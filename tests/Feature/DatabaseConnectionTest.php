<?php

/**
 * Diagnostic test to verify which database is being used.
 */

it('shows which database is actually being used', function () {
    // Check env() value
    $envDb = env('DB_DATABASE');

    // Check config value
    $configDb = config('database.connections.mysql.database');

    // Check actual PDO connection
    $pdo = \DB::connection()->getPdo();
    $actualDb = $pdo->query('SELECT DATABASE()')->fetchColumn();

    dump([
        'env(DB_DATABASE)' => $envDb,
        'config(database.connections.mysql.database)' => $configDb,
        'SELECT DATABASE()' => $actualDb,
    ]);

    // This assertion should pass if using test database
    expect($actualDb)->toBe('chatberry_dashboard_test');
});
