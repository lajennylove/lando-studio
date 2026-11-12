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
            'sshpass' => $this->installSshpass(),
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

            // get.docker.com auto-detects the distro (apt, yum, dnf, zypper, etc.), but not Arch-based ones.
            default => $this->platform->isArchLinux()
                ? $this->installDockerOnArch()
                : 'curl -fsSL https://get.docker.com | sh',
        };
    }

    /**
     * Installs sshpass for password SSH. On macOS it comes from the hudochenkov/sshpass Homebrew tap, which
     * the user must trust. On Linux the distro package is installed through pkexec (desktop prompt).
     */
    public function installSshpass(): string
    {
        if ($this->platform->os() === 'macos') {
            return $this->withBrewBootstrap('brew install hudochenkov/sshpass/sshpass');
        }

        if ($this->platform->isArchLinux()) {
            return 'pkexec pacman -S --noconfirm --needed sshpass';
        }

        return 'pkexec sh -c '.escapeshellarg('apt-get install -y sshpass || dnf install -y sshpass || zypper --non-interactive install sshpass');
    }

    /**
     * Trusts Lando's development CA in the Linux system store. `lando setup` runs this step through sudo,
     * and with no terminal sudo cannot ask for a password, so the app runs the same script through pkexec
     * after `lando setup --skip-install-ca`. Needs the CA certificate that `lando setup` creates.
     */
    public function installLandoCaLinux(): string
    {
        $home = $this->platform->homeDir();

        return 'pkexec '.escapeshellarg($home.'/.lando/scripts/install-system-ca-linux.sh')
            .' --ca '.escapeshellarg($home.'/.lando/certs/LandoCA.crt');
    }

    /**
     * Installs Docker with pacman on Arch-based Linux. The install runs without a terminal, so pkexec
     * raises the desktop's password prompt once for the whole root script. The Arch unit starts dockerd
     * from docker.socket, so the socket is enabled first, and failed units are reset (a failed start leaves
     * the socket rate-limited). The user name is passed as an argument, because $(id -un) inside the root
     * shell would name root. The braces send all output to the install log.
     */
    private function installDockerOnArch(): string
    {
        $rootScript = implode(' && ', [
            'pacman -S --noconfirm --needed docker docker-compose docker-buildx',
            'systemctl daemon-reload',
            'systemctl enable docker.socket docker.service',
            // Units must be loaded before reset-failed can see them. A failed start leaves the socket rate-limited.
            'systemctl reset-failed docker.socket docker.service 2>/dev/null || true',
            'systemctl start docker.socket docker.service',
            'usermod -aG docker "$1"',
        ]);

        return '{ pkexec sh -c '.escapeshellarg($rootScript).' lando-studio "$(id -un)" && '
            .'echo "[Lando Studio] Docker installed. Log out and back in so the docker group applies."; }';
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
        // Order matters: check the two Windows features first, enable only the ones that are off, install
        // the WSL package, then verify all three. Turning a feature on needs a restart before it is active,
        // so the message asks for one in that case. Docker Desktop reads these features at start-up.
        // Each step appends its own line to the log. A transcript buffers its output, so the log stayed empty while
        // the script was still running, and it hid where the script waited.
        $inner = implode('; ', [
            "\$log = '".str_replace("'", "''", $log)."'",
            'function Log($message) { Add-Content -LiteralPath $log -Value "$(Get-Date -Format s) $message" }',
            'Log "started"',
            '$features = @("VirtualMachinePlatform", "Microsoft-Windows-Subsystem-Linux")',
            '$restart = $false',
            'foreach ($f in $features) { if ((Get-WindowsOptionalFeature -Online -FeatureName $f).State -ne "Enabled") { Enable-WindowsOptionalFeature -Online -FeatureName $f -All -NoRestart | Out-Null; $restart = $true } }',
            // Checks the installed file, never runs wsl.exe: before the package exists, the wsl.exe stub installs it
            // and then waits for that install, which left this script stuck. A package whose files were removed
            // still shows as installed in winget, so a missing file means the package is removed and reinstalled.
            'function Test-Wsl { Test-Path "C:\Program Files\WSL\wsl.exe" }',
            'Log "features checked; restart needed: $restart"',
            'if (-not (Test-Wsl)) { Log "WSL missing; installing"; if (winget list --id Microsoft.WSL -e 2>$null | Select-String -Quiet "Microsoft.WSL") { Log "removing broken package"; winget uninstall --id Microsoft.WSL -e --silent }; winget install --id Microsoft.WSL -e --silent --accept-package-agreements --accept-source-agreements }',
            // With a restart still pending, the restart below is what finishes the setup, so it counts as installed.
            '$ok = ((Test-Wsl) -or $restart) -and (@($features | Where-Object { (Get-WindowsOptionalFeature -Online -FeatureName $_).State -in @("Enabled", "EnablePending") }).Count -eq 2)',
            'Log "verified: $ok"',
            'if (-not $ok) { Write-Output "[Lando Studio] WSL2 is not fully installed. Check install_wsl.log."; exit 1 }',
            // A feature that was just turned on only works after a restart, and the elevated process would wait
            // on that restart forever. So the restart is scheduled here, after the checks pass, with a short
            // countdown. shutdown.exe returns at once, so the wait ends and the app can show the message.
            'if ($restart) { Write-Output "[Lando Studio] WSL2 is installed. Restart Windows to finish: it restarts in 15 seconds, then reopen Lando Studio."; shutdown.exe /r /t 15 /c "Lando Studio is restarting Windows to finish the WSL2 setup." } else { Write-Output "[Lando Studio] WSL2 is installed." }',
        ]);

        // Passed as an encoded command: Start-Process joins -ArgumentList items without quoting, so double quotes
        // inside the script are stripped and the elevated PowerShell exits without running it. Base64 has no quotes or spaces.
        $encoded = base64_encode(mb_convert_encoding($inner, 'UTF-16LE', 'UTF-8'));

        return "Start-Process powershell -Verb RunAs -Wait -ArgumentList @('-NoProfile', '-EncodedCommand', '{$encoded}')";
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
