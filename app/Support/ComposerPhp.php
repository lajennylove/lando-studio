<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Works out the PHP version a Composer project needs, from its lock file and manifest, so a site can be set to that
 * version before Composer runs.
 */
final class ComposerPhp
{
    /**
     * Lowest PHP version that every "php" requirement allows, as "major.minor", or null when none is declared.
     *
     * Reads the packages in composer.lock (require and require-dev), its platform section, and the manifest. Each
     * constraint is read by its lowest allowed version. The result is the highest of those lows, so a site on that
     * version satisfies every package. Constraints that cannot be read, such as "*", are ignored.
     */
    public static function requiredVersion(?string $lockPath, string $manifestPath): ?string
    {
        $constraints = [];

        if ($lockPath !== null && is_file($lockPath)) {
            $lock = json_decode((string) file_get_contents($lockPath), true) ?: [];
            foreach (['packages', 'packages-dev'] as $section) {
                foreach ($lock[$section] ?? [] as $package) {
                    if (isset($package['require']['php']) && is_string($package['require']['php'])) {
                        $constraints[] = $package['require']['php'];
                    }
                }
            }
            if (isset($lock['platform']['php']) && is_string($lock['platform']['php'])) {
                $constraints[] = $lock['platform']['php'];
            }
        }

        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
            if (isset($manifest['require']['php']) && is_string($manifest['require']['php'])) {
                $constraints[] = $manifest['require']['php'];
            }
        }

        $lows = array_values(array_filter(array_map([self::class, 'lowestOf'], $constraints)));
        if ($lows === []) {
            return null;
        }

        usort($lows, 'version_compare');

        return end($lows);
    }

    /**
     * Lowest "major.minor" that a constraint allows, or null when it allows none that can be read.
     *
     * "||" alternatives take the lowest of their lows. "<" and "!=" alternatives are skipped, because they set an
     * upper bound or exclude one version, not a lowest one.
     */
    public static function lowestOf(string $constraint): ?string
    {
        $lowest = null;

        foreach (explode('||', $constraint) as $alternative) {
            $alternative = trim($alternative);
            if ($alternative === '' || str_starts_with($alternative, '<') || str_starts_with($alternative, '!')) {
                continue;
            }
            if (! preg_match('/(\d+)\.(\d+)/', $alternative, $m)) {
                continue;
            }

            $version = $m[1].'.'.$m[2];
            if ($lowest === null || version_compare($version, $lowest, '<')) {
                $lowest = $version;
            }
        }

        return $lowest;
    }

    /**
     * Lowest of the available versions that is at least the required one, or null when none is.
     *
     * @param  list<string>  $available
     */
    public static function pick(string $required, array $available): ?string
    {
        $fits = array_values(array_filter($available, fn (string $version): bool => version_compare($version, $required, '>=')));
        usort($fits, 'version_compare');

        return $fits[0] ?? null;
    }
}
