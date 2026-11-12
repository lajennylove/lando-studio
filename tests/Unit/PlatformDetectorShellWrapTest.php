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
