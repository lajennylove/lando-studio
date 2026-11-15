<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PersistentAppKey;
use PHPUnit\Framework\TestCase;

class PersistentAppKeyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lando-studio-key-'.uniqid();
    }

    protected function tearDown(): void
    {
        @unlink($this->directory.DIRECTORY_SEPARATOR.'app-key');
        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_first_use_creates_a_base64_key_of_32_bytes_and_stores_it(): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'app-key';

        $key = PersistentAppKey::resolve($path);

        $this->assertStringStartsWith('base64:', $key);
        $this->assertSame(32, strlen(base64_decode(substr($key, 7), true)));
        $this->assertSame($key, trim(file_get_contents($path)));
    }

    public function test_later_calls_return_the_same_key_so_encrypted_data_stays_readable(): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'app-key';

        $first = PersistentAppKey::resolve($path);
        $second = PersistentAppKey::resolve($path);

        $this->assertSame($first, $second);
    }

    public function test_an_empty_key_file_is_replaced(): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'app-key';
        mkdir($this->directory, 0700, true);
        file_put_contents($path, "  \n");

        $key = PersistentAppKey::resolve($path);

        $this->assertStringStartsWith('base64:', $key);
    }
}
