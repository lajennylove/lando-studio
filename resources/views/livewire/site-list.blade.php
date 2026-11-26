<div wire:poll.keep-alive.15s="syncStatuses">
    @if($sites->isEmpty())
        <div class="px-3 py-4 text-center">
            <flux:text class="text-sm text-zinc-400">No sites yet</flux:text>
        </div>
    @else
        {{-- One group per site type, in the order of SiteType. A gap separates the groups. --}}
        @php
            $groups = collect(\App\Enums\SiteType::cases())
                ->map(fn ($type) => $sites->filter(fn ($site) => $site->site_type === $type)->values())
                ->filter(fn ($group) => $group->isNotEmpty());
        @endphp

        @foreach($groups as $group)
            <flux:navlist variant="outline" class="mb-6 last:mb-0">
                @foreach($group as $site)
                    <flux:navlist.item
                        :href="route('sites.show', $site, false)"
                        :current="request()->routeIs('sites.show') && request()->route('site')?->id === $site->id"
                        wire:navigate
                        class="relative"
                    >
                        {{-- Status as a square vertical stripe along the left edge, the full height of the row. --}}
                        <span class="absolute left-0 top-0 bottom-0 w-1 {{ match($site->status->value ?? $site->status) {
                            'running' => 'bg-green-500',
                            'creating' => 'bg-blue-500 animate-pulse',
                            'error' => 'bg-red-500',
                            'stopped' => 'bg-red-400',
                            default => 'bg-zinc-400',
                        } }}" title="{{ $site->status->value ?? $site->status }}"></span>
                        <div class="flex items-center gap-2">
                            @php
                                $tech = $site->site_type === \App\Enums\SiteType::Astro ? 'astro' : 'wordpress';
                                $techLabel = $site->site_type?->label() ?? 'WordPress';
                            @endphp
                            {{-- Light theme shows the light icon, dark theme the dark one (the .dark class, see app.css). --}}
                            <span class="shrink-0 w-5 h-5" title="{{ $techLabel }}">
                                <img src="{{ asset("tech-icons/{$tech}-light.svg") }}" alt="{{ $techLabel }}" width="20" height="20" class="w-5 h-5 dark:hidden">
                                <img src="{{ asset("tech-icons/{$tech}-dark.svg") }}" alt="{{ $techLabel }}" width="20" height="20" class="w-5 h-5 hidden dark:block">
                            </span>
                            <span class="truncate">{{ $site->name }}</span>
                        </div>
                    </flux:navlist.item>
                @endforeach
            </flux:navlist>
        @endforeach
    @endif
</div>
