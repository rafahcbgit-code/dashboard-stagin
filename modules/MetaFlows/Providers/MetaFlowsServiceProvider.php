<?php

namespace Modules\MetaFlows\Providers;

use Illuminate\Support\ServiceProvider;

class MetaFlowsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->register(RouteServiceProvider::class);

        // Load migrations directly from the module directory
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
