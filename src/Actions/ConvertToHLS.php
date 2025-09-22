<?php

declare(strict_types=1);

namespace AchyutN\LaravelHLS\Actions;

use AchyutN\LaravelHLS\Jobs\UpdateConversionProgress;
use Exception;
use FFMpeg\Format\Video\X264;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use FFMpeg\Exception\RuntimeException;
use Illuminate\Support\Facades\Log;

use function Laravel\Prompts\progress;

final class ConvertToHLS
{
    /**
     * Convert a video file to HLS format with AES-128 encryption.
     *
     * @param  string  $inputPath  The path to the input video file.
     * @param  string  $outputFolder  The folder where the HLS output will be stored.
     * @param  Model  $model  The model instance (optional, used for progress tracking).
     *
     * @throws Exception If the conversion fails.
     */
    public static function convertToHLS(string $inputPath, string $outputFolder, Model $model): void
    {
        $startTime = microtime(true);

        $resolutions = config('hls.resolutions');
        $kiloBitRates = config('hls.bitrates');

        $videoDisk = $model->getVideoDisk();
        $hlsDisk = $model->getHlsDisk();
        $secretsDisk = $model->getSecretsDisk();
        $hlsOutputPath = $model->getHLSOutputPath();
        $secretsOutputPath = $model->getHLSSecretsOutputPath();

        try {
            $media = FFMpeg::fromDisk($videoDisk)->open($inputPath);
            $fileBitrate = $media->getFormat()->get('bit_rate') / 1000;
            $streamVideo = $media->getVideoStream()->getDimensions();
            $fileResolution = "{$streamVideo->getWidth()}x{$streamVideo->getHeight()}";
        } catch (Exception $e) {
            FFMpeg::cleanupTemporaryFiles();
            throw new RuntimeException('Failed to open or probe video file.', $e->getCode(), $e);
        }

        $formats = [];

        $lowerResolutions = array_filter($resolutions, fn($resolution): bool => self::extractResolution($resolution)['height'] <= self::extractResolution($fileResolution)['height']);

        foreach ($lowerResolutions as $resolution => $res) {
            $bitrate = $kiloBitRates[$resolution] ?? 1000;
            $formats[] = (new X264)
                ->setKiloBitrate($bitrate)
                ->setAudioKiloBitrate(128)
                ->setAdditionalParameters([
                    '-vf',
                    'scale=' . self::renameResolution($res),
                    '-tune',
                    'zerolatency',
                    '-preset',
                    'veryfast',
                    '-crf',
                    '22',
                ]);
        }

        if ($formats === []) {
            $formats[] = (new X264)
                ->setKiloBitrate($fileBitrate)
                ->setAudioKiloBitrate(128)
                ->setAdditionalParameters([
                    '-vf',
                    'scale=' . self::renameResolution($fileResolution),
                    '-tune',
                    'zerolatency',
                    '-preset',
                    'veryfast',
                    '-crf',
                    '22',
                ]);
        }

        try {
            // Calcular pesos proporcionales para cada fase
            $totalResolutions = count($lowerResolutions ?: [1]); // Mínimo 1 resolución

            // Calcular estimación dinámica de archivos basada en duración del video
            $videoDurationSeconds = (float)$media->getFormat()->get('duration');
            $segmentDuration = 10; // Segundos por segmento HLS (configurable)

            // Estimar número de segmentos .ts por resolución
            $estimatedSegmentsPerResolution = ceil($videoDurationSeconds / $segmentDuration);

            // Archivos por resolución:
            // - N segmentos .ts (basado en duración)
            // - 1 playlist .m3u8 por resolución
            // - Archivos temporales durante procesamiento (estimado 20% adicional)
            $filesPerResolution = $estimatedSegmentsPerResolution + 1; // .ts + .m3u8
            $tempFilesOverhead = max(1, ceil($filesPerResolution * 0.2)); // 20% overhead para archivos temp

            $totalEstimatedFiles = $totalResolutions * ($filesPerResolution + $tempFilesOverhead);

            // Peso FFmpeg: Procesamiento de video (más costoso computacionalmente)
            $ffmpegWeight = $totalResolutions * 10; // 10 unidades por resolución

            // Peso Upload: Transferencia de archivos (menos costoso pero depende de red)
            $uploadWeight = $totalEstimatedFiles * 2; // 2 unidades por archivo

            $totalWeight = $ffmpegWeight + $uploadWeight;
            $ffmpegPercentage = ($ffmpegWeight / $totalWeight) * 100;
            $uploadPercentage = ($uploadWeight / $totalWeight) * 100;

            Log::info("Progress weights calculated - FFmpeg: {$ffmpegPercentage}%, Upload: {$uploadPercentage}%");

            // Configurar callback para tracking de upload
            Media::setGlobalProgressCallback(function ($uploadedFiles, $totalFiles, $uploadProgress) use ($model, $ffmpegPercentage): void {
                // Escalar upload progress desde el punto donde terminó FFmpeg
                $uploadScaledProgress = $ffmpegPercentage + ($uploadProgress * (100 - $ffmpegPercentage) / 100);

                Log::info("Upload Progress Debug (Cumulative Tracking)", [
                    'uploaded_files' => $uploadedFiles,
                    'total_files' => $totalFiles,
                    'upload_progress' => $uploadProgress,
                    'ffmpeg_percentage' => $ffmpegPercentage,
                    'upload_scaled_progress' => $uploadScaledProgress,
                    'final_progress' => (int) $uploadScaledProgress
                ]);

                UpdateConversionProgress::dispatch($model, (int) $uploadScaledProgress);
            });

            $export = FFMpeg::fromDisk($videoDisk)
                ->open($inputPath)
                ->exportForHLS()
                ->toDisk($hlsDisk);

            foreach ($formats as $format) {
                $export->addFormat($format);
            }

            Log::info('Started conversion for resolutions: ' . implode(', ', array_keys($lowerResolutions)));

            $progress = progress(
                label: 'Converting video to HLS format...',
                steps: 100,
                hint: 'Estimated time remaining: Calculating...',
            );
            $progress->start();

            $export->onProgress(function ($percentage) use ($model, $progress, $startTime, $ffmpegPercentage): void {
                $estimatedTime = self::estimateTime(
                    startTime: $startTime,
                    progress: $percentage
                );
                $progress->hint($estimatedTime);
                $progress->advance();
                // Escalar FFmpeg al porcentaje calculado proporcionalmente
                $scaledFFmpegProgress = (int)($percentage * $ffmpegPercentage / 100);

                Log::info("FFmpeg Progress Debug", [
                    'ffmpeg_percentage_raw' => $percentage,
                    'ffmpeg_max_percentage' => $ffmpegPercentage,
                    'scaled_ffmpeg_progress' => $scaledFFmpegProgress
                ]);

                UpdateConversionProgress::dispatch($model, $scaledFFmpegProgress);
            });

            if (config('hls.enable_encryption')) {
                $export
                    ->withRotatingEncryptionKey(function ($filename, $contents) use ($outputFolder, $secretsDisk, $secretsOutputPath): void {
                        Storage::disk($secretsDisk)->put("{$outputFolder}/{$secretsOutputPath}/{$filename}", $contents);
                    });
            }

            $export->save("{$outputFolder}/{$hlsOutputPath}/playlist.m3u8");

            Log::info("FFmpeg completed, starting upload phase", [
                'ffmpeg_max_percentage' => $ffmpegPercentage,
                'upload_should_start_at' => $ffmpegPercentage
            ]);

            FFMpeg::cleanupTemporaryFiles();

            // Limpiar el callback global
            Media::clearGlobalProgressCallback();

            $progress->finish();
        } catch (Exception $e) {
            // Limpiar el callback global en caso de error
            Media::clearGlobalProgressCallback();
            FFMpeg::cleanupTemporaryFiles();
            throw new RuntimeException("Failed to prepare formats for HLS conversion: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Calculate the estimated time remaining.
     */
    private static function estimateTime(float $startTime, float $progress): string
    {
        $elapsed = microtime(true) - $startTime;
        $remainingSteps = 100 - $progress;
        $etaSeconds = ($progress > 0) ? ($elapsed / $progress) * $remainingSteps : 0;

        return 'Estimated time remaining: ' . gmdate('H:i:s', (int) $etaSeconds);
    }

    /**
     * Extract width and height from a resolution string.
     *
     * @param  string  $resolution  The resolution string in the format '{width}x{height}'.
     * @return array An associative array with 'width' and 'height' keys.
     *
     * @throws Exception If the resolution string is not in the correct format.
     */
    private static function extractResolution(string $resolution): array
    {
        if (preg_match('/^(\d+)x(\d+)$/', $resolution, $matches)) {
            return [
                'width' => (int) $matches[1],
                'height' => (int) $matches[2],
            ];
        }

        throw new InvalidArgumentException("Invalid resolution format: {$resolution}. Expected format is '{width}x{height}'.");
    }

    /**
     * Rename resolution from '{width}x{height}' to 'width:height'.
     *
     * @param  string  $resolution  The resolution string in the format '{width}x{height}'.
     * @return string The resolution string in the format 'width:height'.
     *
     * @throws Exception
     */
    private static function renameResolution(string $resolution): string
    {
        $parts = explode('x', $resolution);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException("Invalid resolution format: {$resolution}. Expected format is '{width}x{height}'.");
        }

        return "{$parts[0]}:{$parts[1]}";
    }
}
