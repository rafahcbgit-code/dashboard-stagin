<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // Force test database configuration after Laravel bootstraps
        // This ensures .env values don't override test settings
        $app['config']->set('database.connections.mysql.database', 'chatberry_dashboard_test');

        // CRITICAL: Purge existing connection and force reconnection with new config
        // Without this, Laravel may use a cached connection to the wrong database
        DB::purge('mysql');
        DB::reconnect('mysql');

        return $app;
    }
}
