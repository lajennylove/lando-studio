<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Site;
use App\Services\PlatformDetector;
use App\Services\SiteManager;
use Mockery;
use Tests\TestCase;

class SiteManagerDeletePathTest extends TestCase
{
    private function useOs(string $os): void
    {
        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('isWindows')->andReturn($os === 'windows');
        $platform->shouldReceive('os')->andReturn($os);
        $this->app->instance(PlatformDetector::class, $platform);
    }

    public function test_linux_delete_retries_through_pkexec_when_root_owned_files_remain(): void
    {
        $this->useOs('linux');
        $site = new Site(['path' => '/home/dev/code/sites/test']);

        $command = app(SiteManager::class)->getDeletePath($site);

        $this->assertSame(
            "rm -rf '/home/dev/code/sites/test'; [ ! -e '/home/dev/code/sites/test' ] || pkexec rm -rf '/home/dev/code/sites/test'",
            $command,
        );
    }

    public function test_macos_delete_is_a_plain_rm_without_pkexec(): void
    {
        $this->useOs('macos');
        $site = new Site(['path' => '/Users/dev/code/sites/test']);

        $this->assertSame("rm -rf '/Users/dev/code/sites/test'", app(SiteManager::class)->getDeletePath($site));
    }

    public function test_delete_path_with_a_quote_is_escaped(): void
    {
        $this->useOs('linux');
        $site = new Site(['path' => "/home/dev/it's a site"]);

        $command = app(SiteManager::class)->getDeletePath($site);

        $this->assertStringStartsWith("rm -rf '/home/dev/it'\\''s a site'", $command);
    }
}
