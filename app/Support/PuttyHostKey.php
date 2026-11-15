<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Process;

/**
 * Answers PuTTY's "host key is not cached" prompt without a console.
 *
 * plink and pscp 0.8x ask for the answer on the console, not on stdin, so `echo y | plink` hangs. In batch mode they
 * print the server's fingerprint and stop. We read that fingerprint and pass it back with `-hostkey`, which accepts that
 * one key for that one connection (first-seen trust, the same as StrictHostKeyChecking=no on macOS and Linux).
 */
final class PuttyHostKey
{
    /**
     * Fingerprint (`SHA256:…`) of the server's key when PuTTY has not cached it; null when it is cached or unreachable.
     *
     * Runs `plink -batch` without credentials, so nothing is authenticated and nothing is stored in the registry.
     */
    public static function fingerprintFor(string $plinkPath, string $target): ?string
    {
        $result = Process::timeout(30)->run([$plinkPath, '-batch', '-ssh', '-T', $target, 'exit']);

        return self::parseFingerprint($result->output().$result->errorOutput());
    }

    /**
     * Extracts `SHA256:…` from plink's "host key is not cached" message.
     */
    public static function parseFingerprint(string $plinkOutput): ?string
    {
        if (stripos($plinkOutput, 'host key is not cached') === false && stripos($plinkOutput, 'key fingerprint is') === false) {
            return null;
        }

        return preg_match('/\b(SHA256:[A-Za-z0-9+\/]{43})\b/', $plinkOutput, $m) === 1 ? $m[1] : null;
    }

    /**
     * The `-hostkey` arguments to add to a plink/pscp command: empty when the key is already cached.
     *
     * @return list<string>
     */
    public static function argumentsFor(string $plinkPath, string $target): array
    {
        $fingerprint = self::fingerprintFor($plinkPath, $target);

        return $fingerprint === null ? [] : ['-hostkey', $fingerprint];
    }
}
