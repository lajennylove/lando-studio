<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DependencyInstaller;
use App\Services\PlatformDetector;
use Mockery;
use PHPUnit\Framework\TestCase;

class DependencyInstallerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function installerFor(string $os): DependencyInstaller
    {
        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('os')->andReturn($os);
        $platform->shouldReceive('isWindows')->andReturn($os === 'windows');

        return new DependencyInstaller($platform);
    }

    public function test_macos_docker_install_uses_orbstack_not_docker_desktop(): void
    {
        $command = $this->installerFor('macos')->getInstallCommand('docker');

        $this->assertStringContainsString('brew install --cask orbstack', $command);
        $this->assertStringNotContainsString('brew install --cask docker', $command);
    }

    public function test_macos_install_links_docker_app_to_orbstack_with_admin_prompt(): void
    {
        $command = $this->installerFor('macos')->getInstallCommand('docker');

        $this->assertStringContainsString('ln -sfn /Applications/OrbStack.app /Applications/Docker.app', $command);
        $this->assertStringContainsString('with administrator privileges', $command);
    }

    public function test_macos_link_step_never_overwrites_an_existing_docker_app(): void
    {
        $command = $this->installerFor('macos')->getInstallCommand('docker');

        $this->assertStringContainsString('if [ ! -e /Applications/Docker.app ] && [ ! -L /Applications/Docker.app ]', $command);
    }

    public function test_link_step_runs_only_after_a_successful_brew_install(): void
    {
        $command = $this->installerFor('macos')->getInstallCommand('docker');

        $this->assertStringContainsString('brew install --cask orbstack && if [', $command);
    }

    public function test_windows_and_linux_docker_install_are_unchanged(): void
    {
        $this->assertStringContainsString('get.docker.com', $this->installerFor('ubuntu')->getInstallCommand('docker'));
        $this->assertStringNotContainsString('OrbStack', $this->installerFor('ubuntu')->getInstallCommand('docker'));
        $this->assertStringNotContainsString('OrbStack', $this->installerFor('windows')->getInstallCommand('docker'));
    }
}
