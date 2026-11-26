<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\AstroPortAllocator;
use App\Support\AstroConfigPort;
use Illuminate\Console\Command;

/**
 * Sets a headless site's Astro port to the one its cloned Astro project is configured for, so the local port matches
 * production. A project with no port in its config keeps the site's port, or gets one from the allocator. A port used
 * by another site is refused: two Astro servers cannot share a port.
 */
class AstroPortCommand extends Command
{
    protected $signature = 'lando-dev:astro-port {path : Absolute site root} {folder : Astro folder under wp/} {site-id : Site id}';

    protected $description = 'Set a headless site Astro port from the port its Astro project is configured for';

    public function handle(): int
    {
        $path = rtrim((string) $this->argument('path'), '/\\');
        $folder = (string) $this->argument('folder');

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $folder) || $folder === '.' || $folder === '..') {
            $this->error('[Lando Studio ERROR] The Astro folder name is not valid.');

            return self::FAILURE;
        }

        $site = Site::find((int) $this->argument('site-id'));
        if (! $site) {
            $this->error('[Lando Studio ERROR] The site was not found.');

            return self::FAILURE;
        }

        $configured = AstroConfigPort::read($path.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.$folder);

        if ($configured === null) {
            if ($site->astro_port === null) {
                $site->update(['astro_port' => AstroPortAllocator::next()]);
            }
            $this->line("[Lando Studio] The Astro project sets no port. Using {$site->astro_port}.");

            return self::SUCCESS;
        }

        $other = Site::where('astro_port', $configured)->where('id', '!=', $site->id)->first();
        if ($other) {
            $this->error("[Lando Studio ERROR] Port {$configured} is already used by the site '{$other->name}'. Destroy that site or change its port first.");

            return self::FAILURE;
        }

        $site->update(['astro_port' => $configured]);
        $this->line("[Lando Studio] The Astro project listens on port {$configured}, the same as production.");

        return self::SUCCESS;
    }
}
