<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(
    Tests\TestCase::class,
    Illuminate\Foundation\Testing\RefreshDatabase::class,
)->in('Feature');

uses(Tests\TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

// Custom expectation for performance testing
expect()->extend('toRespondWithin', function (int $milliseconds) {
    return $this->toBeLessThan($milliseconds);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Measure execution time of a closure in milliseconds
 */
function measureTime(callable $callback): float
{
    $start = microtime(true);
    $callback();
    return (microtime(true) - $start) * 1000;
}

/**
 * Create a test organization with WhatsApp configuration
 */
function createTestOrganization(array $attributes = []): \App\Models\Organization
{
    return \App\Models\Organization::factory()->create(array_merge([
        'metadata' => [
            'whatsapp' => [
                'access_token' => 'test_token',
                'phone_number_id' => 'test_phone_id',
                'waba_id' => 'test_waba_id',
            ],
            'timezone' => 'UTC',
        ],
    ], $attributes));
}

/**
 * Get the query count for a closure
 */
function countQueries(callable $callback): int
{
    $count = 0;
    \DB::listen(function ($query) use (&$count) {
        $count++;
    });

    $callback();

    return $count;
}
