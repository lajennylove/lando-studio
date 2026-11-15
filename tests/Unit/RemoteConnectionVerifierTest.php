<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\RemoteSite;
use App\Services\DependencyChecker;
use App\Services\RemoteConnectionVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Mockery;
use Tests\TestCase;

class RemoteConnectionVerifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // On Windows, getPlinkPath() is called before Process::run(). Stub it so tests
        // exercise the Process-fake path instead of short-circuiting with "plink not found".
        $this->instance(DependencyChecker::class, Mockery::mock(DependencyChecker::class, function ($mock) {
            $mock->allows('getPlinkPath')->andReturn('plink')->byDefault();
        }));
    }

    /**
     * Answers the SSH probe, then the schema probe. The Windows host-key lookup (plink -batch, no password) gets
     * an empty result, which means "key already cached".
     */
    private function fakeProbes(string $sshOutput, string $schemaOutput): void
    {
        $answers = [$sshOutput, $schemaOutput];

        Process::fake(function ($process) use (&$answers) {
            $command = is_array($process->command) ? $process->command : [];

            if (in_array('-batch', $command, true)) {
                return Process::result(output: '');
            }

            return Process::result(output: (string) array_shift($answers));
        });
    }

    public function test_succeeds_when_ssh_and_schema_dump_ok(): void
    {
        $remote = RemoteSite::query()->create([
            'remote_domain' => 'https://example.com',
            'local_domain' => null,
            'ssh_server_ip' => '10.0.0.1',
            'ssh_user' => 'u',
            'ssh_password' => 's',
            'db_name' => 'd',
            'db_user' => 'du',
            'db_password' => 'dp',
            'theme_name' => null,
            'repo_url' => null,
        ]);

        $this->fakeProbes('LANDO_STUDIO_SSH_OK', "-- MariaDB dump 10.6\n");

        $r = app(RemoteConnectionVerifier::class)->verify($remote);

        $this->assertTrue($r->ok);
    }

    public function test_fails_when_ssh_does_not_return_marker(): void
    {
        $remote = RemoteSite::query()->create([
            'remote_domain' => 'https://example.com',
            'local_domain' => null,
            'ssh_server_ip' => '10.0.0.1',
            'ssh_user' => 'u',
            'ssh_password' => 's',
            'db_name' => 'd',
            'db_user' => 'du',
            'db_password' => 'dp',
            'theme_name' => null,
            'repo_url' => null,
        ]);

        Process::fake([
            '*' => Process::result(output: 'Permission denied'),
        ]);

        $r = app(RemoteConnectionVerifier::class)->verify($remote);

        $this->assertFalse($r->ok);
        $this->assertSame('ssh', $r->phase);
    }

    public function test_fails_when_mysql_access_denied(): void
    {
        $remote = RemoteSite::query()->create([
            'remote_domain' => 'https://example.com',
            'local_domain' => null,
            'ssh_server_ip' => '10.0.0.1',
            'ssh_user' => 'u',
            'ssh_password' => 's',
            'db_name' => 'd',
            'db_user' => 'du',
            'db_password' => 'dp',
            'theme_name' => null,
            'repo_url' => null,
        ]);

        $this->fakeProbes('LANDO_STUDIO_SSH_OK', 'ERROR 1045 (28000): Access denied for user');

        $r = app(RemoteConnectionVerifier::class)->verify($remote);

        $this->assertFalse($r->ok);
        $this->assertSame('database', $r->phase);
    }

    public function test_a_1045_inside_a_schema_dump_is_not_an_access_error(): void
    {
        $dump = "/*M!999999\\- enable the sandbox mode */\n-- MariaDB dump 10.19\n) ENGINE=InnoDB AUTO_INCREMENT=1045 DEFAULT CHARSET=utf8mb4;\n";

        $this->assertFalse(RemoteConnectionVerifier::isAccessDenied($dump));
    }

    public function test_windows_ssh_command_reads_the_password_from_the_environment_not_the_command_line(): void
    {
        $password = "pa!ss%w^rd&'x";
        $verifier = app(RemoteConnectionVerifier::class);
        $method = new \ReflectionMethod($verifier, 'plinkViaPowerShell');
        $method->setAccessible(true);

        $command = $method->invoke($verifier, 'plink', '/home/u@10.0.0.1', 'echo LANDO_STUDIO_SSH_OK');
        $script = implode(' ', $command);

        $this->assertStringContainsString('-pw $env:LANDO_STUDIO_SSH_PW', $script);
        $this->assertStringNotContainsString($password, $script);
    }

    public function test_mysql_login_errors_are_access_errors(): void
    {
        $this->assertTrue(RemoteConnectionVerifier::isAccessDenied("mysqldump: Got error: 1045: Access denied for user 'u'@'localhost'"));
        $this->assertTrue(RemoteConnectionVerifier::isAccessDenied("ERROR 1045 (28000): Access denied for user 'u'@'localhost' (using password: YES)"));
    }
}
