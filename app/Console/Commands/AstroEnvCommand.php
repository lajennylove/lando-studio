<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Support\AstroEnvFile;
use Illuminate\Console\Command;

/**
 * Writes the .env of a site's Astro project (wp/<folder>/.env) from the non-secret defaults. The Astro port is read from
 * the site, which the clone sets from the project's own config. Run by the clone steps.
 */
class AstroEnvCommand extends Command
{
    protected $signature = 'lando-dev:astro-env {path : Absolute site root} {folder : Astro folder under wp/} {site-id : Site id}';

    protected $description = 'Write the .env of a headless site Astro project (non-secret values only)';

    public function handle(): int
    {
        $path = rtrim((string) $this->argument('path'), '/\\');
        $folder = (string) $this->argument('folder');

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $folder) || $folder === '.' || $folder === '..') {
            $this->error('[Lando Studio ERROR] The Astro folder name is not valid.');

            return self::FAILURE;
        }

        $site = Site::find((int) $this->argument('site-id'));
        if (! $site || ! $site->astro_port) {
            $this->error('[Lando Studio ERROR] The site has no Astro port.');

            return self::FAILURE;
        }

        $dir = $path.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.$folder;
        if (! is_dir($dir)) {
            $this->error("[Lando Studio ERROR] The Astro project folder was not found: {$dir}");

            return self::FAILURE;
        }

        file_put_contents($dir.DIRECTORY_SEPARATOR.'.env', AstroEnvFile::render((int) $site->astro_port));
        $this->line("[Lando Studio] Wrote {$folder}/.env with the Astro port {$site->astro_port}.");

        return self::SUCCESS;
    }
}
