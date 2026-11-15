<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LandoYamlGenerator;
use App\Services\OptimizedMode;
use App\Services\PlatformDetector;
use Mockery;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class LandoYamlGeneratorVolumesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ld_yaml_'.uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'{.lando.yml,lando-ca.crt}', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function onWindows(bool $windows, bool $optimized = true): void
    {
        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('isWindows')->andReturn($windows);
        $this->app->instance(PlatformDetector::class, $platform);

        $mode = Mockery::mock(OptimizedMode::class);
        $mode->shouldReceive('enabledForNewSites')->andReturn($windows && $optimized);
        $this->app->instance(OptimizedMode::class, $mode);
    }

    public function test_windows_without_optimized_mode_gets_no_volumes(): void
    {
        $this->onWindows(true, false);

        $this->assertStringNotContainsString('wp_plugins', (new LandoYamlGenerator)->generate('shop', $this->dir));
    }

    /**
     * @return array<string, mixed>
     */
    private function generate(array $options = []): array
    {
        $yaml = (new LandoYamlGenerator)->generate('shop', $this->dir, $options);

        return Yaml::parse($yaml);
    }

    public function test_windows_puts_core_and_plugins_on_volumes(): void
    {
        $this->onWindows(true);

        $parsed = $this->generate();
        $volumes = $parsed['services']['appserver']['overrides']['volumes'];

        $this->assertSame(
            ['/app/wp/wp-admin', '/app/wp/wp-includes', '/app/wp/wp-content/plugins'],
            array_column($volumes, 'target'),
        );
        $this->assertSame(['volume'], array_unique(array_column($volumes, 'type')));
        $this->assertSame(['wp_admin', 'wp_includes', 'wp_plugins'], array_keys($parsed['volumes']));
    }

    public function test_the_active_theme_gets_vendor_and_node_modules_volumes(): void
    {
        $this->onWindows(true);

        $parsed = $this->generate(['theme_slug' => 'locker-room']);
        $targets = array_column($parsed['services']['appserver']['overrides']['volumes'], 'target');

        $this->assertContains('/app/wp/wp-content/themes/locker-room/vendor', $targets);
        $this->assertContains('/app/wp/wp-content/themes/locker-room/node_modules', $targets);
        $this->assertArrayHasKey('theme_vendor', $parsed['volumes']);
    }

    public function test_every_declared_volume_is_used_and_every_used_volume_is_declared(): void
    {
        $this->onWindows(true);

        $parsed = $this->generate(['theme_slug' => 'locker-room']);
        $used = array_column($parsed['services']['appserver']['overrides']['volumes'], 'source');

        $this->assertEqualsCanonicalizing($used, array_keys($parsed['volumes']));
    }

    public function test_other_platforms_keep_plain_folders(): void
    {
        $this->onWindows(false);

        $parsed = $this->generate(['theme_slug' => 'locker-room']);

        $this->assertArrayNotHasKey('overrides', $parsed['services']['appserver']);
        $this->assertArrayNotHasKey('volumes', $parsed);
    }

    public function test_the_ca_cert_mount_and_the_volumes_share_one_overrides_block(): void
    {
        $this->onWindows(true);
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'lando-ca.crt', 'cert');

        $yaml = (new LandoYamlGenerator)->generate('shop', $this->dir, []);

        $this->assertSame(1, substr_count($yaml, 'overrides:'));
        $volumes = Yaml::parse($yaml)['services']['appserver']['overrides']['volumes'];
        $this->assertSame('./lando-ca.crt:/usr/local/share/ca-certificates/lando-ca.crt:ro', $volumes[0]);
    }

    public function test_volume_build_steps_hand_the_volumes_to_www_data_and_load_the_php_tuning_last(): void
    {
        $this->onWindows(true);

        $steps = $this->generate()['services']['appserver']['build_as_root'];

        $this->assertStringContainsString('find /app/wp/wp-admin /app/wp/wp-includes /app/wp/wp-content/plugins', $steps[0]);
        $this->assertStringContainsString('zzzz-lando-studio-perf.ini', $steps[1]);
        $this->assertStringContainsString('realpath_cache_size=4096K', $steps[1]);
        $this->assertStringNotContainsString('revalidate_freq', $steps[1], 'theme PHP edits must keep showing up within 2 s');
    }

    public function test_write_keeps_an_existing_project_without_volumes_free_of_them(): void
    {
        $this->onWindows(true);
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'.lando.yml', "name: shop\nservices:\n  appserver:\n    db_client: false\n");

        (new LandoYamlGenerator)->write('shop', $this->dir, ['theme_slug' => 'locker-room']);

        $this->assertFalse(LandoYamlGenerator::siteUsesVolumes($this->dir));
        $this->assertStringNotContainsString('type: volume', (string) file_get_contents($this->dir.DIRECTORY_SEPARATOR.'.lando.yml'));
    }

    public function test_write_keeps_the_volume_theme_of_an_existing_project(): void
    {
        $this->onWindows(true);
        (new LandoYamlGenerator)->write('shop', $this->dir, ['theme_slug' => 'locker-room']);

        (new LandoYamlGenerator)->write('shop', $this->dir, ['theme_slug' => 'another-theme', 'redis_version' => '7.0']);

        $this->assertTrue(LandoYamlGenerator::siteUsesVolumes($this->dir));
        $this->assertContains('/app/wp/wp-content/themes/locker-room/vendor', LandoYamlGenerator::volumeTargetsFor($this->dir));
        $this->assertNotContains('/app/wp/wp-content/themes/another-theme/vendor', LandoYamlGenerator::volumeTargetsFor($this->dir));
    }

    public function test_volume_targets_are_read_back_from_the_file(): void
    {
        $this->onWindows(true);
        (new LandoYamlGenerator)->write('shop', $this->dir, ['theme_slug' => 'locker-room']);

        $this->assertSame([
            '/app/wp/wp-admin',
            '/app/wp/wp-includes',
            '/app/wp/wp-content/plugins',
            '/app/wp/wp-content/themes/locker-room/vendor',
            '/app/wp/wp-content/themes/locker-room/node_modules',
        ], LandoYamlGenerator::volumeTargetsFor($this->dir));
        $this->assertSame([], LandoYamlGenerator::volumeTargetsFor($this->dir.DIRECTORY_SEPARATOR.'missing'));
    }
}
