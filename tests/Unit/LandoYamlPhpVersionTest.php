<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LandoYamlGenerator;
use PHPUnit\Framework\TestCase;

class LandoYamlPhpVersionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lando-php-'.uniqid();
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir.'/.lando.yml', "name: shop\nrecipe: wordpress\nconfig:\n  webroot: wp\n  php: \"8.3\"\n  via: apache\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/.lando.yml');
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_it_reads_the_php_version(): void
    {
        $this->assertSame('8.3', LandoYamlGenerator::phpVersionFor($this->dir));
    }

    public function test_it_sets_the_version_and_leaves_the_rest_of_the_file_alone(): void
    {
        (new LandoYamlGenerator)->setPhpVersion($this->dir, '8.4');

        $this->assertSame("name: shop\nrecipe: wordpress\nconfig:\n  webroot: wp\n  php: \"8.4\"\n  via: apache\n", file_get_contents($this->dir.'/.lando.yml'));
        $this->assertSame('8.4', LandoYamlGenerator::phpVersionFor($this->dir));
    }

    public function test_a_version_that_is_not_major_minor_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new LandoYamlGenerator)->setPhpVersion($this->dir, '8.4.1');
    }

    public function test_a_file_without_a_php_line_is_refused(): void
    {
        file_put_contents($this->dir.'/.lando.yml', "name: shop\n");

        $this->expectException(\RuntimeException::class);

        (new LandoYamlGenerator)->setPhpVersion($this->dir, '8.4');
    }
}
