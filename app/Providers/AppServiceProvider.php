<?php

namespace App\Providers;

use App\Services\Payments\PaymentEnvironment;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton so the machine signals are read (and memoised) once per
        // process — every payment path must see the same verdict.
        $this->app->singleton(PaymentEnvironment::class, fn () => new PaymentEnvironment);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
