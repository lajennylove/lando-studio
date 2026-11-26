<div wire:poll.keep-alive.15s="syncStatuses">
    @if($sites->isEmpty())
        <div class="px-3 py-4 text-center">
            <flux:text class="text-sm text-zinc-400">No sites yet</flux:text>
        </div>
    @else
        <flux:navlist variant="outline">
            @foreach($sites as $site)
                <flux:navlist.item
                    :href="route('sites.show', $site, false)"
                    :current="request()->routeIs('sites.show') && request()->route('site')?->id === $site->id"
                    wire:navigate
                >
                    <div class="flex items-center gap-2">
                        <span class="inline-block w-2 h-2 rounded-full {{ match($site->status->value ?? $site->status) {
                            'running' => 'bg-green-500',
                            'creating' => 'bg-blue-500 animate-pulse',
                            'error' => 'bg-red-500',
                            'stopped' => 'bg-red-400',
                            default => 'bg-zinc-400',
                        } }}"></span>
                        @php
                            $tech = $site->site_type === \App\Enums\SiteType::Astro ? 'astro' : 'wordpress';
                            $techLabel = $site->site_type?->label() ?? 'WordPress';
                        @endphp
                        {{-- Light theme shows the light icon, dark theme the dark one (the .dark class, see app.css). --}}
                        <span class="shrink-0 w-[35px] h-[35px]" title="{{ $techLabel }}">
                            <img src="{{ asset("tech-icons/{$tech}-light.svg") }}" alt="{{ $techLabel }}" width="35" height="35" class="w-[35px] h-[35px] dark:hidden">
                            <img src="{{ asset("tech-icons/{$tech}-dark.svg") }}" alt="{{ $techLabel }}" width="35" height="35" class="w-[35px] h-[35px] hidden dark:block">
                        </span>
                        <span class="truncate">{{ $site->name }}</span>
                    </div>
                </flux:navlist.item>
            @endforeach
        </flux:navlist>
    @endif
</div>
