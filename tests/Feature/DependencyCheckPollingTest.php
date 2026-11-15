<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\DependencyCheck;
use App\Services\DependencyChecker;
use App\Services\PlatformDetector;
use Livewire\Livewire;
use Tests\TestCase;

class DependencyCheckPollingTest extends TestCase
{
    /** A checker whose Docker answer is false on the first call and true after that, as after an install. */
    private function dockerAppearsLater(): void
    {
        $this->app->instance(DependencyChecker::class, new class(app(PlatformDetector::class)) extends DependencyChecker
        {
            private int $dockerCalls = 0;

            public function isDockerInstalled(): bool
            {
                return ++$this->dockerCalls > 1;
            }

            public function isLandoInstalled(): bool
            {
                return true;
            }
        });
    }

    public function test_idle_poll_picks_up_a_dependency_that_became_installed(): void
    {
        $this->dockerAppearsLater();

        $component = Livewire::test(DependencyCheck::class);
        $this->assertFalse($component->get('dependencies')['docker']['installed']);

        $component->call('pollInstallStatus');

        $this->assertTrue($component->get('dependencies')['docker']['installed']);
    }

    public function test_page_polls_when_idle_and_keeps_polling_in_a_background_window(): void
    {
        $this->dockerAppearsLater();

        Livewire::test(DependencyCheck::class)
            ->assertSeeHtml('wire:poll.keep-alive.5s="pollInstallStatus"');
    }
}
