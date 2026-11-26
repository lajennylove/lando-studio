<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The .env of an Astro project in a headless site. Only non-secret values come from the Studio: the Redis service
 * and the WordPress API inside the appserver, and the Astro port. WP_API_USER, WP_API_PASSWORD and the FUSE values
 * are left blank, so nothing secret is written from production files.
 */
final class AstroEnvFile
{
    /** Values the Astro server reads from its environment, used both in .env and when the server is started. */
    public static function defaults(int $astroPort): array
    {
        return [
            'REDIS_HOST' => 'cache',
            'REDIS_PORT' => '6379',
            'REDIS_PASSWORD' => '',
            'WORDPRESS_API_URL' => 'http://appserver/wp-json/wp/v2',
            'SEOPRESS_API_URL' => 'http://appserver/wp-json/seopress/v1',
            'PUBLIC_API_URL' => 'http://127.0.0.1:'.$astroPort,
            'ASTRO_ENV' => 'dev',
            'WP_API_USER' => '',
            'WP_API_PASSWORD' => '',
        ];
    }

    /** The .env file content: one KEY=value line per default, with a short note on what to fill in by hand. */
    public static function render(int $astroPort): string
    {
        $lines = ['# Written by Lando Studio. Secrets are left blank on purpose.'];

        foreach (self::defaults($astroPort) as $key => $value) {
            $lines[] = "{$key}={$value}";
        }

        $lines[] = '# FUSE_* is not set for this project.';

        return implode("\n", $lines)."\n";
    }
}
