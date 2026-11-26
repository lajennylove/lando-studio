<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AstroEnvCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'astro-env-'.uniqid();
        mkdir($this->root.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'the-beach', 0755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'the-beach'.DIRECTORY_SEPARATOR.'.env');
        @rmdir($this->root.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'the-beach');
        @rmdir($this->root.DIRECTORY_SEPARATOR.'wp');
        @rmdir($this->root);

        parent::tearDown();
    }

    private function site(int $astroPort): Site
    {
        return Site::create([
            'name' => 'site-'.uniqid(),
            'path' => $this->root,
            'url' => 'https://x.lndo.site',
            'admin_url' => 'https://x.lndo.site/wp-admin',
            'status' => 'creating',
            'php_version' => '8.3',
            'db_version' => '11.8',
            'redis_version' => '8.6',
            'db_port' => 32800,
            'astro_port' => $astroPort,
        ]);
    }

    public function test_it_writes_the_env_file_with_the_site_astro_port(): void
    {
        $site = $this->site(4326);

        $status = Artisan::call('lando-dev:astro-env', ['path' => $this->root, 'folder' => 'the-beach', 'site-id' => (string) $site->id]);

        $this->assertSame(0, $status);
        $written = file_get_contents($this->root.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'the-beach'.DIRECTORY_SEPARATOR.'.env');
        $this->assertStringContainsString("PUBLIC_API_URL=http://127.0.0.1:4326\n", $written);
        $this->assertStringContainsString("REDIS_HOST=cache\n", $written);
    }

    public function test_a_folder_name_with_a_slash_is_refused(): void
    {
        $site = $this->site(4326);

        $status = Artisan::call('lando-dev:astro-env', ['path' => $this->root, 'folder' => '../the-beach', 'site-id' => (string) $site->id]);

        $this->assertSame(1, $status);
        $this->assertFileDoesNotExist($this->root.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'the-beach'.DIRECTORY_SEPARATOR.'.env');
    }
}
