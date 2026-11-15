<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LandoYamlGenerator;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Moves a plugin between a site's plugins volume and a folder on disk, so it can be edited in an IDE.
 *
 * The site page chains these around `lando rebuild`, because a mount changes only when the container is recreated:
 *   enable:  export, rebuild            (copy out, then mount)
 *   create:  create, rebuild            (new or cloned folder, then mount)
 *   disable: unmount, rebuild, restore  (drop the mount, then copy the files back into the volume)
 * Output carries a heartbeat every 20 s so the action log never looks stalled while docker copies files.
 */
class DevPluginCommand extends Command
{
    protected $signature = 'lando:dev-plugin
        {action : export | create | unmount | restore}
        {--path= : Project folder of the site}
        {--container= : appserver container of the site}
        {--plugin= : Plugin folder name}
        {--repo= : Git URL to clone into the plugin folder (create only)}';

    protected $description = 'Mount a plugin from disk instead of from the plugins volume (Windows optimized mode)';

    private const DOCKER_EXE = 'C:\\Program Files\\Docker\\Docker\\resources\\bin\\docker.exe';

    public function handle(LandoYamlGenerator $generator): int
    {
        $path = rtrim((string) $this->option('path'), '/\\');
        $plugin = (string) $this->option('plugin');
        $container = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $this->option('container'));

        if ($path === '' || ! is_dir($path)) {
            return $this->problem('The project folder was not found.');
        }

        if (! LandoYamlGenerator::isValidPluginName($plugin)) {
            return $this->problem('That is not a valid plugin folder name. Use letters, digits, dot, dash and underscore.');
        }

        if (! LandoYamlGenerator::siteUsesVolumes($path)) {
            return $this->problem('This site is not in optimized mode, so its plugins are already on disk.');
        }

        $hostDir = $path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, LandoYamlGenerator::DEV_PLUGINS_DIR).DIRECTORY_SEPARATOR.$plugin;
        $volumeDir = LandoYamlGenerator::CONTAINER_PLUGINS_PATH.'/'.$plugin;
        $current = LandoYamlGenerator::devPluginsFor($path);

        return match ($this->argument('action')) {
            'export' => $this->export($generator, $path, $container, $plugin, $hostDir, $volumeDir, $current),
            'create' => $this->createFolder($generator, $path, $plugin, $hostDir, $current),
            'unmount' => $this->unmount($generator, $path, $plugin, $current),
            'restore' => $this->restore($container, $plugin, $hostDir, $volumeDir),
            default => $this->problem('Unknown action.'),
        };
    }

    /** @param  list<string>  $current */
    private function export(LandoYamlGenerator $generator, string $path, string $container, string $plugin, string $hostDir, string $volumeDir, array $current): int
    {
        if ($container === '') {
            return $this->problem('The site container name is missing.');
        }

        if (is_dir($hostDir) && count(array_diff((array) scandir($hostDir), ['.', '..'])) > 0) {
            return $this->problem("The folder {$hostDir} already has files. Move it away, or use Add to mount that folder as it is.");
        }

        if ($this->docker(['exec', $container, 'test', '-d', $volumeDir]) !== 0) {
            return $this->problem("Plugin '{$plugin}' was not found in the site, or the site is not running.");
        }

        if (! is_dir($hostDir) && ! mkdir($hostDir, 0755, true)) {
            return $this->problem("Could not create {$hostDir}.");
        }

        $this->line("[Lando Studio] Copying {$plugin} to {$hostDir}");
        if ($this->docker(['cp', "{$container}:{$volumeDir}/.", $hostDir]) !== 0) {
            return $this->problem('Docker could not copy the plugin out. Nothing was changed.');
        }

        $generator->setDevPlugins($path, array_merge($current, [$plugin]));
        $this->line("[Lando Studio] {$plugin} will be mounted from disk after the rebuild.");

        return self::SUCCESS;
    }

    /** @param  list<string>  $current */
    private function createFolder(LandoYamlGenerator $generator, string $path, string $plugin, string $hostDir, array $current): int
    {
        $repo = trim((string) $this->option('repo'));

        if ($repo !== '') {
            if (is_dir($hostDir)) {
                return $this->problem("The folder {$hostDir} already exists, so the repository was not cloned.");
            }

            if (! is_dir(dirname($hostDir)) && ! mkdir(dirname($hostDir), 0755, true)) {
                return $this->problem('Could not create the plugins-dev folder.');
            }

            $this->line("[Lando Studio] Cloning {$repo}");
            if ($this->exec(['git', 'clone', '--progress', '--', $repo, $hostDir]) !== 0) {
                return $this->problem('git clone failed. Nothing was changed.');
            }
        } elseif (! is_dir($hostDir) && ! mkdir($hostDir, 0755, true)) {
            return $this->problem("Could not create {$hostDir}.");
        }

        $generator->setDevPlugins($path, array_merge($current, [$plugin]));
        $this->line("[Lando Studio] {$plugin} will be mounted from {$hostDir} after the rebuild.");

        return self::SUCCESS;
    }

    /** @param  list<string>  $current */
    private function unmount(LandoYamlGenerator $generator, string $path, string $plugin, array $current): int
    {
        $generator->setDevPlugins($path, array_values(array_diff($current, [$plugin])));
        $this->line("[Lando Studio] {$plugin} will be served from the plugins volume after the rebuild.");

        return self::SUCCESS;
    }

    /**
     * Runs after the rebuild, when the volume's folder is visible again: copies the files from disk back into it.
     * The folder on disk is kept, it may be a git checkout.
     */
    private function restore(string $container, string $plugin, string $hostDir, string $volumeDir): int
    {
        if (! is_dir($hostDir)) {
            $this->line("[Lando Studio] No folder on disk for {$plugin}; nothing to copy back.");

            return self::SUCCESS;
        }

        $this->line("[Lando Studio] Copying {$plugin} back into the site");
        if ($this->docker(['cp', $hostDir.DIRECTORY_SEPARATOR.'.', "{$container}:{$volumeDir}"]) !== 0) {
            return $this->problem("Docker could not copy the plugin back. Your files are still in {$hostDir}.");
        }

        if ($this->docker(['exec', '-u', 'root', $container, 'chown', '-R', 'www-data:www-data', $volumeDir]) !== 0) {
            return $this->problem('The plugin was copied back, but its owner could not be set.');
        }

        $this->line("[Lando Studio] {$plugin} is back in the site. Your folder {$hostDir} was kept.");

        return self::SUCCESS;
    }

    /** @param  list<string>  $args */
    private function docker(array $args): int
    {
        return $this->exec([is_file(self::DOCKER_EXE) ? self::DOCKER_EXE : 'docker', ...$args]);
    }

    /** @param  list<string>  $command */
    private function exec(array $command): int
    {
        $process = new Process($command);
        $process->setTimeout(null);
        $process->start();

        $lastBeat = time();
        while ($process->isRunning()) {
            if (time() - $lastBeat >= 20) {
                $this->line('[Lando Studio] Still working '.date('H:i:s'));
                $lastBeat = time();
            }

            usleep(250_000);
        }

        $text = trim($process->getOutput().$process->getErrorOutput());
        if ($text !== '' && $process->getExitCode() !== 0) {
            $this->line($text);
        }

        return (int) $process->getExitCode();
    }

    private function problem(string $message): int
    {
        $this->error("[Lando Studio ERROR] {$message}");

        return self::FAILURE;
    }
}
