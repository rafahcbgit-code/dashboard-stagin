<?php

use App\Services\WorkingHoursService;
use Carbon\Carbon;

function isWithinConfiguredTime(string $now, string $from, string $to): ?bool
{
    $service = new WorkingHoursService();
    $method = new ReflectionMethod(WorkingHoursService::class, 'isTimeWithinRange');
    $method->setAccessible(true);

    return $method->invoke($service, Carbon::parse($now), $from, $to);
}

it('keeps the existing all-day schedule active through the final minute', function () {
    expect(isWithinConfiguredTime('2026-10-18 23:58:59', '00:00', '23:59'))->toBeTrue()
        ->and(isWithinConfiguredTime('2026-10-18 23:59:00', '00:00', '23:59'))->toBeTrue()
        ->and(isWithinConfiguredTime('2026-10-18 23:59:17', '00:00', '23:59'))->toBeTrue()
        ->and(isWithinConfiguredTime('2026-10-18 23:59:59', '00:00', '23:59'))->toBeTrue();
});

it('keeps normal ranges bounded to their configured minutes', function () {
    expect(isWithinConfiguredTime('2026-10-18 08:59:59', '09:00', '17:00'))->toBeFalse()
        ->and(isWithinConfiguredTime('2026-10-18 09:00:00', '09:00', '17:00'))->toBeTrue()
        ->and(isWithinConfiguredTime('2026-10-18 17:00:59', '09:00', '17:00'))->toBeFalse()
        ->and(isWithinConfiguredTime('2026-10-18 17:01:00', '09:00', '17:00'))->toBeFalse();
});

it('preserves overnight range behavior with an exclusive end', function () {
    expect(isWithinConfiguredTime('2026-10-18 21:59:59', '22:00', '06:00'))->toBeFalse()
        ->and(isWithinConfiguredTime('2026-10-18 22:00:00', '22:00', '06:00'))->toBeTrue()
        ->and(isWithinConfiguredTime('2026-10-19 06:00:59', '22:00', '06:00'))->toBeFalse()
        ->and(isWithinConfiguredTime('2026-10-19 06:01:00', '22:00', '06:00'))->toBeFalse();
});

it('honors explicit seconds and rejects invalid schedule values', function () {
    expect(isWithinConfiguredTime('2026-10-18 17:00:30', '09:00:00', '17:00:30'))->toBeFalse()
        ->and(isWithinConfiguredTime('2026-10-18 17:00:31', '09:00:00', '17:00:30'))->toBeFalse()
        ->and(isWithinConfiguredTime('2026-10-18 12:00:00', '25:00', '23:59'))->toBeNull();
});
