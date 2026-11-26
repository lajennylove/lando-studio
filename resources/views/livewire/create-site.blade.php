<div>
    <flux:heading size="xl">Create Site</flux:heading>
    <flux:subheading class="mt-1">Choose how you want to create your site</flux:subheading>

    <div class="mt-8">
        <flux:heading size="lg">WordPress Sites</flux:heading>
        <div class="mt-4 grid grid-cols-2 gap-6 w-full max-w-none">
            <a href="{{ route('create.new', [], false) }}" wire:navigate
               class="flex flex-col items-center justify-center gap-4 p-8 rounded-xl border-2 border-zinc-200 dark:border-zinc-700 hover:border-blue-500 dark:hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-all cursor-pointer group">
                <div class="w-16 h-16 rounded-full bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center group-hover:bg-blue-200 dark:group-hover:bg-blue-800/50 transition-colors">
                    <flux:icon name="plus" class="w-8 h-8 text-blue-600" />
                </div>
                <div class="text-center">
                    <flux:heading size="lg">New Website</flux:heading>
                    <flux:text class="mt-1 text-zinc-500">Create a fresh WordPress site with Sage theme</flux:text>
                </div>
            </a>

            <a href="{{ route('create.clone', [], false) }}" wire:navigate
               class="flex flex-col items-center justify-center gap-4 p-8 rounded-xl border-2 border-zinc-200 dark:border-zinc-700 hover:border-amber-500 dark:hover:border-amber-500 hover:bg-amber-50 dark:hover:bg-amber-900/20 transition-all cursor-pointer group">
                <div class="w-16 h-16 rounded-full bg-amber-100 dark:bg-amber-900/50 flex items-center justify-center group-hover:bg-amber-200 dark:group-hover:bg-amber-800/50 transition-colors">
                    <flux:icon name="arrow-down-tray" class="w-8 h-8 text-amber-600" />
                </div>
                <div class="text-center">
                    <flux:heading size="lg">Clone Existing</flux:heading>
                    <flux:text class="mt-1 text-zinc-500">Clone from a production WordPress site</flux:text>
                </div>
            </a>
        </div>
    </div>

    <flux:separator class="my-10" />

    <div>
        <flux:heading size="lg">WP Headless Sites / Astro</flux:heading>
        <flux:subheading class="mt-1">A WordPress site with an Astro front end, cloned or created from its repositories</flux:subheading>
        <div class="mt-4 grid grid-cols-2 gap-6 w-full max-w-none">
            {{-- Create New needs the Astro template repository, which is not set up yet. --}}
            <div aria-disabled="true"
                 class="flex flex-col items-center justify-center gap-4 p-8 rounded-xl border-2 border-dashed border-zinc-200 dark:border-zinc-700 opacity-60 cursor-not-allowed">
                <div class="w-16 h-16 rounded-full bg-violet-100 dark:bg-violet-900/50 flex items-center justify-center">
                    <flux:icon name="plus" class="w-8 h-8 text-violet-600" />
                </div>
                <div class="text-center">
                    <flux:heading size="lg">Create New</flux:heading>
                    <flux:text class="mt-1 text-zinc-500">Not available yet: needs the Astro template repository</flux:text>
                </div>
            </div>

            <a href="{{ route('create.clone', [], false) }}" wire:navigate
               class="flex flex-col items-center justify-center gap-4 p-8 rounded-xl border-2 border-zinc-200 dark:border-zinc-700 hover:border-violet-500 dark:hover:border-violet-500 hover:bg-violet-50 dark:hover:bg-violet-900/20 transition-all cursor-pointer group">
                <div class="w-16 h-16 rounded-full bg-violet-100 dark:bg-violet-900/50 flex items-center justify-center group-hover:bg-violet-200 dark:group-hover:bg-violet-800/50 transition-colors">
                    <flux:icon name="arrow-down-tray" class="w-8 h-8 text-violet-600" />
                </div>
                <div class="text-center">
                    <flux:heading size="lg">Clone Site</flux:heading>
                    <flux:text class="mt-1 text-zinc-500">Clone a production site with its Astro project (the preset must have an Astro repository)</flux:text>
                </div>
            </a>
        </div>
    </div>
</div>
