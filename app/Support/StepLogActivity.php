<?php

declare(strict_types=1);

namespace App\Support;

final class StepLogActivity
{
    /** Suffix of the side file the Windows step wrapper writes its heartbeat to. */
    public const HEARTBEAT_SUFFIX = '.hb';

    /**
     * Unix time of the last sign that a step process is alive: the newer of the step log's modified time and its heartbeat file's.
     *
     * On Windows PowerShell's `*>` redirect holds the step log open, so a helper cannot write to it, and a silent command
     * (composer, wp-cli on a slow file share) left the log untouched until the runner treated the step as finished. The
     * wrapper writes to `<log>.hb` instead. Returns 0 when neither file exists.
     */
    public static function lastActivity(string $logFile): int
    {
        $heartbeatFile = $logFile.self::HEARTBEAT_SUFFIX;
        clearstatcache(true, $logFile);
        clearstatcache(true, $heartbeatFile);

        return max(
            file_exists($logFile) ? (int) filemtime($logFile) : 0,
            file_exists($heartbeatFile) ? (int) filemtime($heartbeatFile) : 0,
        );
    }
}
