<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\GitCredentials;
use Tests\TestCase;

class GitCredentialsTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = GitCredentials::fileFor('test-'.bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        GitCredentials::remove($this->file);

        parent::tearDown();
    }

    public function test_the_file_holds_username_then_password_with_private_permissions(): void
    {
        GitCredentials::write($this->file, 'jenny', 'pa55 word');

        $this->assertSame("jenny\npa55 word\n", file_get_contents($this->file));
        $this->assertSame(0600, fileperms($this->file) & 0777);
    }

    public function test_values_with_line_breaks_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GitCredentials::write($this->file, "jenny\nevil", 'x');
    }

    public function test_the_shell_prefix_names_files_but_never_the_password(): void
    {
        GitCredentials::write($this->file, 'jenny', 'secret-value-123');

        $prefix = GitCredentials::shellPrefix($this->file);

        $this->assertStringContainsString('GIT_ASKPASS=', $prefix);
        $this->assertStringContainsString('LANDO_STUDIO_GIT_CREDENTIALS=', $prefix);
        $this->assertStringNotContainsString('secret-value-123', $prefix);
    }

    public function test_the_askpass_script_answers_from_the_file_and_is_private(): void
    {
        $script = GitCredentials::askpassPath();

        $this->assertStringContainsString('sed -n 1p', file_get_contents($script));
        $this->assertSame(0700, fileperms($script) & 0777);
    }
}
