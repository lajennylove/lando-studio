<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The "Optimized mode" preference (Windows only): whether new sites keep WordPress core and plugins on Docker volumes
 * instead of the slow Docker Desktop file share. It is the default for new and cloned sites only; a site that already
 * has a .lando.yml keeps its own layout (see LandoYamlGenerator::write).
 */
class OptimizedMode
{
    public const KEY = 'optimized_mode';

    public function __construct(private readonly PlatformDetector $platform) {}

    public function isAvailable(): bool
    {
        return $this->platform->isWindows();
    }

    /** Whether a site created now should use volumes. Always false off Windows. */
    public function enabledForNewSites(): bool
    {
        return $this->isAvailable() && $this->stored();
    }

    /** The saved toggle, regardless of platform. Off until someone turns it on. */
    public function stored(): bool
    {
        $path = storage_path('lando_studio_defaults.json');
        if (! is_file($path)) {
            return false;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && ($data[self::KEY] ?? false) === true;
    }
}
