<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildContactGroupCounts extends Command
{
    protected $signature = 'contact-groups:counts-rebuild {--organization= : Rebuild counts for one organization id}';

    protected $description = 'Rebuild stored active contact counts for contact groups';

    public function handle(): int
    {
        $organizationId = $this->option('organization') ? (int) $this->option('organization') : null;

        $this->info('Resetting contact group counts...');

        DB::table('contact_groups')
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->update(['contacts_count' => 0]);

        $this->info('Calculating active group members...');

        $counts = DB::table('contact_contact_group')
            ->select('contact_contact_group.contact_group_id', DB::raw('COUNT(*) as aggregate'))
            ->join('contacts', 'contacts.id', '=', 'contact_contact_group.contact_id')
            ->join('contact_groups', 'contact_groups.id', '=', 'contact_contact_group.contact_group_id')
            ->whereNull('contacts.deleted_at')
            ->when($organizationId, fn ($query) => $query->where('contact_groups.organization_id', $organizationId))
            ->groupBy('contact_contact_group.contact_group_id')
            ->pluck('aggregate', 'contact_group_id');

        foreach ($counts as $groupId => $count) {
            DB::table('contact_groups')
                ->where('id', (int) $groupId)
                ->update(['contacts_count' => (int) $count]);
        }

        $this->info('Rebuilt counts for '.$counts->count().' group(s).');

        return self::SUCCESS;
    }
}
