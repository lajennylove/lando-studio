<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\DatabasePortAllocator;
use App\Services\PlatformDetector;
use App\Support\RefusedDatabasePorts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RefusedDatabasePortsTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    private string $codePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ld_refused_'.uniqid().'.json';
        $this->codePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ld_refused_code_'.uniqid();
        mkdir($this->codePath, 0755, true);

        config(['lando_dev.refused_ports_file' => $this->file, 'lando_dev.defaults.database_forward_port_start' => 42_100]);

        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('defaultCodePath')->andReturn($this->codePath);
        $this->app->instance(PlatformDetector::class, $platform);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @rmdir($this->codePath);

        parent::tearDown();
    }

    public function test_a_refused_port_is_remembered(): void
    {
        $refused = new RefusedDatabasePorts;
        $this->assertSame([], $refused->all());

        $refused->add(42_100);
        $refused->add(42_101);

        $this->assertEqualsCanonicalizing([42_100, 42_101], (new RefusedDatabasePorts)->all());
    }

    public function test_a_port_refused_long_ago_is_forgotten(): void
    {
        file_put_contents($this->file, json_encode([
            '42100' => time() - RefusedDatabasePorts::TTL_SECONDS - 60,
            '42101' => time() - 60,
        ]));

        $this->assertSame([42_101], (new RefusedDatabasePorts)->all());
    }

    public function test_the_allocator_skips_remembered_ports_for_a_new_site(): void
    {
        (new RefusedDatabasePorts)->add(42_100);
        (new RefusedDatabasePorts)->add(42_101);

        $this->assertSame(42_102, app(DatabasePortAllocator::class)->allocate());
    }

    public function test_a_damaged_file_is_treated_as_empty(): void
    {
        file_put_contents($this->file, '{not json');

        $this->assertSame([], (new RefusedDatabasePorts)->all());
        $this->assertSame(42_100, app(DatabasePortAllocator::class)->allocate());
    }
}
