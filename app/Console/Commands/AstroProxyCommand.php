<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RemoteSite;
use App\Services\LandoService;
use App\Services\SshService;
use App\Support\AstroProxyRules;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Sends a headless site's front-end requests to its Astro server. Writes the WordPress folder's .htaccess (the Astro
 * catch-all first, then the WordPress block), and enables Apache's proxy modules in the appserver. The routes are not
 * listed anywhere: the Astro project decides what each path returns.
 */
class AstroProxyCommand extends Command
{
    protected $signature = 'lando-dev:astro-proxy {path : Absolute site root} {port : Astro port} {remote-id : Remote site preset id}';

    protected $description = 'Route a headless site front end to its Astro server, and enable Apache proxying';

    public function handle(LandoService $lando, SshService $ssh): int
    {
        $path = rtrim((string) $this->argument('path'), '/\\');
        $port = (int) $this->argument('port');

        if ($port < 1024 || $port > 65535) {
            $this->error('[Lando Studio ERROR] The Astro port is not valid.');

            return self::FAILURE;
        }

        $remote = RemoteSite::find((int) $this->argument('remote-id'));
        if (! $remote) {
            $this->error('[Lando Studio ERROR] The remote site preset was not found.');

            return self::FAILURE;
        }

        $wordpressBlock = $ssh->buildHtaccessRewriteContent((string) $remote->remote_domain);
        file_put_contents(
            $path.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'.htaccess',
            AstroProxyRules::htaccess($wordpressBlock, $port)
        );
        $this->line("[Lando Studio] Front-end requests of this site go to the Astro server on port {$port}.");

        // Modules first, then a graceful restart so Apache loads them without dropping connections.
        $scriptName = ".astro-proxy-{$port}.sh";
        $scriptFile = $path.DIRECTORY_SEPARATOR.$scriptName;
        file_put_contents($scriptFile, "a2enmod proxy proxy_http >/dev/null 2>&1\napache2ctl -k graceful && echo 'Apache proxy modules are enabled'\n");

        $command = [$lando->getLandoPath(), 'ssh', '-u', 'root', '-c', "bash /app/{$scriptName}"];
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/\.(cmd|bat)$/i', $command[0])) {
            $command = ['cmd', '/d', '/c', ...$command];
        }

        try {
            $process = new Process($command, $path);
            $process->setTimeout(null);
            $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));
            $exitCode = $process->getExitCode();
        } finally {
            @unlink($scriptFile);
        }

        if ($exitCode !== 0) {
            $this->error('[Lando Studio ERROR] Apache could not load the proxy modules. See the output above.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
