<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AstroConfigPort;
use PHPUnit\Framework\TestCase;

class AstroConfigPortTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'astro-port-'.uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_the_port_is_read_from_the_astro_config(): void
    {
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'astro.config.mjs', "export default defineConfig({\n  port: 4324,\n  base: \"/the-beach\",\n});\n");

        $this->assertSame(4324, AstroConfigPort::read($this->dir));
    }

    public function test_a_config_without_a_port_gives_null(): void
    {
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'astro.config.mjs', "export default defineConfig({ base: \"/lobby\" });\n");

        $this->assertNull(AstroConfigPort::read($this->dir));
    }

    public function test_a_folder_without_a_config_gives_null(): void
    {
        $this->assertNull(AstroConfigPort::read($this->dir));
    }

    public function test_a_port_outside_the_usable_range_is_ignored(): void
    {
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'astro.config.mjs', "export default defineConfig({ port: 80 });\n");

        $this->assertNull(AstroConfigPort::read($this->dir));
    }
}
