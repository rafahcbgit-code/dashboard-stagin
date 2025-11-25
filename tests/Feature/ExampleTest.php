<?php

/**
 * Example Feature Tests
 *
 * Basic tests to verify Pest is working correctly.
 * Replace with actual feature tests as development progresses.
 */

it('has the helper functions available', function () {
    // Verify measureTime helper works
    $duration = measureTime(function () {
        usleep(10000); // 10ms
    });

    expect($duration)->toBeGreaterThan(5);
    expect($duration)->toBeLessThan(100);
});

it('can query the database', function () {
    // Simple database connectivity test
    $result = \DB::select('SELECT 1 as connected');

    expect($result)->toBeArray();
    expect($result[0]->connected)->toBe(1);
});

it('can use Pest expectations', function () {
    $value = 'chatberry';

    expect($value)
        ->toBeString()
        ->toContain('chat')
        ->toHaveLength(9);
});
