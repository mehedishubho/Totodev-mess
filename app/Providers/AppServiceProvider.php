<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;

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
        // Set the default URL for the application
        URL::forceScheme('https');

        // Set the application locale
        app()->setLocale(config('app.locale', 'en'));

        // Share variables with all views
        View::share([
            'appName' => config('app.name'),
            'appVersion' => '1.0.0',
        ]);
    }
}
