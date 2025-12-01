<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertNotProductionDatabase();
    }

    /**
     * Abort tests if accidentally connected to production database.
     */
    protected function assertNotProductionDatabase(): void
    {
        $currentDb = config('database.connections.mysql.database');
        $forbiddenDatabases = ['chatberry_dashboard', 'chatberry', 'production'];

        if (in_array($currentDb, $forbiddenDatabases, true)) {
            $this->fail(
                "SAFETY ABORT: Tests attempted to run against production database '{$currentDb}'. ".
                'Ensure phpunit.xml sets DB_DATABASE to a test database.'
            );
        }
    }
}
