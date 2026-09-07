<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Process\Process;

class AudioProcessingService
{
    /**
     * Resolve working ffmpeg executable binary path.
     */
    public function getFfmpegBinary(): string
    {
        $configured = (string) config('autopilot.audio.ffmpeg_path', 'ffmpeg');

        if (!empty($configured) && file_exists($configured)) {
            return $configured;
        }

        // Standard Windows fallback path if configured for Linux in .env
        $windowsDefault = 'D:\\ffmpeg\\ffmpeg-9.0.1-essentials_build\\bin\\ffmpeg.exe';
        if (file_exists($windowsDefault)) {
            return $windowsDefault;
        }

        return 'ffmpeg';
    }

    /**
     * Resolve working ffprobe executable binary path.
     */
    public function getFfprobeBinary(): string
    {
        $configured = (string) config('autopilot.audio.ffprobe_path', 'ffprobe');

        if (!empty($configured) && file_exists($configured)) {
            return $configured;
        }

        $windowsDefault = 'D:\\ffmpeg\\ffmpeg-9.0.1-essentials_build\\bin\\ffprobe.exe';
        if (file_exists($windowsDefault)) {
            return $windowsDefault;
        }

        return 'ffprobe';
    }

    /**
     * Convert audio to mono 32kbps 16kHz MP3 format.
     * ffmpeg -i {input} -ac 1 -ar 16000 -b:a 32k -y {output}
     *
     * @throws Exception
     */
    public function convertToMonoMp3(string $inputPath, string $outputPath): void
    {
        $ffmpeg = $this->getFfmpegBinary();

        $outputDir = dirname($outputPath);
        if (!File::isDirectory($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $command = [
            $ffmpeg,
            '-i', $inputPath,
            '-ac', '1',
            '-ar', '16000',
            '-b:a', '32k',
            '-y',
            $outputPath,
        ];

        $process = new Process($command);
        $process->setTimeout(600);
        $process->run();

        if (!$process->isSuccessful()) {
            $error = $process->getErrorOutput() ?: $process->getOutput();
            throw new Exception("FFmpeg audio conversion failed: " . mb_substr($error, 0, 1000));
        }

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new Exception("FFmpeg failed to produce output file at {$outputPath}");
        }
    }

    /**
     * Read duration of audio file in seconds using ffprobe.
     * ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 {input}
     *
     * @throws Exception
     */
    public function getAudioDuration(string $filePath): float
    {
        $ffprobe = $this->getFfprobeBinary();

        $command = [
            $ffprobe,
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $filePath,
        ];

        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            $error = $process->getErrorOutput() ?: $process->getOutput();
            throw new Exception("FFprobe duration probing failed: " . mb_substr($error, 0, 1000));
        }

        $output = trim($process->getOutput());
        $duration = (float) $output;

        return max(0.0, $duration);
    }

    /**
     * Split audio into segments if exceeding max size.
     * ffmpeg -i {input} -f segment -segment_time {segmentTime} -c copy {outputDir}/{prefix}_%03d.mp3
     *
     * @return array<string> List of chunk file paths
     * @throws Exception
     */
    public function splitIntoChunks(
        string $filePath,
        string $outputDir,
        string $prefix,
        int $segmentTime = 1800
    ): array {
        $ffmpeg = $this->getFfmpegBinary();

        if (!File::isDirectory($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $pattern = $outputDir . DIRECTORY_SEPARATOR . "{$prefix}_%03d.mp3";

        $command = [
            $ffmpeg,
            '-i', $filePath,
            '-f', 'segment',
            '-segment_time', (string) $segmentTime,
            '-c', 'copy',
            '-y',
            $pattern,
        ];

        $process = new Process($command);
        $process->setTimeout(600);
        $process->run();

        if (!$process->isSuccessful()) {
            $error = $process->getErrorOutput() ?: $process->getOutput();
            throw new Exception("FFmpeg chunk splitting failed: " . mb_substr($error, 0, 1000));
        }

        // Find all matching generated chunks
        $globPattern = $outputDir . DIRECTORY_SEPARATOR . "{$prefix}_[0-9][0-9][0-9].mp3";
        $chunks = glob($globPattern) ?: [];
        sort($chunks);

        return $chunks;
    }

    /**
     * Generate signed, expiring public URL for downloading processed audio (24 hours).
     */
    public function generateSignedUrl(int $meetingId, ?string $filename = null, int $expiryHours = 24): string
    {
        $params = ['id' => $meetingId];
        if ($filename) {
            $params['file'] = $filename;
        }

        return URL::temporarySignedRoute(
            'meetings.audio.download',
            now()->addHours($expiryHours),
            $params
        );
    }
}
