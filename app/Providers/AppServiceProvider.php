<?php

namespace App\Providers;

use App\Support\PersistentAppKey;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Builds ship without APP_KEY. Use the key kept in the user's storage folder, so encrypted
        // passwords survive rebuilds. Development keeps the key from .env.
        if (blank(config('app.key'))) {
            config(['app.key' => PersistentAppKey::resolve(storage_path('app-key'))]);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Clone / Lando progress can exceed Livewire’s default 1MB JSON snapshot limit.
        config(['livewire.payload.max_size' => 4 * 1024 * 1024]);
    }
}
