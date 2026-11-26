<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RemoteSite;
use App\Models\Site;
use App\Services\AstroPortAllocator;
use App\Services\PlatformDetector;
use App\Services\SiteManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Astro steps must build for Windows and for Linux/macOS. The step list is built once per platform, with
 * PlatformDetector answering for that platform, and each command is checked for its shell.
 */
class AstroCloneStepsPlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /** @return array<string, array{bool}> */
    public static function platforms(): array
    {
        return ['windows' => [true], 'linux and macos' => [false]];
    }

    /** @return array<string, string> label => command, for the Astro steps only */
    private function astroCommands(bool $windows): array
    {
        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('isWindows')->andReturn($windows);
        $platform->shouldReceive('homeDir')->andReturn(sys_get_temp_dir());
        $platform->shouldIgnoreMissing();
        $this->app->instance(PlatformDetector::class, $platform);

        $remote = RemoteSite::create([
            'remote_domain' => 'https://vegasaces.com/',
            'local_domain' => 'https://vegasaces.lndo.site',
            'ssh_server_ip' => 'server-01.example.test',
            'ssh_user' => 'ssh-user',
            'db_name' => 'example_db',
            'db_user' => 'example_db',
            'remote_path' => '/home/example/public_html/',
            'theme_name' => 'Vegaslobby',
            'repo_url' => 'https://git.example.test/team/theme.git',
            'astro_repo_url' => 'https://git.example.test/team/astro-site.git',
            'astro_branch' => 'main',
            'astro_folder' => 'vegas-astro',
        ]);
        $site = Site::create([
            'name' => 'vegasaces-'.uniqid(),
            'path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'vegasaces',
            'url' => 'https://vegasaces.lndo.site',
            'admin_url' => 'https://vegasaces.lndo.site/wp-admin',
            'status' => 'creating',
            'php_version' => '8.3',
            'db_version' => '11.8',
            'redis_version' => '8.6',
            'db_port' => 32789,
            'astro_port' => AstroPortAllocator::FIRST_PORT,
            'remote_site_id' => $remote->id,
        ]);

        $steps = app(SiteManager::class)->getCloneSiteSteps($site, $remote);

        $commands = [];
        foreach ($steps as $step) {
            if (str_starts_with($step['label'], 'Cloning Astro') || str_contains($step['label'], 'Astro') || str_contains($step['label'], 'Routing')) {
                $commands[$step['label']] = $step['command'];
            }
        }

        return $commands;
    }

    #[DataProvider('platforms')]
    public function test_every_astro_step_is_built_for_the_platform(bool $windows): void
    {
        $commands = $this->astroCommands($windows);

        $this->assertCount(7, $commands, 'clone, branch, env, install, build, serve and routing');
        foreach ($commands as $label => $command) {
            $this->assertNotSame('', trim($command), $label);
        }
    }

    #[DataProvider('platforms')]
    public function test_the_studio_artisan_steps_call_php_in_the_platform_shell(bool $windows): void
    {
        $commands = $this->astroCommands($windows);

        foreach (['Writing Astro environment', 'Starting Astro server', 'Routing the front end to Astro'] as $label) {
            $this->assertStringContainsString('lando-dev:', $commands[$label], $label);
            if ($windows) {
                $this->assertStringStartsWith('& ', $commands[$label], "$label runs php with the call operator on Windows");
            } else {
                $this->assertStringNotContainsString('& ', $commands[$label], "$label is a plain shell command on Unix");
                $this->assertStringNotContainsString('Set-Location', $commands[$label]);
            }
        }
    }

    public function test_windows_steps_never_use_bash_only_syntax_and_unix_steps_never_use_powershell(): void
    {
        $windows = $this->astroCommands(true);
        $unix = $this->astroCommands(false);

        // Windows: && may only appear inside the quoted command given to lando ssh (the container's shell), never as a
        // PowerShell operator. Every Windows step that uses it must have it inside -c "...".
        foreach ($windows as $label => $command) {
            $outside = preg_replace('/-c "[^"]*"/', '', $command);
            $this->assertStringNotContainsString('&& ', $outside, "Windows $label");
        }
        foreach ($unix as $label => $command) {
            $this->assertStringNotContainsString('Set-Location', $command, "Unix $label");
            $this->assertStringNotContainsString('$env:', $command, "Unix $label");
        }
    }
}
