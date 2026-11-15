<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\RemoteSite;
use App\Services\DependencyChecker;
use App\Services\LandoService;
use App\Services\PlatformDetector;
use App\Services\SshService;
use Mockery;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use Tests\TestCase;

class VolumeCommandsTest extends TestCase
{
    private function windows(): void
    {
        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('isWindows')->andReturn(true);
        $platform->shouldReceive('normalizePathSeparators')->andReturnUsing(fn (string $p): string => $p);
        $this->app->instance(PlatformDetector::class, $platform);

        $checker = Mockery::mock(DependencyChecker::class);
        $checker->shouldReceive('getLandoPath')->andReturn('lando');
        $this->app->instance(DependencyChecker::class, $checker);
    }

    private function remote(): RemoteSite
    {
        $remote = new RemoteSite(['ssh_user' => 'u', 'ssh_server_ip' => 'h', 'db_name' => 'd']);
        $remote->id = 7;

        return $remote;
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_the_windows_plugin_sync_runs_in_the_container_when_the_plugins_are_a_volume(): void
    {
        $this->windows();

        $command = app(SshService::class)->buildRsyncPluginsCommand($this->remote(), 'C:\\sites\\shop\\wp\\wp-content\\plugins', 'shop_appserver_1');

        $this->assertStringContainsString('lando:rsync-plugins --remote-id=7', $command);
        $this->assertStringContainsString('--container=shop_appserver_1', $command);
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_the_windows_plugin_sync_stays_on_pscp_without_volumes(): void
    {
        $this->windows();

        $command = app(SshService::class)->buildRsyncPluginsCommand($this->remote(), 'C:\\sites\\shop\\wp\\wp-content\\plugins');

        $this->assertStringNotContainsString('--container', $command);
    }

    public function test_the_container_name_cannot_carry_shell_syntax(): void
    {
        $this->windows();

        $command = app(SshService::class)->buildRsyncPluginsCommand($this->remote(), 'C:\\x', 'shop_appserver_1; calc');

        $this->assertStringContainsString('--container=shop_appserver_1calc', $command);
        $this->assertStringNotContainsString(';', explode('--container=', $command)[1]);
    }

    public function test_sage_is_created_in_tmp_and_copied_when_the_theme_folder_holds_volume_mount_points(): void
    {
        $this->windows();

        $command = app(LandoService::class)->composerCreateProject('C:\\sites\\shop', 'roots/sage', 'shop', true);

        $this->assertStringContainsString('composer create-project roots/sage /tmp/shop --no-install', $command);
        $this->assertStringContainsString('cp -a /tmp/shop/. /app/wp/wp-content/themes/shop/', $command);
    }

    public function test_sage_is_created_in_place_without_volumes(): void
    {
        $this->windows();

        $command = app(LandoService::class)->composerCreateProject('C:\\sites\\shop', 'roots/sage', 'shop');

        $this->assertStringContainsString('composer create-project roots/sage shop --working-dir=/app/wp/wp-content/themes', $command);
        $this->assertStringNotContainsString('/tmp/', $command);
    }
}
