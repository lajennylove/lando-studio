<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DevPlugins;
use App\Services\LandoService;
use App\Services\LandoYamlGenerator;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class DevPluginsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dev-plugins-'.uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);

        parent::tearDown();
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);

            return;
        }

        foreach (is_dir($path) ? array_diff((array) scandir($path), ['.', '..']) : [] as $entry) {
            $this->remove($path.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }

    /** @param  list<string>  $devPlugins */
    private function volumeSite(array $devPlugins = []): void
    {
        file_put_contents($this->dir.'/.lando.yml', (new LandoYamlGenerator)->generate('shop', $this->dir, [
            'use_volumes' => true,
            'theme_slug' => 'locker-room',
            'dev_plugins' => $devPlugins,
        ]));
    }

    /** @return list<mixed> */
    private function appserverVolumes(): array
    {
        $yaml = Yaml::parse((string) file_get_contents($this->dir.'/.lando.yml'));

        return $yaml['services']['appserver']['overrides']['volumes'];
    }

    public function test_dev_plugins_become_bind_mounts_over_the_plugins_volume(): void
    {
        $this->volumeSite(['my-plugin']);

        $this->assertContains(
            './wp/wp-content/plugins-dev/my-plugin:/app/wp/wp-content/plugins/my-plugin',
            $this->appserverVolumes()
        );
        $this->assertSame(['my-plugin'], LandoYamlGenerator::devPluginsFor($this->dir));
    }

    public function test_set_dev_plugins_edits_the_file_in_place_and_stays_valid_yaml(): void
    {
        $this->volumeSite();
        $generator = new LandoYamlGenerator;

        $generator->setDevPlugins($this->dir, ['one', 'two']);
        $this->assertSame(['one', 'two'], LandoYamlGenerator::devPluginsFor($this->dir));
        $this->assertCount(5 + 2, $this->appserverVolumes());

        $generator->setDevPlugins($this->dir, ['two']);
        $this->assertSame(['two'], LandoYamlGenerator::devPluginsFor($this->dir));

        $generator->setDevPlugins($this->dir, []);
        $this->assertSame([], LandoYamlGenerator::devPluginsFor($this->dir));
        $this->assertCount(5, $this->appserverVolumes());
    }

    public function test_set_dev_plugins_makes_an_older_chown_step_skip_mounts(): void
    {
        $this->volumeSite();
        $file = $this->dir.'/.lando.yml';
        $old = (string) preg_replace('/- find (.+) -xdev -exec chown www-data:www-data \{\} \+ \|\| true/', '- chown -R www-data:www-data $1 || true', (string) file_get_contents($file));
        file_put_contents($file, $old);
        $this->assertStringContainsString('- chown -R www-data:www-data /app/wp/wp-admin', $old);

        (new LandoYamlGenerator)->setDevPlugins($this->dir, ['x']);

        $this->assertStringNotContainsString('chown -R', (string) file_get_contents($file));
        $this->assertStringContainsString('-xdev', (string) file_get_contents($file));
    }

    public function test_regenerating_keeps_the_dev_plugins(): void
    {
        $this->volumeSite(['keep-me']);

        (new LandoYamlGenerator)->write('shop', $this->dir, ['theme_slug' => 'locker-room']);

        $this->assertSame(['keep-me'], LandoYamlGenerator::devPluginsFor($this->dir));
    }

    public function test_unsafe_names_are_never_written(): void
    {
        $this->volumeSite(['ok', '../evil', 'a b', 'x;y', '']);

        $this->assertSame(['ok'], LandoYamlGenerator::devPluginsFor($this->dir));
    }

    public function test_a_site_without_volumes_cannot_get_dev_plugins(): void
    {
        file_put_contents($this->dir.'/.lando.yml', (new LandoYamlGenerator)->generate('shop', $this->dir, ['use_volumes' => false]));

        $this->expectException(\RuntimeException::class);
        (new LandoYamlGenerator)->setDevPlugins($this->dir, ['x']);
    }

    public function test_the_chains_check_each_step_and_rebuild_between(): void
    {
        $lando = Mockery::mock(LandoService::class);
        $lando->shouldReceive('rebuild')->andReturn('REBUILD');
        $plugins = new DevPlugins($lando);

        $enable = $plugins->enableCommand('shop', 'C:\\sites\\shop', 'acme');
        $this->assertStringContainsString('lando:dev-plugin export', $enable);
        $this->assertStringContainsString('--container=shop_appserver_1', $enable);
        $this->assertLessThan(strpos($enable, 'REBUILD'), strpos($enable, 'throw'));

        $disable = $plugins->disableCommand('shop', 'C:\\sites\\shop', 'acme');
        $this->assertLessThan(strpos($disable, 'REBUILD'), strpos($disable, 'lando:dev-plugin unmount'));
        $this->assertGreaterThan(strpos($disable, 'REBUILD'), strpos($disable, 'lando:dev-plugin restore'));
    }

    public function test_it_refuses_names_and_repositories_a_shell_could_act_on(): void
    {
        $lando = Mockery::mock(LandoService::class);
        $lando->shouldReceive('rebuild')->andReturn('REBUILD');
        $plugins = new DevPlugins($lando);

        $this->assertFalse(DevPlugins::isValidRepoUrl('https://x.test/a.git; calc'));
        $this->assertFalse(DevPlugins::isValidRepoUrl('https://x.test/a b'));
        $this->assertTrue(DevPlugins::isValidRepoUrl('https://github.com/acme/my-plugin.git'));
        $this->assertTrue(DevPlugins::isValidRepoUrl('git@github.com:acme/my-plugin.git'));

        $this->expectException(\InvalidArgumentException::class);
        $plugins->enableCommand('shop', 'C:\\sites\\shop', 'x; calc');
    }

    public function test_create_makes_the_folder_and_unmount_removes_the_mount_but_keeps_the_folder(): void
    {
        $this->volumeSite();

        $this->assertSame(0, Artisan::call('lando:dev-plugin', [
            'action' => 'create', '--path' => $this->dir, '--plugin' => 'fresh', '--container' => 'shop_appserver_1',
        ]));
        $this->assertDirectoryExists($this->dir.'/wp/wp-content/plugins-dev/fresh');
        $this->assertSame(['fresh'], LandoYamlGenerator::devPluginsFor($this->dir));

        $this->assertSame(0, Artisan::call('lando:dev-plugin', [
            'action' => 'unmount', '--path' => $this->dir, '--plugin' => 'fresh', '--container' => 'shop_appserver_1',
        ]));
        $this->assertSame([], LandoYamlGenerator::devPluginsFor($this->dir));
        $this->assertDirectoryExists($this->dir.'/wp/wp-content/plugins-dev/fresh');
    }

    public function test_the_command_refuses_a_bad_name_and_a_site_without_volumes(): void
    {
        $this->volumeSite();
        $this->assertSame(1, Artisan::call('lando:dev-plugin', [
            'action' => 'create', '--path' => $this->dir, '--plugin' => '../x', '--container' => 'c',
        ]));

        file_put_contents($this->dir.'/.lando.yml', (new LandoYamlGenerator)->generate('shop', $this->dir, ['use_volumes' => false]));
        $this->assertSame(1, Artisan::call('lando:dev-plugin', [
            'action' => 'create', '--path' => $this->dir, '--plugin' => 'ok', '--container' => 'c',
        ]));
        $this->assertDirectoryDoesNotExist($this->dir.'/wp/wp-content/plugins-dev/ok');
    }
}
