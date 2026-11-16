<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A terminal checks in every 10 seconds and uploads in bursts; this only stops floods.
        \Illuminate\Support\Facades\RateLimiter::for('adms', fn (\Illuminate\Http\Request $request) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinute(300)->by($request->ip()));
    }
}
