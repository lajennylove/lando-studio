<?php

namespace App\Services;

class LandoService
{
    /**
     * WP-CLI flags for the database steps of a clone. WP-CLI loads every plugin and the theme on each call, so a
     * plugin or theme that is half-copied or missing its vendor folder crashes `wp option get` and `wp
     * search-replace` with a fatal error. Those commands only read and write the database.
     */
    public const WP_SKIP_CODE = '--skip-plugins --skip-themes';

    private ?string $landoPath = null;

    public function __construct(
        private PlatformDetector $platform,
        private DependencyChecker $checker,
    ) {}

    public function getLandoPath(): string
    {
        if ($this->landoPath === null) {
            $this->landoPath = $this->checker->getLandoPath() ?? 'lando';
        }

        return $this->landoPath;
    }

    public function start(string $path): string
    {
        return $this->buildCommand($path, 'start');
    }

    public function stop(string $path): string
    {
        return $this->buildCommand($path, 'stop');
    }

    public function restart(string $path): string
    {
        return $this->buildCommand($path, 'restart');
    }

    public function rebuild(string $path): string
    {
        return $this->buildCommand($path, 'rebuild -y');
    }

    public function destroy(string $path): string
    {
        return $this->buildCommand($path, 'destroy -y');
    }

    public function wpConfigCreate(string $path): string
    {
        $dbName = config('lando_dev.defaults.db_name');
        $dbUser = config('lando_dev.defaults.db_user');
        $dbPass = config('lando_dev.defaults.db_password');
        $dbHost = config('lando_dev.defaults.db_host');

        return $this->buildCommand($path,
            "wp config create --dbname={$dbName} --dbuser={$dbUser} --dbpass={$dbPass} --dbhost={$dbHost} --path=wp"
        );
    }

    public function wpCoreInstall(string $path, string $siteName, string $adminUser, string $adminPass, string $adminEmail): string
    {
        $url = 'https://'.$siteName.'.lndo.site';
        $title = ucwords(str_replace('-', ' ', $siteName));

        $urlArg = escapeshellarg($url);
        $titleArg = escapeshellarg($title);
        $userArg = escapeshellarg($adminUser);
        $passArg = escapeshellarg($adminPass);
        $emailArg = escapeshellarg($adminEmail);

        return $this->buildCommand($path,
            "wp core install --url={$urlArg} --title={$titleArg} --admin_user={$userArg} --admin_password={$passArg} --admin_email={$emailArg} --path=wp"
        );
    }

    public function wpCoreDownload(string $path): string
    {
        return $this->buildCommand($path, 'wp core download --path=./wp');
    }

    /**
     * On Windows, build_as_root curl can fail silently in Docker Desktop,
     * leaving WP-CLI missing. This command installs it inside the running
     * container if not already present — a no-op on Linux/macOS where
     * build_as_root runs reliably.
     */
    public function ensureWpCli(string $path): ?string
    {
        if (! $this->platform->isWindows()) {
            return null;
        }

        $install = 'which wp > /dev/null 2>&1 || (curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp && chmod +x /usr/local/bin/wp && echo WP-CLI installed)';
        $escapedPath = $this->escapePath($path);
        $lando = $this->getLandoPath();
        $psLando = '& "'.str_replace('"', '""', $lando).'"';

        return "Set-Location {$escapedPath}; {$psLando} exec appserver -- bash -c \"{$install}\"";
    }

    /**
     * Same as: lando wp search-replace 'old' 'new' --all-tables --path=wp
     */
    public function wpSearchReplace(string $path, string $from, string $to): string
    {
        $fromArg = escapeshellarg($from);
        $toArg = escapeshellarg($to);

        $cmd = $this->buildCommand($path, "wp search-replace {$fromArg} {$toArg} --all-tables --path=wp ".self::WP_SKIP_CODE);

        // wp search-replace on a large DB runs silently for 2-10+ minutes.
        // Wrap with a heartbeat so the 30s log-idle timer does not fire prematurely.
        return $this->platform->isWindows()
            ? $cmd
            : $this->withHeartbeat('[Lando Studio] Domain replacement in progress', $cmd);
    }

    public function wpThemeActivate(string $path, string $themeName): string
    {
        return $this->buildCommand($path, "wp theme activate {$themeName} --path=wp");
    }

    public function wpThemeList(string $path): string
    {
        return $this->buildCommand($path, 'wp theme list --format=json --path=wp');
    }

    public function wpUserUpdatePassword(string $path, string $username, string $password): string
    {
        $escapedPass = str_replace("'", "'\\''", $password);

        return $this->buildCommand($path, "wp user update {$username} --user_pass='{$escapedPass}' --path=wp");
    }

    /**
     * Import a gzipped SQL dump into the Lando database service.
     *
     * lando db-import on .gz can pass raw gzip to mysql (ASCII '\\0' / --binary-mode). Decompress
     * to a temporary .sql in the app root, import that, then remove it (avoids docker stdin quirks).
     */
    public function dbImport(string $path, string $dumpFile): string
    {
        if ($this->platform->isWindows()) {
            // Run gzip → mysql entirely inside the container via `lando ssh`.
            // This avoids a 2+ GB intermediate .sql file and keeps a bash heartbeat
            // alive so WithCommandExecution's 30 s log-inactivity timer doesn't fire
            // prematurely on large databases.
            //
            // The bash script is written to a .sh file first (mounted as /app/ inside
            // the container) to avoid PowerShell → Windows → lando → bash quoting hell.
            $dbName = config('lando_dev.defaults.db_name');
            $dbUser = config('lando_dev.defaults.db_user');
            $dbPass = config('lando_dev.defaults.db_password');
            $dbHost = config('lando_dev.defaults.db_host');

            $lando = $this->getLandoPath();
            $psLando = '& "'.str_replace('"', '""', $lando).'"';
            $escapedPath = $this->escapePath($path);

            $bashScript = implode("\n", [
                '#!/bin/bash',
                'echo "[Lando Studio] Starting database import..."',
                '(while sleep 20; do echo "[Lando Studio] Database import in progress $(date +%H:%M:%S)"; done &)',
                'HB=$!',
                "gzip -dc /app/{$dumpFile} | mysql --skip-ssl -u {$dbUser} -p{$dbPass} -h {$dbHost} {$dbName}",
                'EC=$?',
                'kill $HB 2>/dev/null; wait $HB 2>/dev/null',
                'if [ $EC -eq 0 ]; then',
                '  echo "[Lando Studio] Database import complete"',
                'else',
                '  echo "[Lando Studio ERROR] Database import failed (exit code $EC)"',
                '  exit $EC',
                'fi',
            ]);

            // Escape for PowerShell single-quoted here-string content (double any single quotes).
            $psScript = str_replace("'", "''", $bashScript);

            return "Set-Location {$escapedPath}"
                // Write the script with LF line endings and NO BOM so bash can execute it.
                ."; [System.IO.File]::WriteAllText((Join-Path (Get-Location) '.lando-import.sh'), '{$psScript}', (New-Object System.Text.UTF8Encoding(\$false)))"
                ."; {$psLando} ssh -c 'bash /app/.lando-import.sh'"
                ."; Remove-Item -Force '.lando-import.sh' -ErrorAction SilentlyContinue";
        }

        $escapedPath = $this->escapePath($path);
        $dumpArg = escapeshellarg($dumpFile);
        $lando = $this->getLandoPath();

        return "cd {$escapedPath} && gzip -dc {$dumpArg} > dumpfile.sql && {$lando} db-import dumpfile.sql && rm -f dumpfile.sql";
    }

    /**
     * @param  bool  $themeOnVolumes  The theme's vendor and node_modules are Docker volumes, so the theme folder already
     *                                exists with those two mount points and create-project would refuse it. The project is
     *                                created in /tmp without installing, then copied in; the install step fills vendor.
     */
    public function composerCreateProject(string $path, string $package, string $name, bool $themeOnVolumes = false): string
    {
        if ($themeOnVolumes) {
            $tmp = '/tmp/'.preg_replace('/[^A-Za-z0-9_.-]/', '', $name);
            $theme = '/app/wp/wp-content/themes/'.preg_replace('/[^A-Za-z0-9_.-]/', '', $name);

            return $this->buildCommand($path, "ssh -c \"bash -c 'rm -rf {$tmp} && COMPOSER_PROCESS_TIMEOUT=0 composer create-project {$package} {$tmp} --no-install && mkdir -p {$theme} && cp -a {$tmp}/. {$theme}/ && rm -rf {$tmp}'\"");
        }

        // Use ssh -c with bash -c so COMPOSER_PROCESS_TIMEOUT env var is set by the shell.
        // lando ssh -c execs directly (no shell), so we need bash to interpret the env var.
        return $this->buildCommand($path, "ssh -c \"bash -c 'COMPOSER_PROCESS_TIMEOUT=0 composer create-project {$package} {$name} --working-dir=/app/wp/wp-content/themes'\"");
    }

    public function composerInstall(string $path, string $subdir): string
    {
        // Disable Composer's internal process timeout so large package extractions
        // (e.g. google/apiclient-services) don't fail mid-unzip.
        // lando ssh -c execs directly (no shell), so we need bash to interpret the env var.
        $cmd = $this->afterPhpCheck($path, $subdir, $this->buildCommand($path, "ssh -c \"bash -c 'COMPOSER_PROCESS_TIMEOUT=0 composer install --working-dir=/app/{$subdir}'\""));

        // Composer is silent while it unpacks, for many minutes on a slow file share. Windows steps get a heartbeat
        // from PlatformDetector::wrapCommandWithLogRedirect; elsewhere this keeps the log alive.
        return $this->platform->isWindows()
            ? $cmd
            : $this->withHeartbeat('[Lando Studio] Composer install in progress', $cmd);
    }

    /**
     * Composer in a mounted subdirectory (theme), with a log heartbeat so the dashboard
     * idle timer does not treat silent Composer output as an early completion.
     */
    public function composerInstallWithHeartbeat(string $path, string $subdir): string
    {
        return $this->composerWorkingDirWithHeartbeat($path, $subdir, 'install', '[Lando Studio] Composer install in progress');
    }

    public function composerUpdateWithHeartbeat(string $path, string $subdir): string
    {
        return $this->composerWorkingDirWithHeartbeat($path, $subdir, 'update', '[Lando Studio] Composer update in progress');
    }

    private function composerWorkingDirWithHeartbeat(string $path, string $subdir, string $verb, string $heartbeatMessage): string
    {
        $escapedPath = $this->escapePath($path);
        $lando = $this->getLandoPath();
        $cmd = $this->afterPhpCheck($path, $subdir, "cd {$escapedPath} && {$lando} composer {$verb} --working-dir=/app/{$subdir}");

        return $this->platform->isWindows()
            ? $cmd
            : $this->withHeartbeat($heartbeatMessage, $cmd);
    }

    /**
     * Run a Lando tooling command defined in .lando.yml (e.g. theme-install, dev).
     */
    public function tooling(string $path, string $toolAndArgs): string
    {
        return $this->buildCommand($path, $toolAndArgs);
    }

    public function toolingWithHeartbeat(string $path, string $toolAndArgs, string $heartbeatMessage): string
    {
        $cmd = $this->buildCommand($path, $toolAndArgs);

        return $this->platform->isWindows()
            ? $cmd
            : $this->withHeartbeat($heartbeatMessage, $cmd);
    }

    public function yarn(string $path, string $subdir, ?string $command = null): string
    {
        if ($command) {
            $yarnCmd = "yarn {$command}";
        } else {
            // --ignore-scripts prevents postinstall hooks from running during
            // install, which can fail when binaries (e.g. vite) are not yet
            // in $PATH. The separate "yarn build" step handles compilation.
            $yarnCmd = 'yarn install --ignore-scripts';
        }

        // The command runs inside the Lando container via `lando ssh`, so the inner
        // `cd /app/... && yarn` is bash regardless of the host OS.
        return $this->buildCommand($path, "ssh -u root -c \"cd /app/{$subdir} && {$yarnCmd}\"");
    }

    public function info(string $path): string
    {
        return $this->buildCommand($path, 'info --format=json');
    }

    /**
     * Remove Acorn's compiled view cache inside the container.
     *
     * Lando health-check requests can compile Blade views before the Vite
     * build produces manifest.json, caching a fatal "manifest not found"
     * error.  Clearing the view cache after the build forces recompilation.
     */
    public function clearAcornCache(string $path): string
    {
        return $this->buildCommand($path, 'ssh -c "rm -rf /app/wp/wp-content/cache/acorn/framework/views/*"');
    }

    /**
     * Whether every container of each Lando app is running, from one `lando list` call.
     *
     * @return array<string, bool>|null app name => running, or null when `lando list` failed or returned nothing
     *                                  usable (Docker stopped, Lando missing). Callers must not read null as "all stopped".
     */
    public function appRunningStates(): ?array
    {
        $output = @shell_exec($this->quietCommand('list --format=json'));
        $list = $output ? json_decode($output, true) : null;
        if (! is_array($list)) {
            return null;
        }

        $states = [];
        foreach ($list as $container) {
            if (($container['kind'] ?? '') !== 'app' || ! isset($container['app'])) {
                continue;
            }

            // A site is running only when all its services are (a stopped database with a live appserver is not).
            $states[$container['app']] = ($states[$container['app']] ?? true) && (bool) ($container['running'] ?? false);
        }

        return $states;
    }

    /**
     * Lando command line for shell_exec with errors discarded. shell_exec runs through cmd.exe on Windows, where
     * `2>/dev/null` makes cmd abort with "The system cannot find the path specified" before lando starts.
     */
    private function quietCommand(string $landoCmd): string
    {
        $lando = $this->getLandoPath();

        return $this->platform->isWindows()
            ? "\"{$lando}\" {$landoCmd} 2>nul"
            : escapeshellarg($lando)." {$landoCmd} 2>/dev/null";
    }

    public function isRunning(string $path, ?string $appName = null): bool
    {
        // lando list --format=json queries Docker directly and returns a reliable boolean
        // `running` field per container — unlike lando info whose `healthy` field can be
        // the string "unknown" (truthy in PHP) even when the containers are stopped.
        $states = $this->appRunningStates();

        if ($states !== null) {
            // Derive app name from the path's basename as fallback.
            // An app missing from the list is not initialised / not running.
            return $states[$appName ?? basename($path)] ?? false;
        }

        // Fallback: lando info (project-directory-based check). cmd.exe on Windows has no Set-Location, so it
        // gets its own cd /d form.
        $cmd = $this->platform->isWindows()
            ? 'cd /d "'.$path.'" && '.$this->quietCommand('info --format=json')
            : $this->buildCommand($path, 'info --format=json').' 2>/dev/null';
        $output = @shell_exec($cmd);

        if (! $output) {
            return false;
        }

        $info = json_decode($output, true);
        if (! is_array($info)) {
            return false;
        }

        foreach ($info as $service) {
            if (($service['service'] ?? '') === 'appserver') {
                // `healthy` can be true (boolean), "healthy" (string), or "unknown" / false.
                // Treat only strict true or the string "healthy" as actually running.
                $healthy = $service['healthy'] ?? false;
                $isHealthy = $healthy === true || (is_string($healthy) && strtolower($healthy) === 'healthy');

                return $isHealthy || str_contains(strtolower($service['state'] ?? ''), 'running');
            }
        }

        return false;
    }

    private function buildCommand(string $path, string $landoCmd): string
    {
        $escapedPath = $this->escapePath($path);

        if ($this->platform->isWindows()) {
            // PowerShell 5.1 (Windows 10 default) does not support &&.
            // Use Set-Location + semicolon, and call the exe with & to handle spaces in path.
            $lando = $this->getLandoPath();
            $psLando = '& "'.str_replace('"', '""', $lando).'"';

            return "Set-Location {$escapedPath}; {$psLando} {$landoCmd}";
        }

        return $this->wrapInShell("cd {$escapedPath} && {$this->getLandoPath()} {$landoCmd}");
    }

    private function wrapInShell(string $command): string
    {
        return $command;
    }

    /**
     * Runs lando:php-check before a Composer command, so Composer never runs on a PHP version the project rejects.
     *
     * When the check or Composer fails, the step log ends with a [Lando Studio ERROR] line, so the failure is not
     * mistaken for a finished step. On Unix the heartbeat loop is stopped before that line, since the heartbeat is
     * only killed on success.
     */
    private function afterPhpCheck(string $path, string $subdir, string $composer): string
    {
        $php = $this->escapePath(PHP_BINARY);
        $artisan = $this->escapePath(base_path('artisan'));
        $check = ($this->platform->isWindows() ? '& '.$php : $php).' '.$artisan
            .' lando:php-check --path='.$this->escapePath($path).' --subdir='.$this->escapePath($subdir);
        $message = '[Lando Studio ERROR] Composer did not finish. The PHP check or Composer failed; see the output above.';

        if ($this->platform->isWindows()) {
            return "{$check} && {$composer}; if (-not \$?) { Write-Output '{$message}' }";
        }

        return "{$check} && {$composer} || { echo ".escapeshellarg($message).'; kill $LANDO_STUDIO_HB 2>/dev/null; false; }';
    }

    private function escapePath(string $path): string
    {
        if ($this->platform->isWindows()) {
            return "\"{$path}\"";
        }

        return "'".str_replace("'", "'\\''", $path)."'";
    }

    /**
     * Wrap a long-running silent command with a background heartbeat (Unix only).
     *
     * Several lando commands (wp search-replace, heavy composer/yarn installs) run silently
     * for minutes. WithCommandExecution marks a step complete after 30s of log inactivity;
     * the heartbeat keeps the log alive so the step is not advanced prematurely.
     *
     * Uses the same bash pattern as SshService::buildMysqldumpCommand:
     *   - ';' (not '&&') before the while loop so there is no operator-precedence race
     *   - no extra '( )' around the while loop so $! is the direct shell PID (kill works reliably)
     */
    /**
     * Replace the remote WordPress URL with the local one, and optionally fix theme
     * folder name references if the production theme name differs from the local one.
     *
     * Reads the actual siteurl and template from the imported database rather than
     * trusting remote_sites values — those can diverge (www. prefix, theme slug hyphenation,
     * etc.) which would cause 0 replacements or a blank-page theme mismatch.
     *
     * If the production `template` option differs from $localThemeName, a second
     * search-replace runs to rewrite every serialized path (options, post meta, etc.)
     * that still contains the old theme slug.
     */
    public function wpSearchReplaceImported(string $path, string $localUrl, ?string $localThemeName = null): string
    {
        $lando = $this->getLandoPath();
        $escapedPath = $this->escapePath($path);
        $toArg = escapeshellarg($localUrl);

        if ($this->platform->isWindows()) {
            $psLando = '& "'.str_replace('"', '""', $lando).'"';
            $psLocalUrl = str_replace("'", "''", $localUrl);

            // PowerShell 5.1 compatible: get siteurl, search-replace, optionally fix theme slug. An empty siteurl or
            // template means the lookup failed, and `search-replace "" url` would fail or touch nothing, so the step
            // logs a [Lando Studio ERROR] line instead (the runner treats it as a failed step).
            $skip = self::WP_SKIP_CODE;
            $cmd = "Set-Location {$escapedPath}"
                ."; \$REMOTE_URL = ({$psLando} wp option get siteurl --path=wp {$skip} 2>\$null | Select-Object -Last 1)"
                .'; if ($REMOTE_URL) { $REMOTE_URL = $REMOTE_URL.Trim() }'
                .'; if (-not $REMOTE_URL) {'
                ."   Write-Host '[Lando Studio ERROR] Could not read the remote site URL (siteurl) from the imported database, so the domain was not replaced.'"
                .' } else {'
                ."   Write-Host \"Replacing: \$REMOTE_URL -> {$psLocalUrl}\";"
                ."   {$psLando} wp search-replace \"\$REMOTE_URL\" '{$psLocalUrl}' --all-tables --path=wp {$skip}";

            if ($localThemeName) {
                $psThemeName = str_replace("'", "''", $localThemeName);
                $cmd .= ";   \$PROD_THEME = ({$psLando} wp option get template --path=wp {$skip} 2>\$null | Select-Object -Last 1)"
                    .';   if ($PROD_THEME) { $PROD_THEME = $PROD_THEME.Trim() }'
                    .';   if (-not $PROD_THEME) {'
                    ."     Write-Host '[Lando Studio ERROR] Could not read the production theme name (template) from the imported database.'"
                    ."   } elseif (\$PROD_THEME -ne '{$psThemeName}') {"
                    ."     Write-Host \"Theme slug mismatch: \$PROD_THEME -> {$psThemeName}\";"
                    ."     {$psLando} wp search-replace \"\$PROD_THEME\" '{$psThemeName}' --all-tables --path=wp {$skip}"
                    .'   } else {'
                    .'     Write-Host "Theme slug matches: $PROD_THEME"'
                    .'   }';
            }

            return $cmd.' }';
        }

        $skip = self::WP_SKIP_CODE;
        $missingUrl = "echo '[Lando Studio ERROR] Could not read the remote site URL (siteurl) from the imported database, so the domain was not replaced.' >&2; false";
        $cmd = "cd {$escapedPath}"
            ." && REMOTE_URL=\$({$lando} wp option get siteurl --path=wp {$skip} 2>/dev/null | tail -1 | tr -d '\\r\\n')"
            ." && { [ -n \"\$REMOTE_URL\" ] || { {$missingUrl}; }; }"
            ." && echo \"Replacing: \$REMOTE_URL -> {$localUrl}\""
            ." && {$lando} wp search-replace \"\$REMOTE_URL\" {$toArg} --all-tables --path=wp {$skip}";

        if ($localThemeName) {
            $localThemeArg = escapeshellarg($localThemeName);
            $missingTheme = "echo '[Lando Studio ERROR] Could not read the production theme name (template) from the imported database.' >&2; false";
            $cmd .= " && PROD_THEME=\$({$lando} wp option get template --path=wp {$skip} 2>/dev/null | tail -1 | tr -d '\\r\\n')"
                ." && { [ -n \"\$PROD_THEME\" ] || { {$missingTheme}; }; }"
                ." && if [ \"\$PROD_THEME\" != {$localThemeArg} ]; then"
                ."   echo \"Theme slug mismatch: \$PROD_THEME -> {$localThemeName}\";"
                ."   {$lando} wp search-replace \"\$PROD_THEME\" {$localThemeArg} --all-tables --path=wp {$skip};"
                .' else'
                .'   echo "Theme slug matches: $PROD_THEME";'
                .' fi';
        }

        return $this->withHeartbeat('[Lando Studio] Domain replacement in progress', $cmd);
    }

    private function withHeartbeat(string $message, string $command): string
    {
        $msg = escapeshellarg($message);
        $trapCleanup = escapeshellarg('kill $LANDO_STUDIO_HB 2>/dev/null; wait $LANDO_STUDIO_HB 2>/dev/null');

        return 'while sleep 20; do echo '.$msg.'; done & LANDO_STUDIO_HB=$!'
            .' && trap '.$trapCleanup.' EXIT'
            .' && ('.$command.')'
            .' && kill $LANDO_STUDIO_HB 2>/dev/null && wait $LANDO_STUDIO_HB 2>/dev/null && trap - EXIT';
    }
}
