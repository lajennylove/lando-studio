<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AstroEnvCommandTest extends TestCase
{
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

    public function test_it_writes_the_env_file_into_the_astro_folder(): void
    {
        $status = Artisan::call('lando-dev:astro-env', ['path' => $this->root, 'folder' => 'the-beach', 'port' => '4326']);

        $this->assertSame(0, $status);
        $written = file_get_contents($this->root.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'the-beach'.DIRECTORY_SEPARATOR.'.env');
        $this->assertStringContainsString("PUBLIC_API_URL=http://127.0.0.1:4326\n", $written);
        $this->assertStringContainsString("REDIS_HOST=cache\n", $written);
    }

    public function test_a_folder_name_with_a_slash_is_refused(): void
    {
        $status = Artisan::call('lando-dev:astro-env', ['path' => $this->root, 'folder' => '../the-beach', 'port' => '4326']);

        $this->assertSame(1, $status);
        $this->assertFileDoesNotExist($this->root.DIRECTORY_SEPARATOR.'wp'.DIRECTORY_SEPARATOR.'the-beach'.DIRECTORY_SEPARATOR.'.env');
    }
}
