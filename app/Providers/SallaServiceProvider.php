<?php

namespace App\Providers;

use App\Services\SallaTokenService;
use Illuminate\Support\ServiceProvider;

class SallaServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register SallaTokenService as singleton
        // هذا يضمن أن نفس الـ instance يتم استخدامها خلال request واحد
        $this->app->singleton(SallaTokenService::class, function ($app) {
            return new SallaTokenService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
