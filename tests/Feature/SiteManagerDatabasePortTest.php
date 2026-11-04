<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\PlatformDetector;
use App\Services\SiteManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SiteManagerDatabasePortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the real sites folder and the real 32787 range out of these tests.
        config(['lando_dev.defaults.database_forward_port_start' => 40_100]);

        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('defaultCodePath')->andReturn(sys_get_temp_dir().DIRECTORY_SEPARATOR.'ld_empty_'.uniqid());
        $this->app->instance(PlatformDetector::class, $platform);
    }

    public function test_allocate_database_forward_port_starts_at_config_default(): void
    {
        $manager = app(SiteManager::class);

        $this->assertSame(
            (int) config('lando_dev.defaults.database_forward_port_start'),
            $manager->allocateDatabaseForwardPort()
        );
    }

    public function test_allocate_database_forward_port_skips_port_stored_on_a_site(): void
    {
        $start = (int) config('lando_dev.defaults.database_forward_port_start');

        Site::create([
            'name' => 'first',
            'path' => '/tmp/first',
            'status' => SiteStatus::Running,
            'db_port' => $start,
        ]);

        $manager = app(SiteManager::class);

        $this->assertSame($start + 1, $manager->allocateDatabaseForwardPort());
    }

    public function test_allocate_never_drops_below_config_start(): void
    {
        config(['lando_dev.defaults.database_forward_port_start' => 32_800]);

        Site::create([
            'name' => 'low',
            'path' => '/tmp/low',
            'status' => SiteStatus::Running,
            'db_port' => 32_799,
        ]);

        $this->assertSame(32_800, app(SiteManager::class)->allocateDatabaseForwardPort());
    }
}
