<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DependencyChecker;
use App\Services\LandoService;
use App\Services\PlatformDetector;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LandoServiceWpCommandsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function landoService(bool $windows): LandoService
    {
        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('isWindows')->andReturn($windows);

        $checker = Mockery::mock(DependencyChecker::class);
        $checker->shouldReceive('getLandoPath')->andReturn($windows ? 'C:\\lando\\lando.cmd' : '/usr/local/bin/lando');

        return new LandoService($platform, $checker);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function platforms(): array
    {
        return ['windows' => [true], 'unix' => [false]];
    }

    #[DataProvider('platforms')]
    public function test_domain_replacement_does_not_load_plugins_or_themes(bool $windows): void
    {
        $command = $this->landoService($windows)->wpSearchReplaceImported('/sites/betus', 'https://betus.lndo.site', 'locker-room');

        // Every wp call (siteurl lookup, template lookup, both search-replace runs) must carry the flags.
        $this->assertSame(4, substr_count($command, LandoService::WP_SKIP_CODE));
    }

    #[DataProvider('platforms')]
    public function test_domain_replacement_reports_an_error_when_the_remote_url_cannot_be_read(bool $windows): void
    {
        $command = $this->landoService($windows)->wpSearchReplaceImported('/sites/betus', 'https://betus.lndo.site');

        // The runner's error detector matches this prefix, so the step fails instead of running with an empty URL.
        $this->assertStringContainsString('[Lando Studio ERROR] Could not read the remote site URL', $command);
    }

    #[DataProvider('platforms')]
    public function test_domain_replacement_reports_an_error_when_the_theme_name_cannot_be_read(bool $windows): void
    {
        $command = $this->landoService($windows)->wpSearchReplaceImported('/sites/betus', 'https://betus.lndo.site', 'locker-room');

        $this->assertStringContainsString('[Lando Studio ERROR] Could not read the production theme name', $command);
    }

    public function test_composer_install_keeps_the_log_alive_outside_windows(): void
    {
        $command = $this->landoService(false)->composerInstall('/sites/betus', 'wp/wp-content/themes/locker-room');

        $this->assertStringContainsString('Composer install in progress', $command);
    }

    public function test_composer_install_on_windows_is_left_to_the_step_wrapper_heartbeat(): void
    {
        $command = $this->landoService(true)->composerInstall('C:\\sites\\betus', 'wp/wp-content/themes/locker-room');

        $this->assertStringNotContainsString('while sleep', $command);
        $this->assertStringContainsString('composer install', $command);
    }
}
