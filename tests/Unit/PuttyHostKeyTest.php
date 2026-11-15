<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PuttyHostKey;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class PuttyHostKeyTest extends TestCase
{
    private const NOT_CACHED = "The host key is not cached for this server:\n  example.test (port 22)\nThe server's ssh-ed25519 key fingerprint is:\n  ssh-ed25519 255 SHA256:l66CKVckGkgAOWHUjBrAH6fmlce+cghKyr0f2ShNjEw\nConnection abandoned.\nFATAL ERROR: Cannot confirm a host key in batch mode";

    public function test_it_reads_the_fingerprint_from_the_not_cached_message(): void
    {
        $this->assertSame(
            'SHA256:l66CKVckGkgAOWHUjBrAH6fmlce+cghKyr0f2ShNjEw',
            PuttyHostKey::parseFingerprint(self::NOT_CACHED),
        );
    }

    public function test_it_ignores_output_that_is_not_a_host_key_prompt(): void
    {
        $this->assertNull(PuttyHostKey::parseFingerprint("Access denied\nFATAL ERROR: Cannot confirm"));
        $this->assertNull(PuttyHostKey::parseFingerprint(''));
    }

    public function test_arguments_pass_the_fingerprint_as_hostkey(): void
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: self::NOT_CACHED, exitCode: 1)]);

        $this->assertSame(
            ['-hostkey', 'SHA256:l66CKVckGkgAOWHUjBrAH6fmlce+cghKyr0f2ShNjEw'],
            PuttyHostKey::argumentsFor('plink.exe', 'user@example.test'),
        );
        Process::assertRan(fn ($process) => in_array('-batch', $process->command, true) && ! in_array('-pw', $process->command, true));
    }

    public function test_arguments_are_empty_when_the_key_is_already_cached(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'Using username "user".', exitCode: 1)]);

        $this->assertSame([], PuttyHostKey::argumentsFor('plink.exe', 'user@example.test'));
    }
}
