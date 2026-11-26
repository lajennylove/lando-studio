<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RemoteSite;
use App\Models\Site;
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
    protected $signature = 'lando-dev:astro-proxy {path : Absolute site root} {site-id : Site id} {remote-id? : Remote site preset id, when the site has one}';

    protected $description = 'Route a headless site front end to its Astro server, and enable Apache proxying';

    public function handle(LandoService $lando, SshService $ssh): int
    {
        $path = rtrim((string) $this->argument('path'), '/\\');
        $site = Site::find((int) $this->argument('site-id'));
        $port = (int) ($site?->astro_port ?? 0);

        if ($port < 1024 || $port > 65535) {
            $this->error('[Lando Studio ERROR] The site has no valid Astro port.');

            return self::FAILURE;
        }

        // A site with no preset (an imported site) gets WordPress's standard rules instead of the image proxy.
        $remoteId = $this->argument('remote-id');
        $remote = $remoteId !== null && $remoteId !== '' ? RemoteSite::find((int) $remoteId) : null;
        if ($remoteId !== null && $remoteId !== '' && ! $remote) {
            $this->error('[Lando Studio ERROR] The remote site preset was not found.');

            return self::FAILURE;
        }

        $wordpressBlock = $remote
            ? $ssh->buildHtaccessRewriteContent((string) $remote->remote_domain)
            : "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n";
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
