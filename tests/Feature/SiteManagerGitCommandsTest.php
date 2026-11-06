<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PlatformDetector;
use App\Services\SiteManager;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class SiteManagerGitCommandsTest extends TestCase
{
    private function commandFrom(string $method, array $args, string $os): string
    {
        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('isWindows')->andReturn($os === 'windows');
        $this->app->instance(PlatformDetector::class, $platform);

        $reflection = new ReflectionMethod(SiteManager::class, $method);

        return $reflection->invokeArgs(app(SiteManager::class), $args);
    }

    public function test_theme_clone_on_linux_fails_fast_when_git_needs_a_login(): void
    {
        $command = $this->commandFrom('gitCloneCommand', ['/home/dev/code/sites/betus', 'https://example.test/repo/', 'locker-room'], 'linux');

        $this->assertStringContainsString('GIT_TERMINAL_PROMPT=0 git clone', $command);
    }

    public function test_theme_clone_on_windows_disables_the_git_prompt(): void
    {
        $command = $this->commandFrom('gitCloneCommand', ['C:\\sites\\betus', 'https://example.test/repo/', 'locker-room'], 'windows');

        $this->assertStringContainsString("\$env:GIT_TERMINAL_PROMPT='0'", $command);
    }

    public function test_dev_checkout_keeps_the_current_branch_when_the_repo_has_no_dev(): void
    {
        $command = $this->commandFrom('gitCheckoutCommand', ['/home/dev/theme', 'main'], 'linux');

        $this->assertStringContainsString("(git checkout 'main' 2>/dev/null || echo \"Branch main not found", $command);
    }

    public function test_dev_checkout_on_windows_does_not_fail_when_the_branch_is_missing(): void
    {
        $command = $this->commandFrom('gitCheckoutCommand', ['C:\\theme', 'main'], 'windows');

        $this->assertStringContainsString('if ($LASTEXITCODE -ne 0)', $command);
    }

    public function test_branch_names_with_normal_git_characters_are_accepted(): void
    {
        foreach (['main', 'release/2026.1', 'feature-login_v2', 'a', 'hotfix/a1.b2'] as $branch) {
            $this->assertTrue(SiteManager::isValidBranchName($branch), $branch);
        }
    }

    public function test_branch_names_that_could_reach_the_shell_are_rejected(): void
    {
        foreach (['main; rm -rf ~', 'main && whoami', '$(id)', 'a b', 'main`id`', '../etc', 'a..b', 'a//b', '-main', 'main/', '', str_repeat('a', 101)] as $branch) {
            $this->assertFalse(SiteManager::isValidBranchName($branch), $branch);
        }
    }

    public function test_checkout_quotes_the_branch_for_the_shell(): void
    {
        $command = $this->commandFrom('gitCheckoutCommand', ['/home/dev/theme', 'release/2026.1'], 'linux');

        $this->assertStringContainsString("git checkout 'release/2026.1'", $command);
    }
}
