<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LandoService;
use App\Support\AstroEnvFile;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Starts the Astro server of a headless site inside its appserver and waits until it answers.
 *
 * A previous server of the same port is stopped first (its pid is kept in /tmp). The server gets the same values as
 * its .env, because Astro reads the Redis and WordPress addresses from the process environment. The command exits 0
 * only when /casino/ answers 200.
 */
class AstroServeCommand extends Command
{
    protected $signature = 'lando-dev:astro-serve {path : Absolute site root} {folder : Astro folder under wp/} {port : Astro port}';

    protected $description = 'Start the Astro server of a headless site in its appserver and check that it answers';

    public function handle(LandoService $lando): int
    {
        $path = rtrim((string) $this->argument('path'), '/\\');
        $folder = (string) $this->argument('folder');
        $port = (int) $this->argument('port');

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $folder) || $folder === '.' || $folder === '..' || $port < 1024 || $port > 65535) {
            $this->error('[Lando Studio ERROR] The Astro folder or port is not valid.');

            return self::FAILURE;
        }

        $command = [$lando->getLandoPath(), 'ssh', '-u', 'root', '-c', $this->serverScript($folder, $port)];
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/\.(cmd|bat)$/i', $command[0])) {
            $command = ['cmd', '/d', '/c', ...$command];
        }

        $process = new Process($command, $path);
        $process->setTimeout(null);
        $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));

        if ($process->getExitCode() !== 0) {
            $this->error('[Lando Studio ERROR] The Astro server did not answer on /casino/. See the output above.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Bash run inside the appserver. Values come from AstroEnvFile, and the folder and port were checked above.
     */
    private function serverScript(string $folder, int $port): string
    {
        $env = [];
        foreach (AstroEnvFile::defaults($port) as $key => $value) {
            $env[] = $key.'='.escapeshellarg((string) $value);
        }
        $envs = implode(' ', $env);
        $pidFile = "/tmp/astro-{$port}.pid";
        $logFile = "/tmp/astro-{$port}.log";

        return "cd /app/wp/{$folder} || exit 1; "
            ."if [ -f {$pidFile} ]; then kill \$(cat {$pidFile}) 2>/dev/null; sleep 2; fi; "
            ."(env {$envs} PORT={$port} HOST=0.0.0.0 nohup node ./dist/server/entry.mjs > {$logFile} 2>&1 & echo \$! > {$pidFile}); "
            .'ok=0; for i in $(seq 1 60); do '
            ."code=\$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:{$port}/casino/); "
            .'if [ "$code" = 200 ]; then ok=1; break; fi; sleep 2; done; '
            .'if [ "$ok" = 1 ]; then echo "Astro server answers on port '.$port.' (/casino/ 200)"; exit 0; fi; '
            ."echo 'Astro server did not answer /casino/. Last log lines:' >&2; tail -20 {$logFile} >&2; exit 1";
    }
}
