<?php

namespace App\Services;

/**
 * Generates .lando.yml for each WordPress project. Service versions are Lando/container-side only.
 *
 * When a CA certificate is present in the site directory (lando-ca.crt), the generator
 * injects volume mounts and build steps to install it in the container's system CA store.
 * This enables HTTPS for curl, apt, and npm inside Docker on corporate proxy networks.
 *
 * On Windows the generator also puts the files PHP reads on every request (WordPress core, plugins, the theme's
 * vendor and node_modules) on Docker volumes. Docker Desktop shares C:\ with the container file by file, and a page
 * that loads 50 plugins took over a minute; the same files on a volume load in seconds.
 */
class LandoYamlGenerator
{
    /** Volume name => path inside the container, for the folders that are not tied to one theme. */
    private const CORE_VOLUMES = [
        'wp_admin' => '/app/wp/wp-admin',
        'wp_includes' => '/app/wp/wp-includes',
        'wp_plugins' => '/app/wp/wp-content/plugins',
    ];

    /** Folders of the active theme that composer and yarn fill; they are rebuilt, never edited by hand. */
    private const THEME_VOLUMES = ['theme_vendor' => 'vendor', 'theme_node_modules' => 'node_modules'];

    /**
     * @param  array{use_volumes?: bool, volume_theme?: ?string, theme_slug?: string}  $options
     *                                                                                           `use_volumes` forces volumes on or off (default: the "Optimized mode" setting, Windows only). `volume_theme` names the theme whose vendor and
     *                                                                                           node_modules get volumes (default: `theme_slug`, none when it is empty).
     */
    public function generate(string $siteName, string $sitePath, array $options = []): string
    {
        $stub = file_get_contents(resource_path('stubs/lando.yml.stub'));

        $rawThemeSlug = trim((string) ($options['theme_slug'] ?? ''));
        $themeSlug = $rawThemeSlug === '' ? 'twentytwentyfive' : $rawThemeSlug;
        $hasCert = file_exists(rtrim($sitePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'lando-ca.crt');

        $useVolumes = (bool) ($options['use_volumes'] ?? app(OptimizedMode::class)->enabledForNewSites());
        $volumeTheme = array_key_exists('volume_theme', $options)
            ? $options['volume_theme']
            : ($rawThemeSlug === '' ? null : $rawThemeSlug);
        $volumes = $useVolumes ? $this->volumeTargets($volumeTheme) : [];
        $devPlugins = $useVolumes ? self::validDevPlugins((array) ($options['dev_plugins'] ?? [])) : [];

        $replacements = [
            '{{SITE_NAME}}' => $siteName,
            '{{THEME_SLUG}}' => $themeSlug,
            '{{PHP_VERSION}}' => $options['php_version'] ?? config('lando_dev.defaults.php_version'),
            '{{DB_VERSION}}' => $options['db_version'] ?? config('lando_dev.defaults.db_version'),
            '{{REDIS_VERSION}}' => $options['redis_version'] ?? config('lando_dev.defaults.redis_version'),
            '{{DB_PORT}}' => (string) ($options['db_port'] ?? config('lando_dev.defaults.database_forward_port_start')),
            '{{CA_CERT_OVERRIDES}}' => $this->overridesBlock($hasCert, $volumes, $devPlugins),
            '{{CA_CERT_BUILD_STEPS}}' => $hasCert ? $this->certBuildSteps() : '',
            '{{VOLUME_BUILD_STEPS}}' => $volumes === [] ? '' : $this->volumeBuildSteps($volumes),
            '{{TOP_LEVEL_VOLUMES}}' => $volumes === [] ? '' : $this->topLevelVolumes($volumes),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $stub);
    }

    /**
     * Writes the project's .lando.yml. A project that already has a .lando.yml keeps its volume layout: adding
     * volumes to a site that has none would hide its files behind empty volumes, and dropping them would orphan the
     * data in them. Only a new project follows the platform default.
     */
    public function write(string $siteName, string $path, array $options = []): void
    {
        $filePath = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.lando.yml';

        if (is_file($filePath)) {
            $existing = (string) file_get_contents($filePath);

            if (! array_key_exists('use_volumes', $options)) {
                $options['use_volumes'] = self::yamlUsesVolumes($existing);
                $options['volume_theme'] = self::volumeThemeIn($existing);
            }

            if (! array_key_exists('dev_plugins', $options)) {
                $options['dev_plugins'] = self::devPluginsIn($existing);
            }
        }

        file_put_contents($filePath, $this->generate($siteName, $path, $options));
    }

    /**
     * Whether the project at $sitePath keeps WordPress core and plugins on Docker volumes (Windows projects made by
     * this version). Callers that write into wp/ from the host must use the container instead when this is true.
     */
    public static function siteUsesVolumes(string $sitePath): bool
    {
        $file = rtrim($sitePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.lando.yml';

        return is_file($file) && self::yamlUsesVolumes((string) file_get_contents($file));
    }

    /**
     * Container paths of the project's volumes, read from its .lando.yml (empty for a project without volumes).
     *
     * @return list<string>
     */
    public static function volumeTargetsFor(string $sitePath): array
    {
        $file = rtrim($sitePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.lando.yml';
        if (! is_file($file)) {
            return [];
        }

        $yaml = (string) file_get_contents($file);
        if (! self::yamlUsesVolumes($yaml)) {
            return [];
        }

        preg_match_all('/^\s+source: \w+\s*\n\s+target: (\/app\/\S+)\s*$/m', $yaml, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** Host folder (relative to the project) that holds the plugins mounted from disk, one folder per plugin. */
    public const DEV_PLUGINS_DIR = 'wp/wp-content/plugins-dev';

    /** Where plugins live inside the appserver container. */
    public const CONTAINER_PLUGINS_PATH = '/app/wp/wp-content/plugins';

    /** A plugin folder name that is safe in YAML, in a path and in a shell command. */
    public static function isValidPluginName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $name) === 1 && ! str_contains($name, '..');
    }

    /**
     * Plugins the project mounts from disk instead of from the plugins volume.
     *
     * @return list<string>
     */
    public static function devPluginsFor(string $sitePath): array
    {
        $file = rtrim($sitePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.lando.yml';

        return is_file($file) ? self::devPluginsIn((string) file_get_contents($file)) : [];
    }

    /**
     * Rewrites the dev-plugin mounts of an existing .lando.yml in place, leaving the rest of the file alone, and makes
     * the chown build step skip mounted folders (they belong to the host). Needs a project on volumes.
     *
     * @param  list<string>  $names
     */
    /**
     * Reads the PHP version from a site's .lando.yml, or returns null when the file has no php line.
     */
    public static function phpVersionFor(string $sitePath): ?string
    {
        $file = rtrim($sitePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.lando.yml';
        if (! is_file($file)) {
            return null;
        }

        return preg_match('/^  php: "([^"]+)"\r?$/m', (string) file_get_contents($file), $m) ? $m[1] : null;
    }

    /**
     * Sets the PHP version in a site's .lando.yml in place, leaving the rest of the file as it is. The site must be
     * rebuilt afterwards for the containers to use it.
     *
     * @throws \InvalidArgumentException when the version is not "major.minor"
     * @throws \RuntimeException when the file has no php line
     */
    public function setPhpVersion(string $sitePath, string $version): void
    {
        if (! preg_match('/^\d+\.\d+$/', $version)) {
            throw new \InvalidArgumentException('PHP version must look like 8.4.');
        }

        $file = rtrim($sitePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.lando.yml';
        $yaml = is_file($file) ? (string) file_get_contents($file) : '';
        $yaml = preg_replace('/^(  php: )"[^"]+"(\r?)$/m', '$1"'.$version.'"$2', $yaml, 1, $count);
        if ($count !== 1) {
            throw new \RuntimeException('.lando.yml has no php version line.');
        }

        file_put_contents($file, $yaml);
    }

    public function setDevPlugins(string $sitePath, array $names): void
    {
        $file = rtrim($sitePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.lando.yml';
        $yaml = is_file($file) ? (string) file_get_contents($file) : '';

        if (! self::yamlUsesVolumes($yaml)) {
            throw new \RuntimeException('Development plugins need a site in optimized mode.');
        }

        $yaml = (string) preg_replace('#^ {8}- \./'.preg_quote(self::DEV_PLUGINS_DIR, '#').'/[^\n]*\n#m', '', $yaml);
        $yaml = (string) preg_replace_callback(
            '/^( {6}- )chown -R www-data:www-data (\S.*?) \|\| true$/m',
            fn (array $m): string => $m[1].self::chownStep(explode(' ', $m[2])),
            $yaml
        );

        $mounts = '';
        foreach (self::validDevPlugins($names) as $name) {
            $mounts .= '        - ./'.self::DEV_PLUGINS_DIR."/{$name}:".self::CONTAINER_PLUGINS_PATH."/{$name}\n";
        }

        if ($mounts !== '') {
            $yaml = (string) preg_replace('/^(\n)(    build_as_root:)/m', "{$mounts}$1$2", $yaml, 1, $inserted);
            if ($inserted !== 1) {
                throw new \RuntimeException('Could not find the volumes block in .lando.yml.');
            }
        }

        file_put_contents($file, $yaml);
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function validDevPlugins(array $names): array
    {
        return array_values(array_unique(array_filter(
            array_map('strval', $names),
            fn (string $name): bool => self::isValidPluginName($name)
        )));
    }

    /** @return list<string> */
    private static function devPluginsIn(string $yaml): array
    {
        preg_match_all(
            '#^ {8}- \./'.preg_quote(self::DEV_PLUGINS_DIR, '#').'/([A-Za-z0-9._-]+):#m',
            $yaml,
            $matches
        );

        return self::validDevPlugins($matches[1]);
    }

    /**
     * Hands the volumes to www-data without descending into mounts from disk (find -xdev stays on one filesystem).
     *
     * @param  list<string>  $targets
     */
    private static function chownStep(array $targets): string
    {
        return 'find '.implode(' ', $targets).' -xdev -exec chown www-data:www-data {} + || true';
    }

    private static function yamlUsesVolumes(string $yaml): bool
    {
        return str_contains($yaml, 'source: wp_plugins');
    }

    private static function volumeThemeIn(string $yaml): ?string
    {
        return preg_match('#/app/wp/wp-content/themes/([^/\s]+)/vendor#', $yaml, $m) === 1 ? $m[1] : null;
    }

    /**
     * Volume name => container path for this project.
     *
     * @return array<string, string>
     */
    private function volumeTargets(?string $theme): array
    {
        $targets = self::CORE_VOLUMES;

        if ($theme !== null && $theme !== '') {
            foreach (self::THEME_VOLUMES as $name => $folder) {
                $targets[$name] = "/app/wp/wp-content/themes/{$theme}/{$folder}";
            }
        }

        return $targets;
    }

    /**
     * The single `overrides:` block of the appserver: the CA cert mount and the volumes share one key, and YAML
     * allows a key only once.
     *
     * @param  array<string, string>  $volumes
     * @param  list<string>  $devPlugins
     */
    private function overridesBlock(bool $hasCert, array $volumes, array $devPlugins = []): string
    {
        if (! $hasCert && $volumes === []) {
            return '';
        }

        $lines = ["    overrides:\n", "      volumes:\n"];

        if ($hasCert) {
            $lines[] = "        - ./lando-ca.crt:/usr/local/share/ca-certificates/lando-ca.crt:ro\n";
        }

        // The long form is required: Lando turns the short `name:/path` form into a bind mount of a folder.
        foreach ($volumes as $name => $target) {
            $lines[] = "        - type: volume\n          source: {$name}\n          target: {$target}\n";
        }

        foreach ($devPlugins as $name) {
            $lines[] = '        - ./'.self::DEV_PLUGINS_DIR."/{$name}:".self::CONTAINER_PLUGINS_PATH."/{$name}\n";
        }

        return implode('', $lines)."\n";
    }

    /**
     * Build steps that install the mounted CA cert into the system store before
     * any other curl/apt commands run. Appends the cert to the system CA bundle
     * so curl, apt, and node all trust it.
     */
    private function certBuildSteps(): string
    {
        return <<<'YAML'
      - cp /usr/local/share/ca-certificates/lando-ca.crt /usr/local/share/ca-certificates/lando-ca-corp.crt && update-ca-certificates 2>/dev/null || true

YAML;
    }

    /**
     * Build steps for a project on volumes: hand the new (root-owned) volumes to www-data, and size PHP's file caches
     * for a site of this weight. The ini name sorts after Lando's own zzz-lando-my-custom.ini, which would otherwise win.
     * opcache.revalidate_freq stays at 2 s so edits to theme PHP show up at once.
     *
     * @param  array<string, string>  $volumes
     */
    private function volumeBuildSteps(array $volumes): string
    {
        $chown = self::chownStep(array_values($volumes));
        $ini = "printf 'opcache.memory_consumption=256\\nopcache.max_accelerated_files=30000\\nrealpath_cache_size=4096K\\nrealpath_cache_ttl=600\\n' > /usr/local/etc/php/conf.d/zzzz-lando-studio-perf.ini";

        return "      - {$chown}\n      - {$ini}\n";
    }

    /**
     * @param  array<string, string>  $volumes
     */
    private function topLevelVolumes(array $volumes): string
    {
        $lines = ["\nvolumes:\n"];

        foreach (array_keys($volumes) as $name) {
            $lines[] = "  {$name}:\n";
        }

        return implode('', $lines);
    }
}
