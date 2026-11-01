<?php

use App\Services\ReportService;

uses(Tests\TestCase::class);

function effectiveReportSeconds(string $from, string $to, array $config): int
{
    $service = (new ReflectionClass(ReportService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ReportService::class, 'calculateEffectiveSeconds');
    return $method->invoke($service, $from, $to, $config);
}

it('counts only working time in the organization timezone across a weekend', function () {
    $config = ['enable_working_hours' => true, 'timezone' => 'Asia/Damascus', 'working_hours' => [
        ['day' => 'Friday', 'active' => true, 'from' => '09:00', 'to' => '17:00'],
        ['day' => 'Monday', 'active' => true, 'from' => '09:00', 'to' => '17:00'],
    ]];
    // UTC 13:30 is 16:30 locally; UTC 06:30 Monday is 09:30 locally.
    expect(effectiveReportSeconds('2026-10-23 13:30:00', '2026-10-26 06:30:00', $config))->toBe(3600);
});

it('counts the portion of an overnight shift on the following day', function () {
    $config = ['enable_working_hours' => true, 'timezone' => 'UTC', 'working_hours' => [
        ['day' => 'Monday', 'active' => true, 'from' => '22:00', 'to' => '06:00'],
    ]];
    expect(effectiveReportSeconds('2026-10-26 23:00:00', '2026-10-27 07:00:00', $config))->toBe(25200);
});
