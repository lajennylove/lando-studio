<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\LandoContainer;
use Symfony\Component\Process\Process;

/**
 * Plugins of an optimized-mode (volume) site that are mounted from disk so they can be edited in an IDE.
 * The state lives in the site's .lando.yml (see LandoYamlGenerator); this class lists what is installed and builds the
 * PowerShell chains that move a plugin and rebuild the site.
 */
class DevPlugins
{
    private const DOCKER_EXE = 'C:\\Program Files\\Docker\\Docker\\resources\\bin\\docker.exe';

    public function __construct(private readonly LandoService $lando) {}

    public function containerFor(string $siteName): string
    {
        return LandoContainer::appserver($siteName);
    }

    /**
     * Plugin folders currently served by the site's container, sorted. Null when the container cannot be reached
     * (site stopped), so the caller can say so instead of showing an empty list.
     *
     * @return list<string>|null
     */
    public function installed(string $siteName): ?array
    {
        $docker = is_file(self::DOCKER_EXE) ? self::DOCKER_EXE : 'docker';
        $process = new Process([
            $docker, 'exec', $this->containerFor($siteName),
            'find', LandoYamlGenerator::CONTAINER_PLUGINS_PATH, '-mindepth', '1', '-maxdepth', '1', '-type', 'd', '-printf', '%f\n',
        ]);
        $process->setTimeout(20);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $names = array_filter(
            array_map('trim', explode("\n", $process->getOutput())),
            fn (string $name): bool => LandoYamlGenerator::isValidPluginName($name)
        );
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($names);
    }

    /** Copy the plugin out of the volume, mount the folder, rebuild. */
    public function enableCommand(string $siteName, string $path, string $plugin): string
    {
        return $this->step('export', $siteName, $path, $plugin, 'Could not move the plugin to disk. Nothing was changed.')
            .'; '.$this->lando->rebuild($path);
    }

    /** Create or clone the folder, mount it, rebuild. */
    public function createCommand(string $siteName, string $path, string $plugin, string $repo = ''): string
    {
        return $this->step('create', $siteName, $path, $plugin, 'Could not prepare the plugin folder. Nothing was changed.', $repo)
            .'; '.$this->lando->rebuild($path);
    }

    /** Drop the mount, rebuild, then copy the files from disk back into the volume. */
    public function disableCommand(string $siteName, string $path, string $plugin): string
    {
        return $this->step('unmount', $siteName, $path, $plugin, 'Could not update .lando.yml.')
            .'; '.$this->lando->rebuild($path)
            .'; if ($LASTEXITCODE -ne 0) { throw \'[Lando Studio ERROR] The rebuild failed. The plugin folder on disk was not touched.\' }; '
            .$this->artisan('restore', $siteName, $path, $plugin);
    }

    /** Accepts only a plain http(s) or ssh git URL, with nothing a shell could act on. */
    public static function isValidRepoUrl(string $url): bool
    {
        return preg_match('{^(https?://|git@|ssh://)[A-Za-z0-9._~:/@%+=?&#-]+$}', $url) === 1;
    }

    private function step(string $action, string $siteName, string $path, string $plugin, string $failure, string $repo = ''): string
    {
        return $this->artisan($action, $siteName, $path, $plugin, $repo)
            ."; if (\$LASTEXITCODE -ne 0) { throw '[Lando Studio ERROR] ".str_replace("'", "''", $failure)."' }";
    }

    private function artisan(string $action, string $siteName, string $path, string $plugin, string $repo = ''): string
    {
        if (! LandoYamlGenerator::isValidPluginName($plugin)) {
            throw new \InvalidArgumentException('Invalid plugin folder name.');
        }

        $quote = fn (string $value): string => '"'.str_replace('"', '""', $value).'"';

        $command = '& '.$quote(PHP_BINARY).' '.$quote(base_path('artisan')).' lando:dev-plugin '.$action
            .' --path='.$quote($path)
            .' --container='.$this->containerFor($siteName)
            .' --plugin='.$plugin;

        if ($repo !== '' && self::isValidRepoUrl($repo)) {
            $command .= ' --repo='.$quote($repo);
        }

        return $command;
    }
}
