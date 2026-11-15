<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Livewire\SiteList;
use App\Models\Site;
use App\Services\LandoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class SiteListStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'site-list-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function site(string $name, SiteStatus $status, ?string $path = null): Site
    {
        return Site::create(['name' => $name, 'path' => $path ?? $this->dir, 'status' => $status]);
    }

    /**
     * @param  array<string, bool>|null  $states
     */
    private function lando(?array $states): void
    {
        $lando = Mockery::mock(LandoService::class);
        $lando->shouldReceive('appRunningStates')->andReturn($states);
        $this->app->instance(LandoService::class, $lando);
    }

    public function test_sync_marks_sites_running_or_stopped_from_docker(): void
    {
        $this->site('up', SiteStatus::Stopped);
        $this->site('down', SiteStatus::Running);
        $this->lando(['up' => true, 'down' => false]);

        Livewire::test(SiteList::class)->call('syncStatuses');

        $this->assertSame(SiteStatus::Running, Site::where('name', 'up')->first()->status);
        $this->assertSame(SiteStatus::Stopped, Site::where('name', 'down')->first()->status);
    }

    public function test_a_site_missing_from_lando_list_is_stopped(): void
    {
        $this->site('gone', SiteStatus::Running);
        $this->lando([]);

        Livewire::test(SiteList::class)->call('syncStatuses');

        $this->assertSame(SiteStatus::Stopped, Site::where('name', 'gone')->first()->status);
    }

    public function test_sites_being_created_or_in_error_keep_their_status(): void
    {
        $this->site('building', SiteStatus::Creating);
        $this->site('broken', SiteStatus::Error);
        $this->lando(['building' => true, 'broken' => true]);

        Livewire::test(SiteList::class)->call('syncStatuses');

        $this->assertSame(SiteStatus::Creating, Site::where('name', 'building')->first()->status);
        $this->assertSame(SiteStatus::Error, Site::where('name', 'broken')->first()->status);
    }

    public function test_nothing_changes_when_lando_list_gives_no_answer(): void
    {
        $this->site('keeps', SiteStatus::Running);
        $this->lando(null);

        Livewire::test(SiteList::class)->call('syncStatuses');

        $this->assertSame(SiteStatus::Running, Site::where('name', 'keeps')->first()->status);
    }

    public function test_a_site_whose_folder_is_missing_is_left_alone(): void
    {
        $this->site('nofolder', SiteStatus::Running, $this->dir.DIRECTORY_SEPARATOR.'missing');
        $this->lando([]);

        Livewire::test(SiteList::class)->call('syncStatuses');

        $this->assertSame(SiteStatus::Running, Site::where('name', 'nofolder')->first()->status);
    }

    public function test_sidebar_polls_and_shows_stopped_sites_in_red(): void
    {
        $this->site('off', SiteStatus::Stopped);

        Livewire::test(SiteList::class)
            ->assertSeeHtml('wire:poll.keep-alive.15s="syncStatuses"')
            ->assertSeeHtml('bg-red-400');
    }
}
