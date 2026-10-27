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
            if (isset($wh['enabled']) && !$wh['enabled']) return null;

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
                            'from' => $found['from'] ?? null,
                            'to' => $found['to'] ?? null,
                        ];
                    } else {
                        $days[] = ['day' => $dayName, 'active' => false, 'from' => '09:00', 'to' => '17:00'];
                    }
                }
                return $this->validatedSchedule($tz, $days);
            }

            // Simple from/to format (same schedule every day)
            if (isset($wh['from']) && isset($wh['to'])) {
                $days = array_map(fn($dayName) => [
                    'day' => $dayName,
                    'active' => true,
                    'from' => $wh['from'],
                    'to' => $wh['to'],
                ], $dayNames);
                return $this->validatedSchedule($tz, $days);
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
                            'from' => $dayCfg['from'] ?? null,
                            'to' => $dayCfg['to'] ?? null,
                        ];
                    } else {
                        $days[] = ['day' => $dayName, 'active' => false, 'from' => '09:00', 'to' => '17:00'];
                    }
                }
                return $this->validatedSchedule($tz, $days);
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function validatedSchedule(string $timezone, array $days): ?array
    {
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            return null;
        }
        foreach ($days as $day) {
            if ($day['active'] && ($this->parseScheduleTime($day['from'], false) === null || $this->parseScheduleTime($day['to'], true) === null)) return null;
        }
        return ['timezone' => $timezone, 'days' => $days];
    }

    public function isWithinWorkingHours($organizationId): bool
    {
        $schedule = $this->getSchedule($organizationId);
        if (!$schedule) return true; // Invalid or disabled: allow the normal greeting.

        $now = Carbon::now($schedule['timezone']);
        $today = $now->format('l');
        $yesterday = $now->copy()->subDay()->format('l');
        $seconds = $now->hour * 3600 + $now->minute * 60 + $now->second;
        foreach ($schedule['days'] as $day) {
            if (!$day['active']) continue;
            $from = $this->parseScheduleTime($day['from'], false);
            $to = $this->parseScheduleTime($day['to'], true);
            if ($day['day'] === $today && ($from <= $to ? ($seconds >= $from && $seconds <= $to) : $seconds >= $from)) return true;
            if ($day['day'] === $yesterday && $from > $to && $seconds <= $to) return true;
        }
        return false;
    }

    /**
     * Compare a local time with an inclusive minute-based schedule.
     *
     * A configured end such as 23:59 represents the complete minute and is
     * therefore treated as 23:59:59. Explicit HH:MM:SS values retain their
     * provided seconds. Overnight ranges are supported as before.
     */
    private function isTimeWithinRange(Carbon $now, $from, $to): ?bool
    {
        $fromSeconds = $this->parseScheduleTime($from, false);
        $toSeconds = $this->parseScheduleTime($to, true);

        if ($fromSeconds === null || $toSeconds === null) {
            return null;
        }

        $currentSeconds = ($now->hour * 3600) + ($now->minute * 60) + $now->second;

        if ($fromSeconds <= $toSeconds) {
            return $currentSeconds >= $fromSeconds && $currentSeconds <= $toSeconds;
        }

        return $currentSeconds >= $fromSeconds || $currentSeconds <= $toSeconds;
    }

    private function parseScheduleTime($value, bool $endOfMinute): ?int
    {
        if (! is_string($value) || ! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($value), $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        $second = isset($matches[3]) ? (int) $matches[3] : ($endOfMinute ? 59 : 0);

        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return ($hour * 3600) + ($minute * 60) + $second;
    }

    private function isOvernightRange(string $from, string $to): bool
    {
        return $this->parseScheduleTime($from, false) > $this->parseScheduleTime($to, true);
    }

}
