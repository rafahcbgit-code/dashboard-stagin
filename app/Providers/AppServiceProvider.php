<?php

namespace App\Providers;

use App\Services\WooCommerce\Contracts\WooCommerceIntegrationRepositoryInterface;
use App\Services\WooCommerce\Repositories\Eloquent\WooCommerceIntegrationRepository;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\TelescopeServiceProvider;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            WooCommerceIntegrationRepositoryInterface::class,
            WooCommerceIntegrationRepository::class
        );

        if ($this->app->environment('local')) {
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
        
        if (!\App::environment('local')) {
            \URL::forceScheme('https');
        }
        
        Inertia::share([
        'contact_phone' => fn () => request()->get('contact_phone'),
        'whatsapp_link' => fn () => request()->get('whatsapp_link'),
        'calendly_url' => fn () => request()->get('calendly_url'),
        'is_trial' => fn () => request()->get('is_trial'),
        ]);
    }
}
