<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;

/**
 * Picks the port an Astro server listens on for a new headless site. Each site keeps its own port, so two Astro
 * servers never share one. The range sits below the Lando ports and above the default dev ports.
 */
final class AstroPortAllocator
{
    public const FIRST_PORT = 4321;

    public const LAST_PORT = 4399;

    /**
     * Lowest port in the range that no site has been given yet. Throws when the whole range is in use.
     */
    public static function next(): int
    {
        $taken = Site::whereNotNull('astro_port')->pluck('astro_port')->map(fn ($p) => (int) $p)->all();

        for ($port = self::FIRST_PORT; $port <= self::LAST_PORT; $port++) {
            if (! in_array($port, $taken, true)) {
                return $port;
            }
        }

        throw new \RuntimeException('No free Astro port between '.self::FIRST_PORT.' and '.self::LAST_PORT.'.');
    }
}
