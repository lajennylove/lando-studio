<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\StepLogActivity;
use PHPUnit\Framework\TestCase;

class StepLogActivityTest extends TestCase
{
    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = sys_get_temp_dir().DIRECTORY_SEPARATOR.'step-log-'.uniqid().'.log';
    }

    protected function tearDown(): void
    {
        foreach ([$this->log, $this->log.StepLogActivity::HEARTBEAT_SUFFIX] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_activity_is_zero_when_neither_file_exists(): void
    {
        $this->assertSame(0, StepLogActivity::lastActivity($this->log));
    }

    public function test_activity_is_the_log_time_without_a_heartbeat_file(): void
    {
        file_put_contents($this->log, 'x');
        touch($this->log, 1_700_000_000);

        $this->assertSame(1_700_000_000, StepLogActivity::lastActivity($this->log));
    }

    public function test_a_newer_heartbeat_file_keeps_a_silent_step_alive(): void
    {
        file_put_contents($this->log, 'x');
        touch($this->log, 1_700_000_000);
        file_put_contents($this->log.StepLogActivity::HEARTBEAT_SUFFIX, 'x');
        touch($this->log.StepLogActivity::HEARTBEAT_SUFFIX, 1_700_000_500);

        $this->assertSame(1_700_000_500, StepLogActivity::lastActivity($this->log));
    }

    public function test_an_older_heartbeat_file_does_not_hide_newer_log_output(): void
    {
        file_put_contents($this->log, 'x');
        touch($this->log, 1_700_000_900);
        file_put_contents($this->log.StepLogActivity::HEARTBEAT_SUFFIX, 'x');
        touch($this->log.StepLogActivity::HEARTBEAT_SUFFIX, 1_700_000_100);

        $this->assertSame(1_700_000_900, StepLogActivity::lastActivity($this->log));
    }
}
