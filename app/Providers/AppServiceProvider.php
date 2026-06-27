<?php

namespace App\Providers;

use App\Models\ResourceAddon;
use App\Models\Subscription;
use App\Observers\ResourceAddonObserver;
use App\Observers\SubscriptionObserver;
use App\Services\WooCommerce\Contracts\WooCommerceIntegrationRepositoryInterface;
use App\Services\WooCommerce\Repositories\Eloquent\WooCommerceIntegrationRepository;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
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

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        Subscription::observe(SubscriptionObserver::class);
        ResourceAddon::observe(ResourceAddonObserver::class);
        
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
