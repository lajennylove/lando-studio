<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RepositoryCredential;
use App\Models\Site;
use App\Services\PlatformDetector;
use App\Services\SiteManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class RepositoryLoginCloneTest extends TestCase
{
    use RefreshDatabase;

    private function cloneCommand(string $os): string
    {
        $platform = Mockery::mock(PlatformDetector::class);
        $platform->shouldReceive('isWindows')->andReturn($os === 'windows');
        $this->app->instance(PlatformDetector::class, $platform);

        $method = new ReflectionMethod(SiteManager::class, 'gitCloneCommand');

        return $method->invokeArgs(app(SiteManager::class), [
            '/home/dev/code/sites/betus', 'https://devops.example.test/Projects/_git/wp-lockerroom/', 'locker-room', new Site(['id' => 7]),
        ]);
    }

    public function test_the_password_is_stored_encrypted(): void
    {
        RepositoryCredential::create(['host' => 'devops.example.test', 'username' => 'jenny', 'password' => 'plain-secret-9']);

        $raw = \DB::table('repository_credentials')->value('password');

        $this->assertNotSame('plain-secret-9', $raw);
        $this->assertSame('plain-secret-9', RepositoryCredential::where('host', 'devops.example.test')->first()->password);
    }

    public function test_a_host_has_one_saved_login(): void
    {
        RepositoryCredential::create(['host' => 'github.com', 'username' => 'a', 'password' => 'x']);

        $this->expectException(UniqueConstraintViolationException::class);
        RepositoryCredential::create(['host' => 'github.com', 'username' => 'b', 'password' => 'y']);
    }

    public function test_clone_without_a_saved_login_is_unchanged(): void
    {
        $command = $this->cloneCommand('linux');

        $this->assertStringContainsString('GIT_TERMINAL_PROMPT=0 GIT_SSH_COMMAND="ssh -o BatchMode=yes" git clone', $command);
        $this->assertStringNotContainsString('GIT_ASKPASS', $command);
    }

    public function test_clone_with_a_saved_login_reads_the_file_and_removes_it_without_the_password(): void
    {
        RepositoryCredential::create(['host' => 'devops.example.test', 'username' => 'jenny', 'password' => 'never-in-command-42']);

        $command = $this->cloneCommand('linux');

        $this->assertStringContainsString('GIT_ASKPASS=', $command);
        $this->assertStringContainsString('rm -f', $command);
        $this->assertStringNotContainsString('never-in-command-42', $command);
    }
}
