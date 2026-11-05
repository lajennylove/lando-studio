<?php

namespace App\Services;

class DependencyInstaller
{
    public function __construct(
        private PlatformDetector $platform,
    ) {}

    public function getInstallCommand(string $dependency): string
    {
        return match ($dependency) {
            'lando' => $this->installLando(),
            'docker' => $this->installDocker(),
            'orbstack' => $this->installOrbStack(),
            'putty' => $this->installPutty(),
            'wsl' => $this->installWsl(),
            default => throw new \InvalidArgumentException("Unknown dependency: {$dependency}"),
        };
    }

    public function installLando(): string
    {
        if ($this->platform->isWindows()) {
            // $env:NONINTERACTIVE=1 suppresses all prompts (equivalent to -Yes flag).
            // Lando's published setup-lando.ps1 (v3.7.5) has a broken ARM64 check ("elseif ($arch = == ...")
            // and then runs the arm64 CLI, which does not start on Windows ARM64. Patch those two spots
            // in memory before running it, so the x64 CLI is installed (it runs under emulation).
            // Remove this patch once Lando fixes the script and the arm64 CLI.
            return implode('; ', [
                '$env:NONINTERACTIVE=1',
                '$s = (Invoke-RestMethod -Uri \'https://get.lando.dev/setup-lando.ps1\' -UseBasicParsing)',
                '$s = $s -replace \'elseif \(\$arch = == "ARM64"\)\', \'elseif ($arch -eq "ARM64")\'',
                '$s = $s -replace \'(?m)^\$SYSTEM_ARCHITECTURE = Get-SystemArchitecture\', \'$$SYSTEM_ARCHITECTURE = "x64"\'',
                'Invoke-Expression $s',
            ]);
        }

        // The setup-lando.sh script handles macOS and all major Linux distros.
        return '/bin/bash -c "$(curl -fsSL https://get.lando.dev/setup-lando.sh)"';
    }

    public function installDocker(): string
    {
        return match ($this->platform->os()) {
            // Bootstrap Homebrew first if absent, then install Docker Desktop cask.
            'macos' => $this->withBrewBootstrap('brew install --cask docker'),

            // Official documented method from https://docs.docker.com/desktop/setup/install/windows-install/
            // PowerShell: detect arch, download installer, run silently.
            // --quiet          suppresses UI
            // --accept-license pre-accepts Docker Subscription Service Agreement
            // --backend=wsl-2  default backend; avoids Hyper-V prompt
            'windows' => implode('; ', [
                '$arch = if ($env:PROCESSOR_ARCHITECTURE -eq "ARM64") { "arm64" } else { "amd64" }',
                '$url = "https://desktop.docker.com/win/main/$arch/Docker%20Desktop%20Installer.exe"',
                '$f = "$env:TEMP\DockerDesktopInstaller.exe"',
                'Invoke-WebRequest -Uri $url -OutFile $f -UseBasicParsing',
                'Start-Process $f -Wait -ArgumentList @("install", "--quiet", "--accept-license", "--backend=wsl-2")',
            ]),

            // get.docker.com auto-detects the distro (apt, yum, dnf, zypper, etc.).
            default => 'curl -fsSL https://get.docker.com | sh',
        };
    }

    public function installOrbStack(): string
    {
        // OrbStack is macOS-only; bootstrap Homebrew if needed.
        return $this->withBrewBootstrap('brew install --cask orbstack');
    }

    public function installPutty(): string
    {
        // Prefer winget when available (built into Windows 10 21H1+ and Windows 11).
        // Fall back to a direct MSI download for machines without winget (e.g. LTSC,
        // stripped images, or older Windows 10 builds).
        $checker = app(DependencyChecker::class);

        if ($checker->isWingetInstalled()) {
            return implode('; ', [
                'winget install --id PuTTY.PuTTY --source winget --silent --accept-package-agreements --accept-source-agreements',
                'Write-Output "[Lando Studio] PuTTY installed via winget. plink.exe is in: $env:ProgramFiles\PuTTY\"',
            ]);
        }

        // Direct MSI download fallback — official PuTTY release page.
        // msiexec /i ... /qn runs a silent install to the default Program Files path.
        return implode('; ', [
            '$arch = if ([Environment]::Is64BitOperatingSystem) { "64bit" } else { "32bit" }',
            '$url = "https://the.earth.li/~sgtatham/putty/latest/w$arch/putty-$arch-installer.msi"',
            '$msi = "$env:TEMP\putty-installer.msi"',
            'Write-Output "[Lando Studio] winget not found — downloading PuTTY installer directly..."',
            'Invoke-WebRequest -Uri $url -OutFile $msi -UseBasicParsing',
            'Start-Process msiexec -Wait -ArgumentList @("/i", $msi, "/qn")',
            'Remove-Item $msi -Force -ErrorAction SilentlyContinue',
            'Write-Output "[Lando Studio] PuTTY installed. plink.exe is in: $env:ProgramFiles\PuTTY\"',
        ]);
    }

    /**
     * Installs WSL2 on Windows: turns on the Virtual Machine Platform and Linux subsystem features,
     * then installs the WSL package. Both need admin rights, so the work runs in an elevated
     * PowerShell (Windows shows its UAC prompt). That process writes its own log, since its output
     * does not come back here. If Windows asks for a restart, the log says so.
     */
    public function installWsl(): string
    {
        $log = storage_path('logs/install_wsl.log');
        $inner = implode('; ', [
            '$a = 0; $b = 0',
            'dism.exe /online /enable-feature /featurename:VirtualMachinePlatform /all /norestart | Out-Null; $a = $LASTEXITCODE',
            'dism.exe /online /enable-feature /featurename:Microsoft-Windows-Subsystem-Linux /all /norestart | Out-Null; $b = $LASTEXITCODE',
            'winget install --id Microsoft.WSL -e --silent --accept-package-agreements --accept-source-agreements',
            'if ($a -eq 3010 -or $b -eq 3010) { Write-Output "[Lando Studio] WSL2 is installed. Restart Windows to finish, then reopen Lando Studio." } else { Write-Output "[Lando Studio] WSL2 is installed." }',
        ]);
        $inner .= ' *> \''.$log.'\'';

        return "Start-Process powershell -Verb RunAs -Wait -ArgumentList @('-NoProfile', '-Command', '".str_replace("'", "''", $inner)."')";
    }

    /**
     * Prepend a Homebrew bootstrap to a brew command so it works on a fresh
     * macOS machine that does not yet have Homebrew installed.
     *
     * NONINTERACTIVE=1 suppresses all prompts in the Homebrew install script.
     */
    private function withBrewBootstrap(string $brewCommand): string
    {
        $installBrew = 'NONINTERACTIVE=1 /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"';

        return "command -v brew >/dev/null 2>&1 || {$installBrew} && {$brewCommand}";
    }
}
