<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Models\Organization;
use Carbon\Carbon;

class WorkingHoursService
{
    /**
     * Determine whether current time is within the organization's configured working hours.
     *
     * Supports formats in organization metadata:
     * - working_hours: { enabled:1, timezone:'Africa/Cairo', from:'09:00', to:'17:00' }
     * - working_hours: { enabled:1, timezone:'..', days: { mon: {from:'09:00',to:'17:00'}, ... } }
     *
     * If no working_hours config is present or enabled flag is missing, this returns true
     * (auto-reply allowed). This avoids accidentally blocking behavior for orgs without config.
     *
     * @return bool
     */
    /**
     * Get the working hours schedule for an organization as a normalized array of day entries.
     * Returns ['timezone' => string, 'days' => [{day, active, from, to}, ...]]
     * If working hours are not configured, returns null.
     */
    public function getSchedule($organizationId): ?array
    {
        try {
            $organization = Organization::find($organizationId);
            if (! $organization) {
                return null;
            }

            $metadata = $organization->metadata ? json_decode($organization->metadata, true) : [];

            // Check if working hours feature is enabled
            if (array_key_exists('enable_working_hours', $metadata) && ! (int) $metadata['enable_working_hours']) {
                return null;
            }

            $wh = $metadata['working_hours'] ?? null;
            if (! $wh) {
                return null;
            }

            $tz = $wh['timezone'] ?? $metadata['timezone'] ?? config('app.timezone') ?? 'UTC';

            $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

            // Array of day objects format
            if (is_array($wh) && array_values($wh) === $wh && isset($wh[0]) && is_array($wh[0]) && isset($wh[0]['day'])) {
                $days = [];
                foreach ($dayNames as $dayName) {
                    $found = null;
                    foreach ($wh as $entry) {
                        if (! is_array($entry)) continue;
                        $entryDay = $entry['day'] ?? ($entry['name'] ?? null);
                        if ($entryDay && strcasecmp(trim($entryDay), $dayName) === 0) {
                            $found = $entry;
                            break;
                        }
                    }
                    if ($found) {
                        $days[] = [
                            'day' => $dayName,
                            'active' => (bool) ($found['active'] ?? true),
                            'from' => $found['from'] ?? '09:00',
                            'to' => $found['to'] ?? '17:00',
                        ];
                    } else {
                        $days[] = ['day' => $dayName, 'active' => false, 'from' => '09:00', 'to' => '17:00'];
                    }
                }
                return ['timezone' => $tz, 'days' => $days];
            }

            // Simple from/to format (same schedule every day)
            if (isset($wh['from']) && isset($wh['to'])) {
                $days = array_map(fn($dayName) => [
                    'day' => $dayName,
                    'active' => true,
                    'from' => $wh['from'],
                    'to' => $wh['to'],
                ], $dayNames);
                return ['timezone' => $tz, 'days' => $days];
            }

            // Per-day map format
            if (isset($wh['days']) && is_array($wh['days'])) {
                $dayMap = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
                $days = [];
                foreach ($dayNames as $dayName) {
                    $shortKey = array_search($dayName, $dayMap);
                    $dayCfg = $wh['days'][$shortKey] ?? null;
                    if ($dayCfg) {
                        $days[] = [
                            'day' => $dayName,
                            'active' => true,
                            'from' => $dayCfg['from'] ?? '09:00',
                            'to' => $dayCfg['to'] ?? '17:00',
                        ];
                    } else {
                        $days[] = ['day' => $dayName, 'active' => false, 'from' => '09:00', 'to' => '17:00'];
                    }
                }
                return ['timezone' => $tz, 'days' => $days];
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function isWithinWorkingHours($organizationId): bool
    {
        try {
            $organization = Organization::find($organizationId);
            if (! $organization) {
                Log::channel('webhook')->info('Working hours check - organization not found, allowing auto-reply', [
                    'organization_id' => $organizationId,
                ]);
                return true;
            }

            $metadata = $organization->metadata ? json_decode($organization->metadata, true) : [];
            $wh = $metadata['working_hours'] ?? null;

            // Support two shapes:
            // 1) legacy: working_hours => ['from' => '09:00', 'to' => '17:00'] or 'days' map
            // 2) your format: enable_working_hours (bool) + working_hours as array of days

            // If the organization explicitly disables the feature via enable_working_hours => allow
            if (array_key_exists('enable_working_hours', $metadata) && ! (int) $metadata['enable_working_hours']) {
                Log::channel('webhook')->info('Working hours check - enable_working_hours disabled, allowing auto-reply', [
                    'organization_id' => $organizationId,
                ]);
                return true;
            }

            // If your format is present (array of day objects), normalize to per-day lookup
            $workingHoursDayArray = null;
            if (is_array($wh) && array_values($wh) === $wh && isset($wh[0]) && is_array($wh[0]) && isset($wh[0]['day'])) {
                // Example: working_hours: [ { day: 'Monday', active: true, from:'09:00', to:'17:00' }, ... ]
                $workingHoursDayArray = $wh;
            }

            // If nothing relevant configured -> allow auto-reply (backwards compatible)
            if (! $wh && ! $workingHoursDayArray) {
                Log::channel('webhook')->info('Working hours check - no working_hours configured, allowing auto-reply', [
                    'organization_id' => $organizationId,
                ]);
                return true;
            }

            // Determine timezone
            $tz = $wh['timezone'] ?? $metadata['timezone'] ?? config('app.timezone') ?? 'UTC';
            $now = Carbon::now($tz);
            $currentMinutes = (int)$now->format('G') * 60 + (int)$now->format('i');

            // Helper to parse HH:MM to minutes
            $toMinutes = function ($timeStr) {
                if (! $timeStr || ! is_string($timeStr)) return null;
                $parts = explode(':', $timeStr);
                if (count($parts) < 2) return null;
                $h = intval($parts[0]);
                $m = intval($parts[1]);
                return $h * 60 + $m;
            };

            // Case 1: simple from/to keys (same every day)
            if (isset($wh['from']) && isset($wh['to'])) {
                $fromMin = $toMinutes($wh['from']);
                $toMin = $toMinutes($wh['to']);
                if ($fromMin === null || $toMin === null) {
                    Log::channel('webhook')->info('Working hours check - invalid from/to format, allowing auto-reply', [
                        'organization_id' => $organizationId,
                        'from' => $wh['from'] ?? null,
                        'to' => $wh['to'] ?? null,
                    ]);
                    return true;
                }

                if ($fromMin <= $toMin) {
                    $inside = $currentMinutes >= $fromMin && $currentMinutes < $toMin;
                    Log::channel('webhook')->info('Working hours check', [
                        'organization_id' => $organizationId,
                        'timezone' => $tz,
                        'now' => $now->toIso8601String(),
                        'from' => $wh['from'],
                        'to' => $wh['to'],
                        'inside' => $inside,
                    ]);
                    return $inside;
                }
                // overnight range (e.g. 22:00 - 06:00)
                $inside = $currentMinutes >= $fromMin || $currentMinutes < $toMin;
                Log::channel('webhook')->info('Working hours check (overnight)', [
                    'organization_id' => $organizationId,
                    'timezone' => $tz,
                    'now' => $now->toIso8601String(),
                    'from' => $wh['from'],
                    'to' => $wh['to'],
                    'inside' => $inside,
                ]);
                return $inside;
            }

            // Case 1b: your format - working_hours is an array of day objects
            if ($workingHoursDayArray !== null) {
                $todayName = $now->format('l'); // e.g. Monday
                $found = null;
                foreach ($workingHoursDayArray as $entry) {
                    if (! is_array($entry)) continue;
                    $entryDay = $entry['day'] ?? ($entry['name'] ?? null);
                    if (! $entryDay) continue;
                    if (strcasecmp(trim($entryDay), $todayName) === 0) {
                        $found = $entry;
                        break;
                    }
                }

                if (! $found) {
                    Log::channel('webhook')->info('Working hours check - no entry for today in working_hours array, outside working hours', [
                        'organization_id' => $organizationId,
                        'today' => $todayName,
                    ]);
                    return false;
                }

                // Check active flag for the day
                if (array_key_exists('active', $found) && ! (int) $found['active']) {
                    Log::channel('webhook')->info('Working hours check - today marked inactive in working_hours array, outside working hours', [
                        'organization_id' => $organizationId,
                        'today' => $todayName,
                    ]);
                    return false;
                }

                $fromMin = $toMinutes($found['from'] ?? null);
                $toMin = $toMinutes($found['to'] ?? null);
                if ($fromMin === null || $toMin === null) {
                    Log::channel('webhook')->info('Working hours check - invalid from/to in working_hours entry for today, outside working hours', [
                        'organization_id' => $organizationId,
                        'today' => $todayName,
                        'entry' => $found,
                    ]);
                    return false;
                }

                if ($fromMin <= $toMin) {
                    $inside = $currentMinutes >= $fromMin && $currentMinutes < $toMin;
                    Log::channel('webhook')->info('Working hours check (array-of-days)', [
                        'organization_id' => $organizationId,
                        'timezone' => $tz,
                        'now' => $now->toIso8601String(),
                        'today' => $todayName,
                        'from' => $found['from'],
                        'to' => $found['to'],
                        'inside' => $inside,
                    ]);
                    return $inside;
                }

                $inside = $currentMinutes >= $fromMin || $currentMinutes < $toMin;
                Log::channel('webhook')->info('Working hours check (array-of-days overnight)', [
                    'organization_id' => $organizationId,
                    'timezone' => $tz,
                    'now' => $now->toIso8601String(),
                    'today' => $todayName,
                    'from' => $found['from'],
                    'to' => $found['to'],
                    'inside' => $inside,
                ]);
                return $inside;
            }

            // Case 2: per-day configuration
            if (isset($wh['days']) && is_array($wh['days'])) {
                $dayMap = [
                    0 => 'sun', 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat'
                ];
                $dayKey = $dayMap[(int)$now->dayOfWeek] ?? null;
                $dayCfg = $dayKey && isset($wh['days'][$dayKey]) ? $wh['days'][$dayKey] : null;

                if (! $dayCfg) {
                    // If no config for today, assume not working -> disallow
                    Log::channel('webhook')->info('Working hours check - no day configuration for today, outside working hours', [
                        'organization_id' => $organizationId,
                        'day' => $dayKey,
                    ]);
                    return false;
                }

                $fromMin = $toMinutes($dayCfg['from'] ?? null);
                $toMin = $toMinutes($dayCfg['to'] ?? null);
                if ($fromMin === null || $toMin === null) {
                    Log::channel('webhook')->info('Working hours check - invalid day from/to format, outside working hours', [
                        'organization_id' => $organizationId,
                        'day' => $dayKey,
                        'from' => $dayCfg['from'] ?? null,
                        'to' => $dayCfg['to'] ?? null,
                    ]);
                    return false;
                }

                if ($fromMin <= $toMin) {
                    $inside = $currentMinutes >= $fromMin && $currentMinutes < $toMin;
                    Log::channel('webhook')->info('Working hours check (per-day)', [
                        'organization_id' => $organizationId,
                        'timezone' => $tz,
                        'now' => $now->toIso8601String(),
                        'day' => $dayKey,
                        'from' => $dayCfg['from'],
                        'to' => $dayCfg['to'],
                        'inside' => $inside,
                    ]);
                    return $inside;
                }
                $inside = $currentMinutes >= $fromMin || $currentMinutes < $toMin;
                Log::channel('webhook')->info('Working hours check (per-day overnight)', [
                    'organization_id' => $organizationId,
                    'timezone' => $tz,
                    'now' => $now->toIso8601String(),
                    'day' => $dayKey,
                    'from' => $dayCfg['from'],
                    'to' => $dayCfg['to'],
                    'inside' => $inside,
                ]);
                return $inside;
            }

            // Unknown format -> allow
            return true;
        } catch (\Throwable $e) {
            // On error, don't block auto-reply
            Log::channel('webhook')->warning('isWithinWorkingHours check failed, allowing auto-reply', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

}