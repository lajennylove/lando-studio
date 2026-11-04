<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\ImportSites;
use App\Models\Site;
use App\Services\LandoService;
use App\Services\PlatformDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Native\Laravel\Facades\ChildProcess;
use Native\Laravel\Fakes\ChildProcessFake;
use Tests\TestCase;

class ImportSitesPortTest extends TestCase
{
    use RefreshDatabase;

    private string $codePath;

    private ChildProcessFake $childProcess;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lando_dev.defaults.database_forward_port_start' => 40_100]);

        $this->codePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ld_import_'.uniqid();
        mkdir($this->codePath, 0755, true);

        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('defaultCodePath')->andReturn($this->codePath);
        $platform->shouldReceive('shellWrapper')->andReturn('bash');
        $platform->shouldReceive('shellFlag')->andReturn('-c');
        $platform->shouldReceive('wrapCommandWithLogRedirect')->andReturnUsing(fn (string $command) => $command);
        $this->app->instance(PlatformDetector::class, $platform);

        $lando = Mockery::mock(LandoService::class);
        $lando->shouldReceive('rebuild')->andReturnUsing(fn (string $path) => "rebuild {$path}");
        $this->app->instance(LandoService::class, $lando);

        $this->childProcess = ChildProcess::fake();
    }

    /**
     * Number of background processes started through the fake. The fake's all() is not a list of starts.
     */
    private function startedProcesses(): array
    {
        return (new \ReflectionProperty($this->childProcess, 'starts'))->getValue($this->childProcess);
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('logs/import_rebuild_*.log')) ?: [] as $log) {
            unlink($log);
        }

        $this->removeDirectory($this->codePath);

        parent::tearDown();
    }

    public function test_import_moves_a_site_whose_port_clashes_and_rebuilds_it(): void
    {
        $this->writeProject('pacific', 40_200);
        $this->writeProject('sportsanddata', 40_200);

        Livewire::test(ImportSites::class)->call('import', 'sportsanddata');

        $this->assertStringContainsString('portforward: 40100', (string) file_get_contents($this->codePath.'/sportsanddata/.lando.yml'));
        $this->assertStringContainsString('portforward: 40200', (string) file_get_contents($this->codePath.'/pacific/.lando.yml'));
        $this->assertSame(40_100, Site::where('name', 'sportsanddata')->value('db_port'));

        $this->assertCount(1, $this->startedProcesses());
        $this->assertStringContainsString('rebuild '.$this->codePath.'/sportsanddata', implode(' ', (array) $this->startedProcesses()[0]['cmd']));
    }

    public function test_import_leaves_a_site_alone_when_its_port_is_unique(): void
    {
        $this->writeProject('solo', 40_200);

        Livewire::test(ImportSites::class)->call('import', 'solo');

        $this->assertSame(40_200, Site::where('name', 'solo')->value('db_port'));
        $this->assertStringContainsString('portforward: 40200', (string) file_get_contents($this->codePath.'/solo/.lando.yml'));
        $this->assertCount(0, $this->startedProcesses());
    }

    public function test_import_all_gives_every_site_a_unique_port_and_rebuilds_only_changed_ones(): void
    {
        $this->writeProject('alpha', 40_200);
        $this->writeProject('beta', 40_200);
        $this->writeProject('gamma', null);

        Livewire::test(ImportSites::class)->call('importAll');

        $ports = Site::query()->orderBy('name')->pluck('db_port', 'name')->all();

        $this->assertSame(['alpha' => 40_200, 'beta' => 40_100, 'gamma' => 40_101], $ports);
        $this->assertCount(1, $this->startedProcesses());
    }

    public function test_poll_stops_once_the_rebuild_marker_is_written(): void
    {
        $this->writeProject('beta', 40_200);
        $this->writeProject('alpha', 40_200);

        $component = Livewire::test(ImportSites::class)->call('import', 'beta');

        $log = $component->get('rebuildLogFile');
        $this->assertTrue($component->get('rebuildRunning'));

        file_put_contents((string) $log, "rebuilding...\n[LandoDEV] Port rebuild finished\n");

        $component->call('pollRebuild');

        $this->assertFalse($component->get('rebuildRunning'));
        $this->assertStringContainsString('rebuilding...', $component->get('rebuildOutput'));
    }

    private function writeProject(string $name, ?int $dbPort): void
    {
        $dir = $this->codePath.DIRECTORY_SEPARATOR.$name;
        mkdir($dir, 0755, true);

        $database = $dbPort === null ? 'true' : (string) $dbPort;

        file_put_contents($dir.DIRECTORY_SEPARATOR.'.lando.yml', <<<YAML
        name: {$name}
        recipe: wordpress
        config:
          php: "8.3"
        services:
          database:
            portforward: {$database}
          cache:
            portforward: true
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
