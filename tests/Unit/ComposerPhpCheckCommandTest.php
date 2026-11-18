<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DependencyChecker;
use App\Services\LandoService;
use App\Services\PlatformDetector;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ComposerPhpCheckCommandTest extends TestCase
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

    #[DataProvider('platforms')]
    public function test_composer_install_runs_the_php_check_first(bool $windows): void
    {
        $command = $this->landoService($windows)->composerInstall('C:/sites/shop', 'wp/wp-content/themes/shop');

        $this->assertStringContainsString('lando:php-check', $command);
        $this->assertStringContainsString('--subdir=', $command);
        $this->assertLessThan(strpos($command, 'composer install'), strpos($command, 'lando:php-check'));
    }

    #[DataProvider('platforms')]
    public function test_composer_update_runs_the_php_check_first(bool $windows): void
    {
        $command = $this->landoService($windows)->composerUpdateWithHeartbeat('C:/sites/shop', 'wp/wp-content/themes/shop');

        $this->assertLessThan(strpos($command, 'composer'), strpos($command, 'lando:php-check'));
    }

    public function test_a_failed_check_or_composer_ends_with_an_error_line_on_windows(): void
    {
        $command = $this->landoService(true)->composerInstallWithHeartbeat('C:/sites/shop', 'wp/wp-content/themes/shop');

        $this->assertStringContainsString('if (-not $?)', $command);
        $this->assertStringContainsString('[Lando Studio ERROR] Composer did not finish', $command);
    }

    public function test_a_failed_check_or_composer_stops_the_heartbeat_on_unix(): void
    {
        $command = $this->landoService(false)->composerInstallWithHeartbeat('/sites/shop', 'wp/wp-content/themes/shop');

        $this->assertMatchesRegularExpression('/\|\| \{ echo .{1,3}\[Lando Studio ERROR\] Composer did not finish/', $command);
        $this->assertStringContainsString('kill $LANDO_STUDIO_HB', $command);
    }

    /** @return array<string, array{bool}> */
    public static function platforms(): array
    {
        return ['windows' => [true], 'unix' => [false]];
    }
}
