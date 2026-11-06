<?php

namespace App\Services;

class DependencyChecker
{
    public function __construct(
        private PlatformDetector $platform,
    ) {}

    public function checkAll(): array
    {
        $results = [
            'lando' => $this->isLandoInstalled(),
            'docker' => $this->isDockerInstalled(),
        ];

        if ($this->platform->isMacAppleSilicon()) {
            $results['orbstack'] = $this->isOrbStackInstalled();
        }

        return $results;
    }

    public function allRequiredInstalled(): bool
    {
        $windowsRequirements = ! $this->platform->isWindows() || ($this->isWslInstalled() && $this->isPuttyInstalled());

        return $windowsRequirements && $this->isLandoInstalled() && $this->isDockerInstalled() && $this->isLandoSetupComplete();
    }

    /**
     * Whether the WSL package is installed on Windows. Docker Desktop needs WSL2, and Lando uses it too.
     * The package installs its program folder; the stub wsl.exe in System32 exists even when it is not
     * installed, so the program folder is the check. Turning the Windows features on may still need a restart.
     */
    public function isWslInstalled(): bool
    {
        return $this->platform->isWindows() && file_exists('C:\\Program Files\\WSL\\wsl.exe');
    }

    public function isLandoInstalled(): bool
    {
        if ($this->commandExists('lando')) {
            return true;
        }

        // On Windows, the PHP process PATH is frozen at app launch, so newly-installed
        // binaries won't be found by `where` until the app restarts.
        // The official setup-lando.ps1 installs to %USERPROFILE%\.lando\bin\.
        // Check both lando.cmd (the shell wrapper used in practice) and lando.exe.
        if ($this->platform->isWindows()) {
            $bin = $this->platform->homeDir().'\\.lando\\bin\\';

            return file_exists($bin.'lando.cmd') || file_exists($bin.'lando.exe');
        }

        return file_exists($this->unixLandoPath());
    }

    /**
     * Where setup-lando.sh puts the Lando CLI on Linux and macOS. The running app's PATH does not
     * include this folder after the install, so the file is checked directly.
     */
    private function unixLandoPath(): string
    {
        return $this->platform->homeDir().'/.lando/bin/lando';
    }

    /** Full path to lando.cmd (preferred on Windows) or lando.exe fallback. */
    private function windowsLandoExe(): string
    {
        $bin = $this->platform->homeDir().'\\.lando\\bin\\';

        return file_exists($bin.'lando.cmd') ? $bin.'lando.cmd' : $bin.'lando.exe';
    }

    public function isDockerInstalled(): bool
    {
        if ($this->commandExists('docker')) {
            return true;
        }

        if ($this->platform->isWindows()) {
            // Official install location per Docker Desktop Windows docs.
            return file_exists($this->windowsDockerExe());
        }

        return false;
    }

    /** Default docker.exe path for Docker Desktop on Windows. */
    private function windowsDockerExe(): string
    {
        return 'C:\\Program Files\\Docker\\Docker\\resources\\bin\\docker.exe';
    }

    public function isOrbStackInstalled(): bool
    {
        // OrbStack's CLI binary is 'orb', not 'orbstack'.
        // Also check for the macOS app bundle as a fallback.
        return $this->commandExists('orb')
            || (PHP_OS_FAMILY === 'Darwin' && is_dir('/Applications/OrbStack.app'));
    }

    /**
     * Whether PuTTY's plink.exe is available on Windows.
     * plink is required for SSH password-based connections (clone/sync operations).
     */
    public function isPuttyInstalled(): bool
    {
        if ($this->commandExists('plink')) {
            return true;
        }

        return $this->getPlinkPath() !== null;
    }

    /**
     * Full path to plink.exe, or null if not found.
     * Checks the standard PuTTY install locations when not in PATH.
     */
    public function getPlinkPath(): ?string
    {
        $candidates = [
            'C:\\Program Files\\PuTTY\\plink.exe',
            'C:\\Program Files (x86)\\PuTTY\\plink.exe',
            getenv('LOCALAPPDATA').'\\Programs\\PuTTY\\plink.exe',
        ];

        foreach ($candidates as $path) {
            if ($path && file_exists($path)) {
                return $path;
            }
        }

        // Also check PATH via `where`
        $result = @shell_exec('where plink 2>nul');
        if ($result) {
            $first = trim(explode("\n", $result)[0]);
            if ($first && file_exists($first)) {
                return $first;
            }
        }

        return null;
    }

    /**
     * Whether winget (Windows Package Manager) is available.
     * Some Windows builds (LTSC, stripped images, older 10 builds) don't ship it.
     */
    public function isWingetInstalled(): bool
    {
        return $this->platform->isWindows() && $this->commandExists('winget');
    }

    /**
     * Whether `lando setup` has finished. Lando 3 fetches @lando/wordpress when a site first needs it, and
     * `lando setup` does not install it, so the plugin folder alone is not a reliable signal. Setup always
     * creates the Lando CA and the orchestrator (Docker Compose), so those count too.
     */
    public function isLandoSetupComplete(): bool
    {
        $home = $this->platform->homeDir();
        $landoDir = $home.DIRECTORY_SEPARATOR.'.lando';
        $setupOutputs = file_exists($landoDir.DIRECTORY_SEPARATOR.'certs'.DIRECTORY_SEPARATOR.'LandoCA.crt')
            && ! empty(glob($landoDir.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'docker-compose-*'));

        if ($setupOutputs) {
            return true;
        }

        $pluginDir = $this->getLandoPluginsDir();
        if (! $pluginDir) {
            return false;
        }

        return is_dir($pluginDir.DIRECTORY_SEPARATOR.'@lando'.DIRECTORY_SEPARATOR.'wordpress');
    }

    /**
     * Resolve the Lando plugins directory (~/.lando/plugins).
     */
    private function getLandoPluginsDir(): ?string
    {
        $home = $this->platform->homeDir();
        if (! $home) {
            return null;
        }

        $dir = $home.DIRECTORY_SEPARATOR.'.lando'.DIRECTORY_SEPARATOR.'plugins';

        return is_dir($dir) ? $dir : null;
    }

    public function getLandoVersion(): ?string
    {
        // On Windows use the full path in case the child process PATH is frozen.
        $cmd = $this->platform->isWindows()
            ? '"'.$this->windowsLandoExe().'" version'
            : escapeshellarg($this->getLandoPath() ?? 'lando').' version';

        return $this->getCommandOutput($cmd);
    }

    public function getDockerVersion(): ?string
    {
        // On Windows use the full path in case the child process PATH is frozen.
        $cmd = $this->platform->isWindows()
            ? '"'.$this->windowsDockerExe().'" --version'
            : 'docker --version';

        return $this->getCommandOutput($cmd);
    }

    public function getOrbStackVersion(): ?string
    {
        return $this->getCommandOutput('orb version');
    }

    public function getPuttyVersion(): ?string
    {
        $plink = $this->getPlinkPath() ?? 'plink';
        $result = $this->getCommandOutput('"'.$plink.'" -V');

        if (! $result) {
            return null;
        }

        // plink outputs: "plink: Release 0.82" — extract the version number
        if (preg_match('/Release\s+(\S+)/i', $result, $m)) {
            return $m[1];
        }

        return trim(explode("\n", $result)[0]);
    }

    public function getLandoPath(): ?string
    {
        // On Windows, always use the known install path so child processes launched by
        // NativePHP (which may have a frozen PATH) can find the binary without PATH lookup.
        // Prefer lando.cmd (the shell wrapper) over lando.exe.
        if ($this->platform->isWindows()) {
            $exe = $this->windowsLandoExe();

            return file_exists($exe) ? $exe : ($this->getCommandPath('lando') ?? $exe);
        }

        // Fall back to the install location when PATH does not have it (see unixLandoPath()).
        return $this->getCommandPath('lando')
            ?? (file_exists($this->unixLandoPath()) ? $this->unixLandoPath() : null);
    }

    private function commandExists(string $command): bool
    {
        $which = $this->platform->isWindows() ? 'where' : 'which';
        $null = $this->platform->isWindows() ? '2>nul' : '2>/dev/null';
        $result = @shell_exec("{$which} {$command} {$null}");

        return ! empty(trim($result ?? ''));
    }

    private function getCommandOutput(string $command): ?string
    {
        $null = $this->platform->isWindows() ? '2>nul' : '2>/dev/null';
        $result = @shell_exec("{$command} {$null}");

        return $result ? trim($result) : null;
    }

    private function getCommandPath(string $command): ?string
    {
        $which = $this->platform->isWindows() ? 'where' : 'which';
        $null = $this->platform->isWindows() ? '2>nul' : '2>/dev/null';
        $result = @shell_exec("{$which} {$command} {$null}");

        return $result ? trim(explode("\n", $result)[0]) : null;
    }
}
