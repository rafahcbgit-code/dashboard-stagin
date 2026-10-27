<?php 

namespace App\Helpers;

use App\Models\Organization;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class DateTimeHelper
{
    public static function formatDate(string $dateTimeString)
    {
        $dt = Carbon::create($dateTimeString);
        $dateFormat = Setting::where('key', '=', 'date_format')->first()->value;
        $timeFormat = Setting::where('key', '=', 'time_format')->first()->value;

        return $dt->format($dateFormat . ' ' . $timeFormat); 
    }

    /** Format a UTC timestamp using the platform's configured timezone. */
    public static function formatCompanyDate($date): string
    {
        $settings = Setting::whereIn('key', ['date_format', 'time_format', 'timezone'])
            ->pluck('value', 'key');

        $dateFormat = $settings['date_format'] ?? 'd-M-y';
        $timeFormat = $settings['time_format'] ?? 'H:i';
        $timezone = $settings['timezone'] ?? 'UTC';

        return Carbon::parse($date, 'UTC')
            ->setTimezone($timezone)
            ->format($dateFormat . ' ' . $timeFormat);
    }

    public static function convertToOrganizationTimezone($date)
    {
        $timezone = 'UTC'; // Default to UTC
        $organizationId = session()->get('current_organization');

        if ($organizationId) {
            $organization = Organization::find($organizationId);
            if ($organization) {
                $metadata = $organization->metadata;
                $metadata = isset($metadata) ? json_decode($metadata, true) : null;

                if ($metadata && isset($metadata['timezone'])) {
                    $timezone = $metadata['timezone'];
                }
            }
        }

        return Carbon::parse($date)->setTimezone($timezone);
    }

    public static function convertToCompanyTimezone($date)
    {
        $timezone = Setting::where('key', 'timezone')->value('value') ?? 'UTC';

        return Carbon::parse($date)->setTimezone($timezone);
    }

    public static function formatDateWithoutHours($date)
    {
        return $date->format('d M Y'); // Format without hours, minutes, and seconds
    }
}

