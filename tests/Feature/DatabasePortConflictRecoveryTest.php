<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\DatabasePortAllocator;
use App\Services\PlatformDetector;
use App\Services\SiteManager;
use App\Support\CommandLogErrorDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DatabasePortConflictRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $codePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ld_conflict_'.uniqid();
        mkdir($this->codePath, 0755, true);

        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('defaultCodePath')->andReturn($this->codePath);
        $this->app->instance(PlatformDetector::class, $platform);

        config(['lando_dev.defaults.database_forward_port_start' => 41_100]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->codePath.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'.lando.yml') ?: [] as $file) {
            unlink($file);
            rmdir(dirname($file));
        }
        @rmdir($this->codePath);

        parent::tearDown();
    }

    private function site(int $dbPort): Site
    {
        $dir = $this->codePath.DIRECTORY_SEPARATOR.'betustv';
        mkdir($dir, 0755, true);
        file_put_contents($dir.DIRECTORY_SEPARATOR.'.lando.yml', "name: betustv\nservices:\n  database:\n    portforward: {$dbPort}\n  cache:\n    portforward: true\n");

        return Site::create(['name' => 'betustv', 'path' => $dir, 'status' => SiteStatus::Creating, 'db_port' => $dbPort]);
    }

    public function test_detector_reads_the_refused_port_from_the_docker_error(): void
    {
        $log = "Error response from daemon: failed to set up container networking: driver failed programming external connectivity on endpoint betustv_database_1\n127.0.0.1:32790 failed: port is already allocated\n";

        $this->assertSame(32790, CommandLogErrorDetector::conflictingPort($log));
        $this->assertNull(CommandLogErrorDetector::conflictingPort("Healthcheck failed\n"));
    }

    public function test_allocate_skips_a_port_docker_refused_even_though_nothing_listens_on_it(): void
    {
        $this->assertSame(41_101, app(DatabasePortAllocator::class)->allocate(null, [41_100]));
    }

    public function test_the_refused_database_port_moves_in_the_yaml_and_the_site_record(): void
    {
        $site = $this->site(41_100);

        $new = app(SiteManager::class)->reassignDatabasePortAfterConflict($site, 41_100);

        $this->assertSame(41_101, $new);
        $this->assertSame(41_101, $site->fresh()->db_port);
        $yaml = (string) file_get_contents($site->path.DIRECTORY_SEPARATOR.'.lando.yml');
        $this->assertStringContainsString('portforward: 41101', $yaml);
        $this->assertStringContainsString('portforward: true', $yaml);
    }

    public function test_a_refused_port_that_is_not_the_database_port_is_left_alone(): void
    {
        $site = $this->site(41_100);

        $this->assertNull(app(SiteManager::class)->reassignDatabasePortAfterConflict($site, 56_811));
        $this->assertSame(41_100, $site->fresh()->db_port);
        $this->assertStringContainsString('portforward: 41100', (string) file_get_contents($site->path.DIRECTORY_SEPARATOR.'.lando.yml'));
    }
}
