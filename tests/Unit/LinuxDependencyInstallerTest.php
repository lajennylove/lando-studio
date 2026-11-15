<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DependencyInstaller;
use App\Services\PlatformDetector;
use Mockery;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;

class LinuxDependencyInstallerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function linuxInstaller(bool $arch): DependencyInstaller
    {
        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('os')->andReturn('linux');
        $platform->shouldReceive('isArchLinux')->andReturn($arch);
        $platform->shouldReceive('homeDir')->andReturn('/home/dev');

        return new DependencyInstaller($platform);
    }

    public function test_sshpass_on_arch_installs_the_package_through_pkexec(): void
    {
        $command = $this->linuxInstaller(arch: true)->getInstallCommand('sshpass');

        $this->assertSame('pkexec pacman -S --noconfirm --needed sshpass', $command);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_sshpass_on_other_linux_tries_the_common_package_managers(): void
    {
        $command = $this->linuxInstaller(arch: false)->getInstallCommand('sshpass');

        $this->assertStringStartsWith('pkexec sh -c ', $command);
        $this->assertStringContainsString('apt-get install -y sshpass', $command);
        $this->assertStringContainsString('dnf install -y sshpass', $command);
    }

    #[RequiresOperatingSystemFamily('Darwin')]
    public function test_sshpass_on_macos_installs_from_the_homebrew_tap(): void
    {
        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('os')->andReturn('macos');

        $command = (new DependencyInstaller($platform))->getInstallCommand('sshpass');

        $this->assertStringContainsString('brew install hudochenkov/sshpass/sshpass', $command);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_lando_ca_on_linux_runs_lando_script_through_pkexec(): void
    {
        $command = $this->linuxInstaller(arch: false)->installLandoCaLinux();

        $this->assertSame(
            "pkexec '/home/dev/.lando/scripts/install-system-ca-linux.sh' --ca '/home/dev/.lando/certs/LandoCA.crt'",
            $command,
        );
    }

    public function test_docker_on_arch_uses_pacman_through_pkexec_and_not_the_docker_script(): void
    {
        $command = $this->linuxInstaller(arch: true)->getInstallCommand('docker');

        $this->assertStringContainsString('pkexec sh -c', $command);
        $this->assertStringContainsString('pacman -S --noconfirm --needed docker docker-compose docker-buildx', $command);
        $this->assertStringContainsString('systemctl daemon-reload', $command);
        $this->assertStringContainsString('systemctl enable docker.socket docker.service', $command);
        $this->assertStringContainsString('systemctl start docker.socket docker.service', $command);
        $this->assertLessThan(
            strpos($command, 'systemctl start'),
            strpos($command, 'systemctl enable'),
            'units must be enabled (and so loaded) before they are started',
        );
        $this->assertStringNotContainsString('get.docker.com', $command);
    }

    public function test_docker_on_arch_sends_all_output_to_the_install_log(): void
    {
        $command = $this->linuxInstaller(arch: true)->getInstallCommand('docker');

        $this->assertStringStartsWith('{ ', $command);
        $this->assertStringEndsWith('; }', $command);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_docker_on_other_linux_keeps_the_docker_install_script(): void
    {
        $command = $this->linuxInstaller(arch: false)->getInstallCommand('docker');

        $this->assertSame('curl -fsSL https://get.docker.com | sh', $command);
    }
}
