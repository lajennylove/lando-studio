<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Site;
use App\Services\PlatformDetector;
use App\Services\SiteManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use ReflectionMethod;
use Tests\TestCase;

class SiteManagerGitCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function commandFrom(string $method, array $args, string $os): string
    {
        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('isWindows')->andReturn($os === 'windows');
        $this->app->instance(PlatformDetector::class, $platform);

        $reflection = new ReflectionMethod(SiteManager::class, $method);

        return $reflection->invokeArgs(app(SiteManager::class), $args);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_theme_clone_on_linux_fails_fast_when_git_needs_a_login(): void
    {
        $command = $this->commandFrom('gitCloneCommand', ['/home/dev/code/sites/betus', 'https://example.test/repo/', 'locker-room', new Site(['id' => 1])], 'linux');

        $this->assertStringContainsString('GIT_TERMINAL_PROMPT=0 git clone', $command);
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_theme_clone_on_windows_disables_the_git_prompt(): void
    {
        $command = $this->commandFrom('gitCloneCommand', ['C:\\sites\\betus', 'https://example.test/repo/', 'locker-room', new Site(['id' => 1])], 'windows');

        $this->assertStringContainsString("\$env:GIT_TERMINAL_PROMPT='0'", $command);
    }

    public function test_dev_checkout_keeps_the_current_branch_when_the_repo_has_no_dev(): void
    {
        $command = $this->commandFrom('gitCheckoutCommand', ['/home/dev/theme', 'main'], 'linux');

        // escapeshellarg quotes with double quotes on a Windows host, so the expectation uses it too.
        $this->assertStringContainsString('if git checkout '.escapeshellarg('main').' 2>/dev/null; then', $command);
        $this->assertStringContainsString('else echo "Branch main not found', $command);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_checkout_fast_forwards_to_the_fetched_remote_branch_on_linux(): void
    {
        $command = $this->commandFrom('gitCheckoutCommand', ['/home/dev/theme', 'main'], 'linux');

        $this->assertStringContainsString('git merge --ff-only '.escapeshellarg('origin/main'), $command);
        // A branch that cannot fast-forward fails the step through the error detector's marker.
        $this->assertStringContainsString('[Lando Studio ERROR] Branch main could not be fast-forwarded', $command);
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_checkout_fast_forwards_to_the_fetched_remote_branch_on_windows(): void
    {
        $command = $this->commandFrom('gitCheckoutCommand', ['C:\\theme', 'main'], 'windows');

        $this->assertStringContainsString("git merge --ff-only 'origin/main'", $command);
        $this->assertStringContainsString('[Lando Studio ERROR] Branch main could not be fast-forwarded', $command);
        // The merge only runs when the branch was found: it sits in the else branch of the checkout test.
        $this->assertGreaterThan(strpos($command, 'if ($LASTEXITCODE -ne 0)'), strpos($command, 'git merge'));
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_theme_clone_fetches_instead_of_cloning_when_the_folder_already_has_a_repo_on_linux(): void
    {
        $command = $this->commandFrom('gitCloneCommand', ['/home/dev/code/sites/betus', 'https://example.test/repo/', 'locker-room', new Site(['id' => 1])], 'linux');

        $this->assertStringContainsString('if [ -d '.escapeshellarg('locker-room').'/.git ]', $command);
        $this->assertStringContainsString('git fetch --progress origin', $command);
        $this->assertStringContainsString('GIT_TERMINAL_PROMPT=0 git fetch', $command);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_theme_clone_refuses_a_non_empty_folder_that_is_not_a_repo_on_linux(): void
    {
        $command = $this->commandFrom('gitCloneCommand', ['/home/dev/code/sites/betus', 'https://example.test/repo/', 'locker-room', new Site(['id' => 1])], 'linux');

        $this->assertStringContainsString('[Lando Studio ERROR] The theme folder already exists and is not a git repository', $command);
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_theme_clone_fetches_or_refuses_an_existing_folder_on_windows(): void
    {
        $command = $this->commandFrom('gitCloneCommand', ['C:\\sites\\betus', 'https://example.test/repo/', 'locker-room', new Site(['id' => 1])], 'windows');

        $this->assertStringContainsString("Test-Path -LiteralPath 'locker-room\\.git'", $command);
        $this->assertStringContainsString('git fetch --progress origin', $command);
        $this->assertStringContainsString('[Lando Studio ERROR] The theme folder already exists and is not a git repository', $command);
        $this->assertStringContainsString("throw 'git clone failed'", $command);
    }

    #[RequiresOperatingSystemFamily('Windows')]
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

        $this->assertStringContainsString('git checkout '.escapeshellarg('release/2026.1'), $command);
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_wordpress_core_check_fails_when_the_files_are_missing_on_windows(): void
    {
        $command = $this->commandFrom('wpCoreCheckCommand', ['C:\\sites\\test'], 'windows');

        $this->assertStringContainsString('compat.php', $command);
        $this->assertStringContainsString('Start-Sleep -Seconds 5', $command);
        $this->assertStringContainsString('throw', $command);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_wordpress_core_check_fails_when_the_files_are_missing_on_linux(): void
    {
        $command = $this->commandFrom('wpCoreCheckCommand', ['/home/dev/code/sites/test'], 'linux');

        $this->assertStringContainsString('compat.php', $command);
        $this->assertStringContainsString('sleep 5', $command);
        $this->assertStringContainsString('exit 1', $command);
    }
}
