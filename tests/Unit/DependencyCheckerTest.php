<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DependencyChecker;
use App\Services\PlatformDetector;
use Mockery;
use PHPUnit\Framework\TestCase;

class DependencyCheckerTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lando-studio-home-'.uniqid();
        mkdir($this->home.'/.lando/bin', 0777, true);
        mkdir($this->home.'/.lando/certs', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->home);

        Mockery::close();

        parent::tearDown();
    }

    private function checkerWithHome(): DependencyChecker
    {
        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('isWindows')->andReturn(false);
        $platform->shouldReceive('homeDir')->andReturn($this->home);

        return new DependencyChecker($platform);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }

        rmdir($path);
    }

    public function test_lando_counts_as_installed_when_its_binary_is_in_the_home_bin_folder(): void
    {
        file_put_contents($this->home.'/.lando/bin/lando', "#!/bin/sh\n");

        $this->assertTrue($this->checkerWithHome()->isLandoInstalled());
    }

    public function test_lando_setup_counts_as_complete_when_it_has_created_the_ca_and_orchestrator(): void
    {
        file_put_contents($this->home.'/.lando/certs/LandoCA.crt', 'cert');
        file_put_contents($this->home.'/.lando/bin/docker-compose-v2.40.3', 'compose');

        $this->assertTrue($this->checkerWithHome()->isLandoSetupComplete());
    }

    public function test_lando_setup_is_not_complete_when_only_the_ca_exists(): void
    {
        file_put_contents($this->home.'/.lando/certs/LandoCA.crt', 'cert');

        $this->assertFalse($this->checkerWithHome()->isLandoSetupComplete());
    }
}
