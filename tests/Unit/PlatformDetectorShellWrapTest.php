<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PlatformDetector;
use Tests\TestCase;

class PlatformDetectorShellWrapTest extends TestCase
{
    public function test_powershell_steps_reload_path_from_the_registry_before_running(): void
    {
        $prefix = (new PlatformDetector)->powershellStepPrefix();

        $this->assertStringContainsString("GetEnvironmentVariable('Path', 'Machine')", $prefix);
        $this->assertStringContainsString("GetEnvironmentVariable('Path', 'User')", $prefix);
        $this->assertStringContainsString('UTF8', $prefix);
    }

    public function test_path_reload_joins_machine_and_user_path_with_a_string_not_member_access(): void
    {
        $prefix = (new PlatformDetector)->powershellPathPrefix();

        // `...('Machine').';'` is member access in PowerShell: null, so the machine PATH was lost.
        $this->assertStringNotContainsString(").';'", $prefix);
        $this->assertStringContainsString("'Machine') + ';' + ", $prefix);
    }

    public function test_windows_wrapper_writes_a_heartbeat_to_a_side_file_while_the_command_runs(): void
    {
        $windows = new class extends PlatformDetector
        {
            public function isWindows(): bool
            {
                return true;
            }
        };

        $wrapped = $windows->wrapCommandWithLogRedirect('lando composer install', 'C:\\logs\\step.log');

        // The *> redirect holds the log open, so the heartbeat goes to <log>.hb, not into the log itself.
        $this->assertStringContainsString('Start-Job', $wrapped);
        $this->assertStringContainsString("'C:\\logs\\step.log.hb'", $wrapped);
        $this->assertStringContainsString("& { lando composer install } *> 'C:\\logs\\step.log'", $wrapped);
        // The job is stopped and the side file removed before the completion marker is written.
        $this->assertLessThan(
            strpos($wrapped, 'Add-Content -Path'),
            strpos($wrapped, 'Stop-Job'),
        );
    }

    public function test_wrap_puts_log_redirect_outside_pipeline(): void
    {
        $p = new PlatformDetector;
        $log = '/tmp/lando-step.log';
        $inner = 'echo hello | gzip > /tmp/out.gz';

        $wrapped = $p->wrapCommandWithLogRedirect($inner, $log);

        $this->assertStringContainsString($inner, $wrapped);
        // Completion marker must be appended after the command
        $this->assertStringContainsString(PlatformDetector::STEP_DONE_MARKER, $wrapped);

        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertStringContainsString('*>', $wrapped);
            $this->assertStringContainsString('Add-Content', $wrapped);
        } else {
            $this->assertStringStartsWith('(', $wrapped);
            $this->assertStringContainsString(') > ', $wrapped);
            $this->assertStringContainsString('2>&1', $wrapped);
        }
    }
}
