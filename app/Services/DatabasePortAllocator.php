<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;

/**
 * Chooses the host port each project publishes its database on, so no two Lando projects collide.
 *
 * Claims come from every `.lando.yml` under the code path, every tracked site's YAML, and each tracked
 * site's stored db_port. A claim from a tracked site always outranks an untracked folder; between two
 * untracked folders the alphabetically earlier path keeps the port, so the result is deterministic.
 */
final class DatabasePortAllocator
{
    /** @var list<array{port: int, path: string, tracked: bool}>|null */
    private ?array $claims = null;

    /** @var array<int, true> Ports handed out during this instance's lifetime (e.g. one import batch). */
    private array $reserved = [];

    public function __construct(
        private PlatformDetector $platform,
        private LandoYamlParser $parser,
    ) {}

    /**
     * Next free port at or above the configured start, skipping every claimed, reserved or bound port.
     * Pass $projectPath when re-allocating an existing project so its own claims are ignored.
     */
    public function allocate(?string $projectPath = null): int
    {
        $start = (int) config('lando_dev.defaults.database_forward_port_start', 32_787);
        $self = $projectPath === null ? null : $this->normalize($projectPath);

        for ($port = $start; $port <= 65_535; $port++) {
            if ($this->isClaimedByOthers($port, $self) || $this->isBound($port)) {
                continue;
            }

            return $this->reserve($port);
        }

        throw new \RuntimeException('No free database port available above '.$start.'.');
    }

    /**
     * Keep $currentPort for $projectPath when no higher-ranked project claims it; otherwise allocate a new one.
     * Returns the port the project should use. The caller writes the YAML when the result differs from $currentPort.
     */
    public function assign(string $projectPath, ?int $currentPort): int
    {
        $self = $this->normalize($projectPath);

        if ($currentPort !== null && ! isset($this->reserved[$currentPort]) && ! $this->isOutrankedBy($currentPort, $self)) {
            return $this->reserve($currentPort);
        }

        return $this->allocate($projectPath);
    }

    /**
     * Whether something on localhost is already listening on $port.
     */
    public function isBound(int $port): bool
    {
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);

        if ($sock !== false) {
            fclose($sock);

            return true;
        }

        return false;
    }

    private function reserve(int $port): int
    {
        $this->reserved[$port] = true;

        return $port;
    }

    /**
     * Any other project publishes this port, or it was handed out earlier in this instance.
     */
    private function isClaimedByOthers(int $port, ?string $self): bool
    {
        if (isset($this->reserved[$port])) {
            return true;
        }

        foreach ($this->claims() as $claim) {
            if ($claim['port'] === $port && $claim['path'] !== $self) {
                return true;
            }
        }

        return false;
    }

    /**
     * Another project outranks $self for this port: a tracked site always does, and an untracked folder
     * does when its path sorts earlier.
     */
    private function isOutrankedBy(int $port, string $self): bool
    {
        foreach ($this->claims() as $claim) {
            if ($claim['port'] !== $port || $claim['path'] === $self) {
                continue;
            }

            if ($claim['tracked'] || $claim['path'] < $self) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{port: int, path: string, tracked: bool}>
     */
    private function claims(): array
    {
        if ($this->claims !== null) {
            return $this->claims;
        }

        $trackedSites = Site::query()->get(['path', 'db_port']);
        $trackedPaths = $trackedSites->map(fn (Site $site) => $this->normalize((string) $site->path))->all();

        $claims = [];

        foreach ($this->landoFiles($trackedPaths) as $path => $file) {
            foreach ($this->parser->publishedPorts($file) as $port) {
                $claims[] = ['port' => $port, 'path' => $path, 'tracked' => in_array($path, $trackedPaths, true)];
            }
        }

        foreach ($trackedSites as $site) {
            if ($site->db_port !== null) {
                $claims[] = [
                    'port' => (int) $site->db_port,
                    'path' => $this->normalize((string) $site->path),
                    'tracked' => true,
                ];
            }
        }

        return $this->claims = $claims;
    }

    /**
     * Every `.lando.yml` under the code path plus each tracked site's own YAML, keyed by normalized project path.
     *
     * @param  list<string>  $trackedPaths
     * @return array<string, string>
     */
    private function landoFiles(array $trackedPaths): array
    {
        $files = [];

        $codePath = $this->platform->defaultCodePath();

        if (is_dir($codePath)) {
            foreach (glob($codePath.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $dir) {
                $file = $dir.DIRECTORY_SEPARATOR.'.lando.yml';

                if (file_exists($file)) {
                    $files[$this->normalize($dir)] = $file;
                }
            }
        }

        foreach ($trackedPaths as $path) {
            $file = $path.DIRECTORY_SEPARATOR.'.lando.yml';

            if (file_exists($file)) {
                $files[$path] = $file;
            }
        }

        return $files;
    }

    private function normalize(string $path): string
    {
        return rtrim($path, DIRECTORY_SEPARATOR.'/');
    }
}
