<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\DatabasePortAllocator;
use App\Services\PlatformDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DatabasePortAllocatorTest extends TestCase
{
    use RefreshDatabase;

    private string $codePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ld_ports_'.uniqid();
        mkdir($this->codePath, 0755, true);

        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('defaultCodePath')->andReturn($this->codePath);
        $this->app->instance(PlatformDetector::class, $platform);

        config(['lando_dev.defaults.database_forward_port_start' => 40_100]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->codePath);

        parent::tearDown();
    }

    public function test_allocate_starts_at_config_default_when_nothing_is_claimed(): void
    {
        $this->assertSame(40_100, app(DatabasePortAllocator::class)->allocate());
    }

    public function test_allocate_skips_database_and_cache_ports_published_by_other_projects(): void
    {
        $this->writeProject('alpha', 40_100, 40_101);

        $this->assertSame(40_102, app(DatabasePortAllocator::class)->allocate());
    }

    public function test_allocate_skips_ports_stored_on_tracked_sites(): void
    {
        Site::create([
            'name' => 'tracked',
            'path' => $this->codePath.DIRECTORY_SEPARATOR.'tracked',
            'status' => SiteStatus::Stopped,
            'db_port' => 40_100,
        ]);

        $this->assertSame(40_101, app(DatabasePortAllocator::class)->allocate());
    }

    public function test_allocate_ignores_the_projects_own_claims_when_given_its_path(): void
    {
        $this->writeProject('alpha', 40_100);

        $path = $this->codePath.DIRECTORY_SEPARATOR.'alpha';

        $this->assertSame(40_100, app(DatabasePortAllocator::class)->allocate($path));
    }

    public function test_allocate_skips_a_port_something_is_listening_on(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = (string) stream_socket_get_name($server, false);
        $bound = (int) substr($address, strrpos($address, ':') + 1);

        try {
            config(['lando_dev.defaults.database_forward_port_start' => $bound]);

            $this->assertSame($bound + 1, app(DatabasePortAllocator::class)->allocate());
        } finally {
            fclose($server);
        }
    }

    public function test_assign_keeps_an_unclaimed_current_port(): void
    {
        $this->assertSame(40_108, app(DatabasePortAllocator::class)->assign($this->codePath.DIRECTORY_SEPARATOR.'new', 40_108));
    }

    public function test_assign_moves_a_port_held_by_a_tracked_site(): void
    {
        Site::create([
            'name' => 'tracked',
            'path' => $this->codePath.DIRECTORY_SEPARATOR.'tracked',
            'status' => SiteStatus::Stopped,
            'db_port' => 40_108,
        ]);

        $this->assertSame(40_100, app(DatabasePortAllocator::class)->assign($this->codePath.DIRECTORY_SEPARATOR.'new', 40_108));
    }

    public function test_assign_lets_the_earlier_untracked_folder_keep_a_shared_port(): void
    {
        $this->writeProject('pacific', 32_905);
        $this->writeProject('sportsanddata', 32_905);

        $allocator = app(DatabasePortAllocator::class);

        $this->assertSame(32_905, $allocator->assign($this->codePath.DIRECTORY_SEPARATOR.'pacific', 32_905));
        $this->assertSame(40_100, $allocator->assign($this->codePath.DIRECTORY_SEPARATOR.'sportsanddata', 32_905));
    }

    public function test_assign_never_hands_the_same_port_to_two_projects_in_one_batch(): void
    {
        $allocator = app(DatabasePortAllocator::class);

        $first = $allocator->assign($this->codePath.DIRECTORY_SEPARATOR.'one', null);
        $second = $allocator->assign($this->codePath.DIRECTORY_SEPARATOR.'two', null);

        $this->assertNotSame($first, $second);
    }

    private function writeProject(string $name, int $dbPort, ?int $cachePort = null): void
    {
        $dir = $this->codePath.DIRECTORY_SEPARATOR.$name;
        mkdir($dir, 0755, true);

        $cache = $cachePort === null ? 'true' : (string) $cachePort;

        file_put_contents($dir.DIRECTORY_SEPARATOR.'.lando.yml', <<<YAML
        name: {$name}
        services:
          database:
            portforward: {$dbPort}
          cache:
            portforward: {$cache}
        YAML);
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir.DIRECTORY_SEPARATOR.$entry;

            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
