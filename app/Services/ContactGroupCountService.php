<?php

namespace App\Services;

use App\Support\ContactPageCache;
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

        $organizationIds = DB::table('contact_groups')
            ->whereIn('id', $ids->all())
            ->pluck('organization_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        foreach ($ids->chunk(500) as $chunk) {
            DB::table('contact_groups')
                ->whereIn('id', $chunk->all())
                ->update([
                    'contacts_count' => DB::raw('(
                        SELECT COUNT(DISTINCT contact_contact_group.contact_id)
                        FROM contact_contact_group
                        INNER JOIN contacts
                            ON contacts.id = contact_contact_group.contact_id
                        WHERE contact_contact_group.contact_group_id = contact_groups.id
                            AND contacts.deleted_at IS NULL
                    )'),
                ]);
        }

        $organizationIds->each(fn (int $organizationId) => ContactPageCache::forgetContactGroups($organizationId));
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

        $groupIds = collect();

        foreach ($ids->chunk(5000) as $chunk) {
            $groupIds = $groupIds->merge(
                DB::table('contact_contact_group')
                    ->whereIn('contact_id', $chunk->all())
                    ->pluck('contact_group_id')
            );
        }

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
