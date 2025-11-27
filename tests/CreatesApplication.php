<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

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

        return $app;
    }
}
