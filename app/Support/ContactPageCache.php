<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class ContactPageCache
{
    public static function contactsCountKey(int $organizationId): string
    {
        return "organization:{$organizationId}:contacts_count";
    }

    public static function contactGroupsKey(int $organizationId): string
    {
        return "organization:{$organizationId}:contacts_page_contact_groups";
    }

    public static function labelsKey(int $organizationId): string
    {
        return "organization:{$organizationId}:contacts_page_labels";
    }

    public static function forgetContactsCount(int $organizationId): void
    {
        Cache::forget(self::contactsCountKey($organizationId));
    }

    public static function forgetContactGroups(int $organizationId): void
    {
        Cache::forget(self::contactGroupsKey($organizationId));
    }

    public static function forgetLabels(int $organizationId): void
    {
        Cache::forget(self::labelsKey($organizationId));
    }
}
