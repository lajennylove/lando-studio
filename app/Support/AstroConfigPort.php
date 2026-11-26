<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reads the port an Astro project is configured to listen on (astro.config.*). Production runs each Astro project on
 * this port, and the project's own .htaccess proxies to it, so the local site uses the same number.
 */
final class AstroConfigPort
{
    /** @var list<string> */
    private const CONFIG_FILES = ['astro.config.mjs', 'astro.config.js', 'astro.config.ts', 'astro.config.mts', 'astro.config.cjs'];

    /** The configured port, or null when the project does not set one or has no config file. */
    public static function read(string $projectDir): ?int
    {
        foreach (self::CONFIG_FILES as $file) {
            $path = $projectDir.DIRECTORY_SEPARATOR.$file;
            if (! is_file($path)) {
                continue;
            }

            $content = (string) file_get_contents($path);
            if (preg_match('/\bport\s*:\s*(\d{2,5})\b/', $content, $m) === 1) {
                $port = (int) $m[1];

                return $port >= 1024 && $port <= 65535 ? $port : null;
            }

            return null;
        }

        return null;
    }
}
