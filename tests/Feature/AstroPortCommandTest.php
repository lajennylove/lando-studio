<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Site;
use App\Services\AstroPortAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AstroPortCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'astro-port-cmd-'.uniqid();
        mkdir($this->root.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'the-beach', 0755, true);
    }

    protected function tearDown(): void
    {
        $dir = $this->root.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'the-beach';
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
        @rmdir($this->root.DIRECTORY_SEPARATOR.'wp');
        @rmdir($this->root);

        parent::tearDown();
    }

    private function writeConfig(string $content): void
    {
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'the-beach'.DIRECTORY_SEPARATOR.'astro.config.mjs', $content);
    }

    private function site(string $name, ?int $astroPort): Site
    {
        return Site::create([
            'name' => $name,
            'path' => $this->root,
            'url' => "https://{$name}.lndo.site",
            'admin_url' => "https://{$name}.lndo.site/wp-admin",
            'status' => 'creating',
            'php_version' => '8.3',
            'db_version' => '11.8',
            'redis_version' => '8.6',
            'db_port' => 32800,
            'astro_port' => $astroPort,
        ]);
    }

    public function test_the_site_takes_the_port_its_astro_config_sets(): void
    {
        $this->writeConfig("export default defineConfig({ port: 4324, base: \"/the-beach\" });\n");
        $site = $this->site('slotsparadise', AstroPortAllocator::FIRST_PORT);

        $status = Artisan::call('lando-dev:astro-port', ['path' => $this->root, 'folder' => 'the-beach', 'site-id' => (string) $site->id]);

        $this->assertSame(0, $status);
        $this->assertSame(4324, $site->fresh()->astro_port);
    }

    public function test_a_port_already_used_by_another_site_is_refused(): void
    {
        $this->writeConfig("export default defineConfig({ port: 4324 });\n");
        $this->site('other', 4324);
        $site = $this->site('slotsparadise', AstroPortAllocator::FIRST_PORT);

        $status = Artisan::call('lando-dev:astro-port', ['path' => $this->root, 'folder' => 'the-beach', 'site-id' => (string) $site->id]);

        $this->assertSame(1, $status);
        $this->assertSame(AstroPortAllocator::FIRST_PORT, $site->fresh()->astro_port);
    }

    public function test_a_config_without_a_port_keeps_the_site_port(): void
    {
        $this->writeConfig("export default defineConfig({ base: \"/lobby\" });\n");
        $site = $this->site('vegasaces', 4323);

        $status = Artisan::call('lando-dev:astro-port', ['path' => $this->root, 'folder' => 'the-beach', 'site-id' => (string) $site->id]);

        $this->assertSame(0, $status);
        $this->assertSame(4323, $site->fresh()->astro_port);
    }
}
