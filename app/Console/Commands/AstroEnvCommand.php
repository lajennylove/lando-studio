<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AstroEnvFile;
use Illuminate\Console\Command;

/**
 * Writes the .env of a site's Astro project (wp/<folder>/.env) from the non-secret defaults. Run by the clone steps.
 */
class AstroEnvCommand extends Command
{
    protected $signature = 'lando-dev:astro-env {path : Absolute site root} {folder : Astro folder under wp/} {port : Astro port}';

    protected $description = 'Write the .env of a headless site Astro project (non-secret values only)';

    public function handle(): int
    {
        $path = rtrim((string) $this->argument('path'), '/\\');
        $folder = (string) $this->argument('folder');
        $port = (int) $this->argument('port');

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $folder) || $folder === '.' || $folder === '..') {
            $this->error('[Lando Studio ERROR] The Astro folder name is not valid.');

            return self::FAILURE;
        }

        $dir = $path.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.$folder;
        if (! is_dir($dir)) {
            $this->error("[Lando Studio ERROR] The Astro project folder was not found: {$dir}");

            return self::FAILURE;
        }

        file_put_contents($dir.DIRECTORY_SEPARATOR.'.env', AstroEnvFile::render($port));
        $this->line("[Lando Studio] Wrote {$folder}/.env with the Astro port {$port}.");

        return self::SUCCESS;
    }
}
