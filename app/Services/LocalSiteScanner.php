<?php

namespace App\Services;

use App\Enums\SiteType;
use App\Models\Site;
use App\Support\AstroConfigPort;

/**
 * Scans the configured code path for Lando projects not yet tracked in the database.
 */
class LocalSiteScanner
{
    public function __construct(
        private PlatformDetector $platform,
        private LandoYamlParser $parser,
    ) {}

    /**
     * Return an array of importable sites — each is a parsed YAML result merged with its 'path'.
     * Sites already in the database (matched by path or name) are excluded.
     *
     * @return array<int, array{name: string, path: string, php_version: string, db_type: string, db_version: string, redis_version: string|null, db_port: int|null}>
     */
    public function findUnimported(): array
    {
        $codePath = $this->platform->defaultCodePath();

        if (! is_dir($codePath)) {
            return [];
        }

        $existingPaths = Site::pluck('path')
            ->map(fn ($p) => rtrim((string) $p, DIRECTORY_SEPARATOR))
            ->all();

        $existingNames = Site::pluck('name')->all();

        $results = [];

        foreach (glob($codePath.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) as $dir) {
            $landoYml = $dir.DIRECTORY_SEPARATOR.'.lando.yml';

            if (! file_exists($landoYml)) {
                continue;
            }

            $parsed = $this->parser->parse($landoYml);
            if (! $parsed) {
                continue;
            }

            $normalizedDir = rtrim($dir, DIRECTORY_SEPARATOR);

            if (in_array($normalizedDir, $existingPaths, true) || in_array($parsed['name'], $existingNames, true)) {
                continue;
            }

            $results[] = array_merge($parsed, ['path' => $dir], $this->classify($dir));
        }

        return $results;
    }

    /**
     * What kind of site a project folder holds. An Astro project is a folder under wp/ with an astro.config file (the
     * same check the clone uses). Anything else with WordPress is a vanilla WordPress site. Optimized mode is not
     * stored here: it is read from the project's .lando.yml, where it already lives.
     *
     * @return array{site_type: SiteType, astro_folder: string|null, astro_port: int|null}
     */
    public function classify(string $projectDir): array
    {
        foreach (glob($projectDir.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $folder) {
            foreach (['astro.config.mjs', 'astro.config.js', 'astro.config.ts', 'astro.config.mts', 'astro.config.cjs'] as $config) {
                if (is_file($folder.DIRECTORY_SEPARATOR.$config)) {
                    return [
                        'site_type' => SiteType::Astro,
                        'astro_folder' => basename($folder),
                        'astro_port' => AstroConfigPort::read($folder),
                    ];
                }
            }
        }

        return ['site_type' => SiteType::Vanilla, 'astro_folder' => null, 'astro_port' => null];
    }
}
