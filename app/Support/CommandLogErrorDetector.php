<?php

declare(strict_types=1);

namespace App\Support;

final class CommandLogErrorDetector
{
    /**
     * Whether command output indicates failure. Scans the full log: Lando/Docker
     * errors are often thousands of lines above benign trailing banners.
     */
    public static function indicatesFailure(string $content): bool
    {
        $fatalPatterns = [
            'lando command not found',
            'Error response from daemon',
            'Cannot connect to the Docker daemon',
            'EACCES: permission denied',
            'An unrecoverable error occurred while starting up your app',
            'failed to set up container networking',
            'port is already allocated',
            'getaddrinfo for database failed',
            'Error: Database connection error',
            'The site you have requested is not installed',
            'Error: The site you have requested is not installed',
            'Dump file is too small or empty',
            'mysqldump may have failed',
            '[Lando Studio ERROR]',
            'ERROR ==>',
        ];

        foreach ($fatalPatterns as $pattern) {
            if (stripos($content, $pattern) !== false) {
                return true;
            }
        }

        $tail = self::tailLines($content, 80);
        if (stripos($tail, 'is not running') !== false) {
            return true;
        }

        if (preg_match_all('/exited with code (\d+)/i', $content, $matches)) {
            return (int) end($matches[1]) !== 0;
        }

        return false;
    }

    /**
     * The host port Docker refused to publish ("... 127.0.0.1:32790 failed: port is already allocated"), or null
     * when the log has no such error. A stale reservation inside Docker shows up here but not as a listener.
     */
    public static function conflictingPort(string $content): ?int
    {
        if (preg_match('/:(\d{2,5})\s+failed:\s+port is already allocated/i', $content, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    private static function tailLines(string $content, int $lines): string
    {
        $allLines = explode("\n", trim($content));
        $slice = array_slice($allLines, -$lines);

        return implode("\n", $slice);
    }
}
