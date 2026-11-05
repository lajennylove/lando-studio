<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DependencyInstaller;
use App\Services\PlatformDetector;
use Mockery;
use PHPUnit\Framework\TestCase;

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
        $command = $this->windowsInstaller()->getInstallCommand('wsl');

        $this->assertStringContainsString('VirtualMachinePlatform', $command);
        $this->assertStringContainsString('Microsoft-Windows-Subsystem-Linux', $command);
        $this->assertStringContainsString('winget install --id Microsoft.WSL', $command);
    }

    public function test_wsl_install_runs_elevated_so_windows_shows_its_uac_prompt(): void
    {
        $command = $this->windowsInstaller()->getInstallCommand('wsl');

        $this->assertStringContainsString('-Verb RunAs', $command);
    }

    public function test_wsl_install_writes_its_own_log_and_reports_a_restart(): void
    {
        $command = $this->windowsInstaller()->getInstallCommand('wsl');

        $this->assertStringContainsString('install_wsl.log', $command);
        $this->assertStringContainsString('3010', $command);
        $this->assertStringContainsString('Restart Windows', $command);
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
