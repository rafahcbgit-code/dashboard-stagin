<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ContactGroupCountService
{
    private static ?bool $hasContactsCountColumn = null;

    public static function rebuildGroups(iterable $groupIds): void
    {
        if (! self::available()) {
            return;
        }

        $ids = collect($groupIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        foreach ($ids->chunk(500) as $chunk) {
            DB::table('contact_groups')
                ->whereIn('id', $chunk->all())
                ->update([
                    'contacts_count' => DB::raw("(
                        SELECT COUNT(*)
                        FROM contact_contact_group
                        INNER JOIN contacts
                            ON contacts.id = contact_contact_group.contact_id
                        WHERE contact_contact_group.contact_group_id = contact_groups.id
                            AND contacts.deleted_at IS NULL
                    )"),
                ]);
        }
    }

    public static function rebuildForContacts(iterable $contactIds): void
    {
        if (! self::available()) {
            return;
        }

        $ids = collect($contactIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $groupIds = DB::table('contact_contact_group')
            ->whereIn('contact_id', $ids->all())
            ->pluck('contact_group_id');

        self::rebuildGroups($groupIds);
    }

    private static function available(): bool
    {
        if (self::$hasContactsCountColumn === null) {
            self::$hasContactsCountColumn = Schema::hasColumn('contact_groups', 'contacts_count');
        }

        return self::$hasContactsCountColumn;
    }
}
