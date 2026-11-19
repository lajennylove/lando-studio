<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\LandoService;
use App\Services\LandoYamlGenerator;
use App\Support\ComposerPhp;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Makes a site run a PHP version that its Composer project accepts, before Composer runs there.
 *
 * Exits 0 when the site's PHP is already new enough, or when the project declares no version. Otherwise it sets php in
 * .lando.yml, records it on the Site row, runs lando rebuild -y in the project, and exits 0 only if the rebuild worked.
 * The site page chains this in front of every composer install and update.
 */
class PhpForComposerCommand extends Command
{
    protected $signature = 'lando:php-check
        {--path= : Project folder of the site}
        {--subdir= : Folder of the Composer project, relative to the project (empty for the project itself)}';

    protected $description = 'Set a site to the PHP version its Composer project needs, and rebuild it when that changes';

    public function handle(LandoService $lando): int
    {
        $path = rtrim((string) $this->option('path'), '/\\');
        $subdir = trim((string) $this->option('subdir'), '/');

        if ($path === '' || ! is_dir($path)) {
            return $this->problem('The project folder was not found.');
        }

        $dir = $subdir === '' ? $path : $path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $subdir);
        $lock = $dir.DIRECTORY_SEPARATOR.'composer.lock';
        $required = ComposerPhp::requiredVersion(is_file($lock) ? $lock : null, $dir.DIRECTORY_SEPARATOR.'composer.json');

        if ($required === null) {
            $this->line('[Lando Studio] This Composer project declares no PHP version. Nothing to change.');

            return self::SUCCESS;
        }

        $current = LandoYamlGenerator::phpVersionFor($path);
        if ($current === null) {
            return $this->problem('.lando.yml has no PHP version, so it cannot be checked.');
        }

        if (version_compare($current, $required, '>=')) {
            $this->line("[Lando Studio] The site runs PHP {$current}, which the project accepts (needs {$required}).");

            return self::SUCCESS;
        }

        $target = ComposerPhp::pick($required, config('lando_dev.defaults.php_versions', []));
        if ($target === null) {
            return $this->problem("The project needs PHP {$required}, and no PHP version in the app is that new.");
        }

        $this->line("[Lando Studio] The project needs PHP {$required}, but the site runs {$current}. Switching the site to {$target}.");
        (new LandoYamlGenerator)->setPhpVersion($path, $target);
        Site::where('path', $path)->update(['php_version' => $target]);

        $this->line('[Lando Studio] Rebuilding the site so the containers use PHP '.$target.'.');
        if ($this->runRebuild($lando->getLandoPath(), $path) !== 0) {
            return $this->problem('lando rebuild -y failed. .lando.yml already says PHP '.$target.'; rebuild the site by hand.');
        }

        $this->line("[Lando Studio] The site now runs PHP {$target}.");

        return self::SUCCESS;
    }

    /** Runs lando rebuild -y in the project and streams its output, so the action log keeps moving. */
    private function runRebuild(string $lando, string $path): int
    {
        $command = [$lando, 'rebuild', '-y'];
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/\.(cmd|bat)$/i', $lando)) {
            $command = ['cmd', '/d', '/c', ...$command];
        }

        $process = new Process($command, $path);
        $process->setTimeout(null);
        $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));

        return (int) $process->getExitCode();
    }

    private function problem(string $message): int
    {
        $this->error("[Lando Studio ERROR] {$message}");

        return self::FAILURE;
    }
}
