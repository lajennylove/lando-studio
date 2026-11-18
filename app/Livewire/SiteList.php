<?php

namespace App\Livewire;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\LandoService;
use App\Support\LandoContainer;
use Livewire\Attributes\On;
use Livewire\Component;

class SiteList extends Component
{
    #[On('site-created')]
    #[On('site-deleted')]
    #[On('site-status-changed')]
    #[On('application-data-reset')]
    public function refresh(): void
    {
        // Livewire will re-render automatically
    }

    /**
     * Bring every site's stored status in line with Docker, so the dots stay right when a site is stopped or
     * started outside the app, after a reboot, or while another page is open.
     *
     * One `lando list` call covers all sites. Sites being created or in error are left alone (the clone runner owns
     * those), and nothing changes when lando list gave no answer.
     */
    public function syncStatuses(): void
    {
        $states = app(LandoService::class)->appRunningStates();
        if ($states === null) {
            return;
        }

        foreach (Site::whereNotIn('status', [SiteStatus::Creating, SiteStatus::Error])->get() as $site) {
            if (! is_dir($site->path)) {
                continue;
            }

            $status = ($states[LandoContainer::appName($site->name)] ?? false) ? SiteStatus::Running : SiteStatus::Stopped;
            if ($site->status !== $status) {
                $site->update(['status' => $status]);
            }
        }
    }

    public function render()
    {
        return view('livewire.site-list', [
            'sites' => Site::orderBy('name')->get(),
        ]);
    }
}
