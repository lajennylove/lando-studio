<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DependencyInstaller;
use App\Services\PlatformDetector;
use Mockery;
use Tests\TestCase;

class WindowsDependencyInstallerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function windowsInstaller(): DependencyInstaller
    {
        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('os')->andReturn('windows');
        $platform->shouldReceive('isWindows')->andReturn(true);

        return new DependencyInstaller($platform);
    }

    public function test_wsl_install_enables_both_windows_features_and_the_wsl_package(): void
    {
        $command = $this->wslScript();

        $this->assertStringContainsString('VirtualMachinePlatform', $command);
        $this->assertStringContainsString('Microsoft-Windows-Subsystem-Linux', $command);
        $this->assertStringContainsString('winget install --id Microsoft.WSL', $command);
    }

    /** The script the elevated PowerShell runs: the -EncodedCommand payload of the WSL install command, decoded. */
    private function wslScript(): string
    {
        $command = $this->windowsInstaller()->getInstallCommand('wsl');
        preg_match("/-EncodedCommand', '([A-Za-z0-9+\\/=]+)'/", $command, $m);

        return mb_convert_encoding(base64_decode($m[1]), 'UTF-8', 'UTF-16LE');
    }

    public function test_wsl_install_runs_elevated_so_windows_shows_its_uac_prompt(): void
    {
        $command = $this->windowsInstaller()->getInstallCommand('wsl');

        $this->assertStringContainsString('-Verb RunAs', $command);
        $this->assertStringContainsString('-EncodedCommand', $command);
        $this->assertStringNotContainsString('-Command', $command);
    }

    public function test_wsl_install_writes_its_own_log_and_reports_a_restart(): void
    {
        $command = $this->wslScript();

        $this->assertStringContainsString('install_wsl.log', $command);
        $this->assertStringContainsString('Restart Windows', $command);
    }

    public function test_wsl_install_checks_that_wsl_runs_and_clears_a_broken_package_before_reinstalling(): void
    {
        $command = $this->wslScript();

        $this->assertStringContainsString('wsl.exe --version', $command);
        $this->assertStringNotContainsString('Program Files\WSL', $command);
        $this->assertStringContainsString('winget uninstall --id Microsoft.WSL', $command);
        $this->assertLessThan(
            strpos($command, 'winget install --id Microsoft.WSL'),
            strpos($command, 'winget uninstall --id Microsoft.WSL'),
            'the broken package must be removed before the install runs',
        );
    }

    public function test_wsl_install_logs_everything_the_script_prints_to_the_install_log(): void
    {
        $command = $this->wslScript();

        $this->assertStringStartsWith("Start-Transcript -Path '", $command);
        $this->assertStringContainsString('install_wsl.log', $command);
    }

    public function test_wsl_install_forces_the_restart_after_the_checks_so_the_process_does_not_wait_on_it(): void
    {
        $command = $this->wslScript();

        $this->assertStringContainsString('shutdown.exe /r /t 15', $command);
        $this->assertLessThan(
            strpos($command, 'shutdown.exe'),
            strpos($command, 'exit 1'),
            'the restart must come after the verification, so a failed install is not restarted',
        );
    }

    public function test_wsl_install_checks_features_before_enabling_them_and_verifies_afterwards(): void
    {
        $command = $this->wslScript();

        $this->assertStringContainsString('Get-WindowsOptionalFeature', $command);
        $this->assertStringContainsString('-ne "Enabled"', $command);
        $this->assertStringContainsString('Enable-WindowsOptionalFeature', $command);
        $this->assertStringContainsString('-NoRestart', $command);
        $this->assertStringContainsString('exit 1', $command);
    }

    public function test_lando_install_patches_the_broken_arm64_check_and_uses_the_x64_cli(): void
    {
        $command = $this->windowsInstaller()->getInstallCommand('lando');

        $this->assertStringContainsString('elseif ($arch -eq "ARM64")', $command);
        $this->assertStringContainsString('$SYSTEM_ARCHITECTURE = "x64"', $command);
        $this->assertStringContainsString('setup-lando.ps1', $command);
        $this->assertStringNotContainsString('elseif ($arch = ==', $command);
    }
}
