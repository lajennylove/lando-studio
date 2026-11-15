<?php

namespace App\Services;

use App\Enums\SiteStatus;
use App\Livewire\Settings;
use App\Models\RemoteSite;
use App\Models\RepositoryCredential;
use App\Models\Site;
use App\Support\GitCredentials;
use Illuminate\Support\Str;

class SiteManager
{
    /** Branch checked out in each theme repository after cloning. */
    public const THEME_BRANCH = 'main';

    public function __construct(
        private PlatformDetector $platform,
        private LandoService $lando,
        private LandoYamlGenerator $yamlGenerator,
        private SshService $ssh,
        private DatabasePortAllocator $ports,
        private LandoYamlPortEditor $portEditor,
        private RepositoryAccess $repositoryAccess,
    ) {}

    /**
     * @return array{php_version: string, db_version: string, redis_version: string}
     */
    private function resolvedLandoVersions(): array
    {
        return [
            'php_version' => Settings::getDefault('php_version') ?? config('lando_dev.defaults.php_version'),
            'db_version' => Settings::getDefault('db_version') ?? config('lando_dev.defaults.db_version'),
            'redis_version' => Settings::getDefault('redis_version') ?? config('lando_dev.defaults.redis_version'),
        ];
    }

    /**
     * Before starting a site, ensure its .lando.yml database portforward is not already bound by
     * another process. If it is, reassigns the next free port both in the YAML file and in the DB.
     */
    public function ensurePortAvailable(Site $site): void
    {
        $yamlPath = rtrim($site->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.lando.yml';

        if (! file_exists($yamlPath)) {
            return;
        }

        // Use the YAML parser to get the current explicit port (null = dynamic, skip)
        $parser = app(LandoYamlParser::class);
        $parsed = $parser->parse($yamlPath);
        $currentPort = $parsed['db_port'] ?? null;

        if ($currentPort === null) {
            return;
        }

        if (! $this->ports->isBound($currentPort)) {
            return;
        }

        // Same rule as creating a site: a port no other project claims and nothing is listening on
        $newPort = $this->ports->allocate($site->path);

        $this->portEditor->setDatabasePort($yamlPath, $newPort);

        // Persist the new port in the database record
        $site->db_port = $newPort;
        $site->save();
    }

    /**
     * Moves the database portforward off a port Docker refused ("port is already allocated"), in the YAML and in
     * the site record. Returns the new port, or null when the refused port is not this site's database port
     * (so the failure is left alone). Nothing listens on a stale Docker reservation, so the usual bound-port
     * probe cannot see it: the refused port is skipped explicitly. The caller must run `lando rebuild -y`
     * afterwards, because existing containers keep the port they were created with.
     * Pass $alreadyRefused with the ports Docker refused earlier in the same run so none is chosen again.
     *
     * @param  list<int>  $alreadyRefused
     */
    public function reassignDatabasePortAfterConflict(Site $site, int $refusedPort, array $alreadyRefused = []): ?int
    {
        $yamlPath = rtrim($site->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.lando.yml';

        if (! file_exists($yamlPath)) {
            return null;
        }

        $current = app(LandoYamlParser::class)->parse($yamlPath)['db_port'] ?? null;

        if ($current !== $refusedPort) {
            return null;
        }

        $newPort = $this->ports->allocate($site->path, [...$alreadyRefused, $refusedPort]);

        $this->portEditor->setDatabasePort($yamlPath, $newPort);

        $site->db_port = $newPort;
        $site->save();

        return $newPort;
    }

    /**
     * Lowest database port no other project publishes and nothing is listening on (avoids Docker bind conflicts).
     * Pass the project's path when re-allocating an existing project so its own claims are ignored.
     */
    public function allocateDatabaseForwardPort(?string $projectPath = null): int
    {
        return $this->ports->allocate($projectPath);
    }

    public function createNewSite(string $name, string $adminUser, string $adminPass, string $adminEmail, ?string $path = null, bool $installSage = true): Site
    {
        $slug = Str::slug($name);
        $sitePath = $path ?? ($this->platform->defaultCodePath().DIRECTORY_SEPARATOR.$slug);

        if (! is_dir($sitePath)) {
            mkdir($sitePath, 0755, true);
        }

        // Create the webroot directory before lando start so the container can mount it
        $webrootPath = $sitePath.DIRECTORY_SEPARATOR.'wp';
        if (! is_dir($webrootPath)) {
            mkdir($webrootPath, 0755, true);
        }

        $versions = $this->resolvedLandoVersions();
        $dbPort = $this->allocateDatabaseForwardPort($sitePath);
        $themeSlugForYaml = $installSage ? $slug : '';
        $this->yamlGenerator->write($slug, $sitePath, array_merge($versions, [
            'db_port' => $dbPort,
            'theme_slug' => $themeSlugForYaml,
        ]));

        // Create .nvmrc for Node version consistency
        file_put_contents($sitePath.DIRECTORY_SEPARATOR.'.nvmrc', "22\n");

        $this->copyCaCertIfConfigured($sitePath);

        return Site::create([
            'name' => $slug,
            'path' => $sitePath,
            'url' => "https://{$slug}.lndo.site",
            'admin_url' => "https://{$slug}.lndo.site/wp-admin",
            'status' => SiteStatus::Creating,
            // Lando service versions (container), not the Homebrew PHP that runs the Electron app
            'php_version' => $versions['php_version'],
            'db_version' => $versions['db_version'],
            'redis_version' => $versions['redis_version'],
            'db_port' => $dbPort,
            'admin_username' => $adminUser,
            'admin_email' => $adminEmail,
            'theme_name' => $installSage ? $name : null,
        ]);
    }

    public function getNewSiteSteps(Site $site, ?string $adminPassword = null, bool $installSage = true): array
    {
        $path = $site->path;
        $name = $site->name;
        $adminPass = $adminPassword ?? 'admin';

        $steps = [];

        $platform = app(PlatformDetector::class);
        if ($platform->isWindows()) {
            $steps[] = [
                'label' => 'Cleaning up stale Docker resources',
                'command' => "docker ps -aq --filter \"name={$name}\" | ForEach-Object { docker rm -f \$_ } 2>\$null; "
                    ."docker network ls --filter \"name={$name}_\" -q | ForEach-Object { docker network rm \$_ } 2>\$null; "
                    ."Write-Host 'Docker cleanup complete.'",
                'step' => 1,
            ];
        }

        $steps = array_merge($steps, [
            [
                'label' => 'Starting Lando environment',
                'command' => $this->lando->start($path),
                'step' => count($steps) + 1,
            ],
            [
                'label' => 'Downloading WordPress core',
                'command' => $this->lando->wpCoreDownload($path),
                'step' => count($steps) + 2,
            ],
            [
                'label' => 'Verifying WordPress core files',
                'command' => $this->wpCoreCheckCommand($path),
                'step' => count($steps) + 3,
            ],
            [
                'label' => 'Creating WordPress config',
                'command' => $this->lando->wpConfigCreate($path),
                'step' => count($steps) + 4,
            ],
            [
                'label' => 'Installing WordPress',
                'command' => $this->lando->wpCoreInstall($path, $name, (string) $site->admin_username, $adminPass, (string) $site->admin_email),
                'step' => count($steps) + 5,
            ],
        ]);

        if (! $installSage) {
            return $steps;
        }

        return array_merge($steps, [
            [
                'label' => 'Creating Sage theme',
                'command' => $this->lando->composerCreateProject($path, 'roots/sage', $name),
                'step' => 5,
            ],
            [
                'label' => 'Installing Composer dependencies',
                'command' => $this->lando->composerInstall($path, "wp/wp-content/themes/{$name}"),
                'step' => 6,
                'inactivity_timeout' => 120,
            ],
            [
                'label' => 'Installing Yarn dependencies',
                'command' => $this->lando->yarn($path, "wp/wp-content/themes/{$name}"),
                'step' => 7,
                'inactivity_timeout' => 120,
            ],
            [
                'label' => 'Building theme assets',
                'command' => $this->lando->yarn($path, "wp/wp-content/themes/{$name}", 'build'),
                'step' => 8,
                'inactivity_timeout' => 120,
            ],
            [
                'label' => 'Clearing view cache',
                'command' => $this->lando->clearAcornCache($path),
                'step' => 9,
            ],
            [
                'label' => 'Activating theme',
                'command' => $this->lando->wpThemeActivate($path, $name),
                'step' => 10,
            ],
        ]);
    }

    public function cloneSite(RemoteSite $remoteSite, string $name, ?string $path = null, ?string $themeBranch = null): Site
    {
        $slug = Str::slug($name);
        $sitePath = $path ?? ($this->platform->defaultCodePath().DIRECTORY_SEPARATOR.$slug);

        if (! is_dir($sitePath)) {
            mkdir($sitePath, 0755, true);
        }

        $webrootPath = $sitePath.DIRECTORY_SEPARATOR.'wp';
        if (! is_dir($webrootPath)) {
            mkdir($webrootPath, 0755, true);
        }

        $versions = $this->resolvedLandoVersions();
        $dbPort = $this->allocateDatabaseForwardPort($sitePath);
        $themeSlugForYaml = trim((string) ($remoteSite->theme_name ?? ''));
        $this->yamlGenerator->write($slug, $sitePath, array_merge($versions, [
            'db_port' => $dbPort,
            'theme_slug' => $themeSlugForYaml,
        ]));

        $this->copyCaCertIfConfigured($sitePath);

        return Site::create([
            'name' => $slug,
            'path' => $sitePath,
            'url' => "https://{$slug}.lndo.site",
            'admin_url' => "https://{$slug}.lndo.site/wp-admin",
            'status' => SiteStatus::Creating,
            // Lando service versions (container), not the Homebrew PHP that runs the Electron app
            'php_version' => $versions['php_version'],
            'db_version' => $versions['db_version'],
            'redis_version' => $versions['redis_version'],
            'db_port' => $dbPort,
            'theme_name' => $remoteSite->theme_name,
            'theme_branch' => $themeBranch ?: self::THEME_BRANCH,
            'remote_site_id' => $remoteSite->id,
        ]);
    }

    public function getCloneSiteSteps(Site $site, RemoteSite $remoteSite): array
    {
        $path = $site->path;
        $name = $site->name;
        $dumpFile = rtrim($path, '/\\').DIRECTORY_SEPARATOR.'dumpfile.sql.gz';

        $steps = [];

        // On Windows, a previous failed destroy may leave stale Docker containers /
        // networks with the same site name, causing port-conflict errors on lando start.
        // Remove them proactively before starting.
        $platform = app(PlatformDetector::class);
        if ($platform->isWindows()) {
            $steps[] = [
                'label' => 'Cleaning up stale Docker resources',
                'command' => "docker ps -aq --filter \"name={$name}\" | ForEach-Object { docker rm -f \$_ } 2>\$null; "
                    ."docker network ls --filter \"name={$name}_\" -q | ForEach-Object { docker network rm \$_ } 2>\$null; "
                    ."Write-Host 'Docker cleanup complete.'",
                'step' => 1,
            ];
        }

        $steps[] = [
            'label' => 'Starting Lando environment',
            'command' => $this->lando->start($path),
            'step' => count($steps) + 1,
        ];

        // On Windows, Docker Desktop may silently skip build_as_root curl steps,
        // leaving WP-CLI missing. Explicitly install it into the running container.
        $ensureWpCli = $this->lando->ensureWpCli($path);
        if ($ensureWpCli !== null) {
            $steps[] = [
                'label' => 'Ensuring WP-CLI is available',
                'command' => $ensureWpCli,
                'step' => count($steps) + 1,
            ];
        }

        $steps = array_merge($steps, [
            [
                'label' => 'Downloading WordPress core',
                'command' => $this->lando->wpCoreDownload($path),
                'step' => count($steps) + 1,
            ],
            // Plugins are copied before any WP-CLI step runs. WP-CLI loads every plugin, so one that is still being
            // copied crashed the search-replace and theme steps with a fatal error (the database steps now also skip
            // plugins, this keeps the order safe for anything that does load them).
            [
                'label' => 'Syncing plugins from remote',
                'command' => $this->ssh->buildRsyncPluginsCommand($remoteSite, $path.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'wp-content'.DIRECTORY_SEPARATOR.'plugins'),
                'step' => count($steps) + 2,
            ],
            [
                'label' => 'Dumping remote database',
                'group' => 'Database Setup',
                'hint' => 'Streams SQL over SSH then compresses locally — nothing is written on the remote server. Expect 2–10 minutes depending on database size.',
                'command' => $this->ssh->buildMysqldumpCommand($remoteSite, $dumpFile),
                'step' => count($steps) + 2,
            ],
            [
                'label' => 'Validating dump file',
                'group' => 'Database Setup',
                'command' => $this->validateDumpFileCommand($dumpFile),
                'step' => count($steps) + 3,
            ],
            [
                'label' => 'Importing database',
                'group' => 'Database Setup',
                'command' => $this->lando->dbImport($path, 'dumpfile.sql.gz'),
                'step' => count($steps) + 4,
            ],
            [
                'label' => 'Creating WordPress config',
                'group' => 'Database Setup',
                'command' => $this->cloneWpConfigCommand($path, $site),
                'step' => 6,
            ],
            [
                'label' => 'Cleaning up dump file',
                'group' => 'Database Setup',
                'command' => $this->removeDumpFileCommand($dumpFile),
                'step' => 7,
            ],
            [
                'label' => 'Replacing domain references',
                'group' => 'Database Setup',
                'hint' => 'Search-Replace prod-url for localdev-url. Large databases can take 30 minutes or more.',
                'command' => $this->lando->wpSearchReplaceImported($path, "https://{$name}.lndo.site", $remoteSite->theme_name ?: null),
                'step' => 8,
            ],
            [
                'label' => 'Activating local theme',
                'command' => $remoteSite->theme_name
                    ? $this->activateThemeCommand($path, $remoteSite->theme_name)
                    : ($this->platform->isWindows() ? 'Write-Host "No theme configured, skipping theme activation"' : 'echo "No theme configured, skipping theme activation"'),
                'step' => 9,
            ],
            [
                'label' => 'Configuring image proxy',
                'command' => $this->htaccessCommand($path, $remoteSite->remote_domain),
                'step' => 11,
            ],
        ]);

        $themeSubdir = $remoteSite->theme_name
            ? "wp/wp-content/themes/{$remoteSite->theme_name}"
            : null;
        $cloneTheme = $remoteSite->repo_url && $remoteSite->theme_name;
        $themeBranch = $site->theme_branch ?: self::THEME_BRANCH;

        $stepNum = 12;
        $cloneStep = [
            'label' => 'Cloning theme repository',
            'command' => $remoteSite->repo_url
                ? $this->gitCloneCommand($path, $remoteSite->repo_url, $remoteSite->theme_name, $site)
                : ($this->platform->isWindows() ? 'Write-Host "No repo URL configured, skipping theme clone"' : 'echo "No repo URL configured, skipping theme clone"'),
            'step' => $stepNum++,
        ];
        $savedLogin = $remoteSite->repo_url
            ? RepositoryCredential::where('host', $this->repositoryAccess->hostFor($remoteSite->repo_url))->first()
            : null;
        if ($savedLogin) {
            // The runner writes the saved login to a private file just before this step starts.
            $cloneStep['git_credentials_host'] = $savedLogin->host;
        }
        $steps[] = $cloneStep;

        if ($cloneTheme) {
            $themePath = $path.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'wp-content'.DIRECTORY_SEPARATOR.'themes'.DIRECTORY_SEPARATOR.$remoteSite->theme_name;
            $steps[] = [
                'label' => 'Switching to '.$themeBranch.' branch',
                'command' => $this->gitCheckoutCommand($themePath, $themeBranch),
                'step' => $stepNum++,
            ];
        }

        if ($cloneTheme && $remoteSite->install_composer_dependencies && $themeSubdir) {
            $steps[] = [
                'label' => 'Installing Composer dependencies',
                'command' => $this->lando->composerInstall($path, $themeSubdir),
                'step' => $stepNum++,
                'inactivity_timeout' => 120,
            ];
        }

        if ($cloneTheme && $remoteSite->install_node_dependencies && $themeSubdir) {
            $steps[] = [
                'label' => 'Installing Node dependencies',
                'command' => $this->lando->yarn($path, $themeSubdir),
                'step' => $stepNum++,
                'inactivity_timeout' => 120,
            ];
        }

        $steps[] = [
            'label' => 'Building theme',
            'command' => $remoteSite->theme_name && $themeSubdir
                ? $this->lando->yarn($path, $themeSubdir, 'build')
                : 'echo "No theme configured, skipping build"',
            'step' => $stepNum++,
            'inactivity_timeout' => 120,
        ];

        // Lando health-checks compile Blade views before the Vite build creates
        // manifest.json, caching a "manifest not found" fatal error.  Clear the
        // Acorn view cache so the first real page load recompiles cleanly.
        $steps[] = [
            'label' => 'Clearing view cache',
            'command' => $this->lando->clearAcornCache($path),
            'step' => $stepNum++,
        ];

        return $steps;
    }

    /**
     * Host PHP runs artisan to write wp-config.php (WpConfigGenerator) and detect $table_prefix.
     */
    private function cloneWpConfigCommand(string $path, Site $site): string
    {
        if ($this->platform->isWindows()) {
            $php = '"'.str_replace('"', '""', PHP_BINARY).'"';
            $artisan = '"'.str_replace('"', '""', base_path('artisan')).'"';
            $psPath = str_replace("'", "''", $path);
            $siteId = (string) $site->id;

            return "& {$php} {$artisan} lando-dev:clone-wp-config '{$psPath}' '{$siteId}'";
        }

        return sprintf(
            '%s %s lando-dev:clone-wp-config %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            escapeshellarg($path),
            escapeshellarg((string) $site->id)
        );
    }

    private function validateDumpFileCommand(string $dumpFile): string
    {
        $minBytes = 500;

        if ($this->platform->isWindows()) {
            // The command runs inside PowerShell already (wrapCommandWithLogRedirect).
            // Use single-quoted PS path to avoid any double-quote nesting issues.
            $psPath = str_replace("'", "''", str_replace('/', '\\', $dumpFile));

            return "if ((Get-Item '{$psPath}').Length -lt {$minBytes}) { Write-Error 'Dump file is too small or empty - mysqldump may have failed'; exit 1 } else { Write-Host 'Dump file validated' }";
        }

        $escaped = escapeshellarg($dumpFile);

        return "if [ ! -s {$escaped} ] || [ \$(stat -f%z {$escaped} 2>/dev/null || stat -c%s {$escaped} 2>/dev/null) -lt {$minBytes} ]; then echo 'ERROR: Dump file is too small or empty - mysqldump may have failed. Check SSH credentials and remote database settings.' >&2; exit 1; fi; if ! gzip -t {$escaped}; then echo 'ERROR: dumpfile.sql.gz is not a complete gzip archive (transfer or dump may have been interrupted).' >&2; exit 1; fi; echo 'Dump file validated:' && ls -lh {$escaped}";
    }

    private function removeDumpFileCommand(string $dumpFile): string
    {
        $sqlFile = preg_replace('/\.sql\.gz$/u', '.sql', $dumpFile);

        if ($this->platform->isWindows()) {
            $winGz = str_replace('/', '\\', $dumpFile);
            $winSql = str_replace('/', '\\', $sqlFile);

            return "Remove-Item -Force -ErrorAction SilentlyContinue '{$winGz}', '{$winSql}'; Write-Host 'Dump file removed.'";
        }

        return 'rm -f '.escapeshellarg($dumpFile).' '.escapeshellarg($sqlFile)." && echo 'Removed local dump files (dumpfile.sql / .gz).'";
    }

    private function activateThemeCommand(string $path, string $themeName): string
    {
        // These only write two options; loading the plugins and the theme (half-copied, or without vendor yet) can crash them.
        $skip = LandoService::WP_SKIP_CODE;

        if ($this->platform->isWindows()) {
            $lando = $this->lando->getLandoPath();
            $psLando = '& "'.str_replace('"', '""', $lando).'"';
            $psTheme = str_replace("'", "''", $themeName);

            return "Set-Location \"{$path}\"; {$psLando} wp option update template '{$psTheme}' --path=wp {$skip}; {$psLando} wp option update stylesheet '{$psTheme}' --path=wp {$skip}";
        }

        return 'cd '.escapeshellarg($path)." && {$this->lando->getLandoPath()} wp option update template ".escapeshellarg($themeName)." --path=wp {$skip} && {$this->lando->getLandoPath()} wp option update stylesheet ".escapeshellarg($themeName)." --path=wp {$skip}";
    }

    private function htaccessCommand(string $path, ?string $remoteDomain): string
    {
        $content = $this->ssh->buildHtaccessRewriteContent($remoteDomain);

        if ($this->platform->isWindows()) {
            $htaccessPath = $path.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'.htaccess';
            $psPath = str_replace("'", "''", $htaccessPath);
            $psContent = str_replace("'", "''", $content);

            // PS5's -Encoding UTF8 writes a BOM (\xef\xbb\xbf) which Apache
            // treats as an invalid directive, causing a 500 error.
            // Use .NET's BOM-free UTF8 encoder instead.
            return '$utf8 = New-Object System.Text.UTF8Encoding($false); '
                ."[System.IO.File]::WriteAllText('{$psPath}', '{$psContent}', \$utf8); "
                ."Write-Host '.htaccess configured'";
        }

        return 'echo '.escapeshellarg($content)." > {$path}/wp/.htaccess";
    }

    /**
     * Waits until the WordPress core files are on disk and stable, then lets the next step run. The download
     * writes files without printing anything, and the app moves on after about 30 seconds of silence, so this
     * step prints a line every 5 seconds to stay visible until the files are complete. It fails after 5 minutes.
     */
    private function wpCoreCheckCommand(string $path): string
    {
        if ($this->platform->isWindows()) {
            $root = str_replace("'", "''", $path.'\wp');

            // No exit here: exit ends the PowerShell process, and the step log then never gets its completion marker.
            return "\$d = '{$root}'; \$deadline = (Get-Date).AddSeconds(300); \$last = -1; \$stable = 0; \$done = \$false; "
                .'while ((Get-Date) -lt $deadline) { '
                .'$n = (Get-ChildItem $d -Recurse -File -ErrorAction SilentlyContinue | Measure-Object).Count; '
                ."if ((Test-Path (Join-Path \$d 'wp-includes\\compat.php')) -and (Test-Path (Join-Path \$d 'wp-admin')) -and \$n -eq \$last) { \$stable++ } else { \$stable = 0 }; "
                .'if ($stable -ge 2) { $done = $true; break }; '
                .'$last = $n; Write-Host "Waiting for WordPress core files: $n so far"; Start-Sleep -Seconds 5 }; '
                .'if (-not $done) { throw \'WordPress core files did not finish downloading\' }; '
                .'Write-Host "WordPress core files OK ($n files)"';
        }

        $root = escapeshellarg($path.'/wp');

        return "d={$root}; last=-1; stable=0; for i in \$(seq 1 60); do "
            .'n=$(find "$d" -type f 2>/dev/null | wc -l); '
            .'if [ -f "$d/wp-includes/compat.php" ] && [ -d "$d/wp-admin" ] && [ "$n" = "$last" ]; then stable=$((stable+1)); else stable=0; fi; '
            .'if [ "$stable" -ge 2 ]; then echo "WordPress core files OK ($n files)"; exit 0; fi; '
            .'last=$n; echo "Waiting for WordPress core files: $n so far"; sleep 5; done; '
            .'echo "WordPress core files did not finish downloading"; exit 1';
    }

    private function gitCloneCommand(string $path, string $repoUrl, string $themeName, Site $site): string
    {
        $credentialFile = GitCredentials::fileFor('site-'.$site->id);
        $hasSavedLogin = RepositoryCredential::where('host', $this->repositoryAccess->hostFor($repoUrl))->exists();

        if ($this->platform->isWindows()) {
            $themesDir = $path.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'wp-content'.DIRECTORY_SEPARATOR.'themes';
            $login = $hasSavedLogin
                ? "\$env:GIT_ASKPASS='".str_replace("'", "''", GitCredentials::askpassPath())."'; \$env:LANDO_STUDIO_GIT_CREDENTIALS='".str_replace("'", "''", $credentialFile)."'; "
                : '';
            $cleanup = $hasSavedLogin ? "; Remove-Item -Force '".str_replace("'", "''", $credentialFile)."' -ErrorAction SilentlyContinue" : '';
            $name = str_replace("'", "''", $themeName);

            // A theme folder left from an earlier run is fetched, not cloned over: `git clone` fails on it, and the
            // checkout step that follows would then report "up to date" against a stale origin/main. A non-empty
            // folder that is not a git repository is never touched.
            return "Set-Location \"{$themesDir}\"; {$login}\$env:GIT_TERMINAL_PROMPT='0'; "
                ."if (Test-Path -LiteralPath '{$name}\\.git') { Write-Host 'Theme folder already exists, fetching its latest commits instead of cloning.'; Set-Location '{$name}'; git fetch --progress origin; \$code = \$LASTEXITCODE; if (\$code -eq 0) { Write-Host 'Theme fetched.' } } "
                ."elseif ((Test-Path -LiteralPath '{$name}') -and ((Get-ChildItem -Force -LiteralPath '{$name}' | Measure-Object).Count -gt 0)) { Write-Host '[Lando Studio ERROR] The theme folder already exists and is not a git repository, so it was not touched. Remove it or use another site name.'; \$code = 1 } "
                ."else { git clone --progress {$repoUrl} {$themeName}; \$code = \$LASTEXITCODE; if (\$code -eq 0) { Write-Host 'Theme cloned successfully.' } }"
                ."{$cleanup}; if (\$code -ne 0) { throw 'git clone failed' }";
        }

        $themes = escapeshellarg("{$path}/wp/wp-content/themes");
        $dir = escapeshellarg($themeName);
        // GIT_TERMINAL_PROMPT=0 makes a missing login fail at once. Without it git waits for a username that
        // nothing can answer, and the step stops with no output.
        $env = ($hasSavedLogin ? GitCredentials::shellPrefix($credentialFile) : '').'GIT_TERMINAL_PROMPT=0 ';

        $acquire = "if [ -d {$dir}/.git ]; then echo 'Theme folder already exists, fetching its latest commits instead of cloning.'; "
            ."(cd {$dir} && {$env}git fetch --progress origin) && echo 'Theme fetched.'; "
            ."elif [ -n \"\$(ls -A {$dir} 2>/dev/null)\" ]; then echo '[Lando Studio ERROR] The theme folder already exists and is not a git repository, so it was not touched. Remove it or use another site name.' >&2; false; "
            ."else {$env}git clone --progress {$repoUrl} {$themeName} && echo \"Theme cloned successfully.\"; fi";

        if ($hasSavedLogin) {
            return "cd {$themes} && { {$acquire}; }; status=\$?; rm -f ".escapeshellarg($credentialFile).' && [ "$status" -eq 0 ]';
        }

        return "cd {$themes} && { {$acquire}; }";
    }

    /**
     * Switch the theme to a branch and fast-forward it to origin/<branch>.
     *
     * The clone step has just cloned or fetched, so origin/<branch> is current. Without the fast-forward a theme
     * folder from an earlier run stayed on its old commit and the step still said "Switched to branch".
     * A branch that cannot be fast-forwarded (local commits) fails the step with a [Lando Studio ERROR] line.
     */
    private function gitCheckoutCommand(string $path, string $branch): string
    {
        $error = "[Lando Studio ERROR] Branch {$branch} could not be fast-forwarded to origin/{$branch} (local commits or changes are in the way). Update the theme folder by hand, then retry.";

        if ($this->platform->isWindows()) {
            $quoted = "'".str_replace("'", "''", $branch)."'";
            $remote = "'origin/".str_replace("'", "''", $branch)."'";

            return "Set-Location \"{$path}\"; git checkout {$quoted} 2>\$null; if (\$LASTEXITCODE -ne 0) { Write-Host ('Branch {$branch} not found, staying on the current branch') } "
                ."else { git merge --ff-only {$remote}; if (\$LASTEXITCODE -ne 0) { Write-Host '{$error}' } else { Write-Host ('Switched to branch: {$branch}') } }";
        }

        // A repo without this branch keeps its current branch instead of failing the steps after it.
        $quoted = escapeshellarg($branch);
        $remote = escapeshellarg('origin/'.$branch);

        return "cd {$path} && if git checkout {$quoted} 2>/dev/null; then "
            ."if git merge --ff-only {$remote}; then echo \"On branch: \$(git branch --show-current)\"; else echo ".escapeshellarg($error).'; false; fi; '
            ."else echo \"Branch {$branch} not found, staying on \$(git branch --show-current)\"; fi";
    }

    /**
     * Whether a git branch name is safe to pass to git and the shell: letters, digits, dots, slashes, dashes
     * and underscores, no "..", and no leading or trailing separator. Checked before any clone starts.
     */
    public static function isValidBranchName(string $branch): bool
    {
        return strlen($branch) <= 100
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*[A-Za-z0-9]$|^[A-Za-z0-9]$/', $branch) === 1
            && ! str_contains($branch, '..')
            && ! str_contains($branch, '//');
    }

    public function destroySite(Site $site): string
    {
        $platform = app(PlatformDetector::class);

        if ($platform->isWindows()) {
            $landoDestroy = $this->lando->destroy($site->path);
            $name = $site->name;

            // After lando destroy, force-remove any lingering Docker containers and
            // networks that share the site name. On Windows, lando's stderr warnings
            // (NativeCommandError) can interfere with cleanup, leaving stale port
            // bindings that block the next lando start for a same-named site.
            $dockerCleanup = "docker ps -aq --filter \"name={$name}\" | ForEach-Object { docker rm -f \$_ } 2>\$null; "
                ."docker network ls --filter \"name={$name}_\" -q | ForEach-Object { docker network rm \$_ } 2>\$null";

            return "{$landoDestroy}; {$dockerCleanup}";
        }

        return $this->lando->destroy($site->path);
    }

    public function getDeletePath(Site $site): string
    {
        if (app(PlatformDetector::class)->isWindows()) {
            $path = app(PlatformDetector::class)->normalizePathSeparators($site->path);

            // cmd's rmdir handles Windows file locks more reliably than Remove-Item.
            // A brief pause lets Docker Desktop release handles after lando destroy.
            // lando destroy runs Set-Location into the site, and Windows will not delete a folder that a process is
            // standing in, so the shell first moves to the user folder. The result is checked, since rmdir can fail
            // without stopping the script.
            // The folder goes whether or not any container is left. Up to three passes run: plain rmdir, then rmdir with the
            // \\?\ prefix (deep node_modules paths exceed MAX_PATH), with a pause between passes for handles Docker still holds.
            // The destroy only counts as finished when the folder is gone (see destroyLogHasFatalError).
            // PS5 doesn't support &&; use ; to unconditionally run the checks.
            $literal = "'".str_replace("'", "''", $path)."'";

            return 'Start-Sleep -Seconds 2; Set-Location $env:USERPROFILE; $lsPath = '.$literal.'; '
                .'foreach ($lsPass in 1..3) { if (-not (Test-Path -LiteralPath $lsPath)) { break }; '
                .'cmd /c rmdir /s /q "$lsPath" 2>$null; '
                .'if (Test-Path -LiteralPath $lsPath) { cmd /c rmdir /s /q "\\\\?\\$lsPath" 2>$null }; '
                .'if (Test-Path -LiteralPath $lsPath) { Start-Sleep -Seconds 3 } }; '
                .'if (Test-Path -LiteralPath $lsPath) { Write-Host \'Site folder could not be removed. Close any program using it, then delete it.\' } '
                .'else { Write-Host \'Site folder removed.\' }';
        }

        $quotedPath = escapeshellarg($site->path);

        // On Linux the containers write files as root into the bind mount, and a plain rm cannot remove them.
        // If the folder is still there after rm, retry through pkexec, which shows the desktop password prompt.
        if (app(PlatformDetector::class)->os() === 'linux') {
            return "rm -rf {$quotedPath}; [ ! -e {$quotedPath} ] || pkexec rm -rf {$quotedPath}";
        }

        return "rm -rf {$quotedPath}";
    }

    /**
     * Copy the user-configured CA certificate into the site directory so Lando
     * can mount it into the container and install it in the system CA store.
     */
    private function copyCaCertIfConfigured(string $sitePath): void
    {
        $certPath = Settings::getDefault('ca_cert_path');

        if (! $certPath || ! is_file($certPath)) {
            return;
        }

        $dest = $sitePath.DIRECTORY_SEPARATOR.'lando-ca.crt';
        copy($certPath, $dest);
    }

    /**
     * Steps to sync a fresh database dump from production into an existing local site.
     *
     * Reuses the dump → validate → import → wp-config → cleanup → search-replace chain
     * from getCloneSiteSteps, but skips folder creation, lando start, wp-core-download,
     * git clone, composer/node/build steps — those already exist locally.
     *
     * @param  string|null  $localThemeName  Override for the local theme folder name.
     *                                       Falls back to $site->theme_name.
     */
    public function getSyncSteps(Site $site, RemoteSite $remoteSite, ?string $localThemeName = null): array
    {
        $path = $site->path;
        $localTheme = $localThemeName ?: $site->theme_name ?: null;
        $dumpFile = rtrim($path, '/\\').DIRECTORY_SEPARATOR.'dumpfile.sql.gz';
        $localUrl = "https://{$site->name}.lndo.site";

        $steps = [
            [
                'label' => 'Dumping remote database',
                'hint' => 'Streams SQL over SSH then compresses locally — nothing is written on the remote server. Expect 2–10 minutes depending on database size.',
                'command' => $this->ssh->buildMysqldumpCommand($remoteSite, $dumpFile),
                'step' => 1,
            ],
            [
                'label' => 'Validating dump file',
                'command' => $this->validateDumpFileCommand($dumpFile),
                'step' => 2,
            ],
            [
                'label' => 'Importing database',
                'command' => $this->lando->dbImport($path, 'dumpfile.sql.gz'),
                'step' => 3,
            ],
            [
                'label' => 'Creating WordPress config',
                'command' => $this->cloneWpConfigCommand($path, $site),
                'step' => 4,
            ],
            [
                'label' => 'Cleaning up dump file',
                'command' => $this->removeDumpFileCommand($dumpFile),
                'step' => 5,
            ],
            [
                'label' => 'Replacing domain references',
                'hint' => 'Search-replace prod URL → local URL. Large databases can take 30 minutes or more.',
                'command' => $this->lando->wpSearchReplaceImported($path, $localUrl, $localTheme),
                'step' => 6,
            ],
            [
                'label' => 'Rebuilding Lando environment',
                'hint' => 'Runs `lando rebuild -y` to apply all database and config changes.',
                'command' => $this->lando->rebuild($path),
                'step' => 7,
            ],
        ];

        return $steps;
    }
}
