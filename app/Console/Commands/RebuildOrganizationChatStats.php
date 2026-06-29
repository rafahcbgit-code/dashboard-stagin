<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildOrganizationChatStats extends Command
{
    protected $signature = 'chats:stats-rebuild {--organization= : Rebuild stats for one organization id}';

    protected $description = 'Rebuild organization chat counters used by the chat list without running heavy counts per request';

    public function handle(): int
    {
        $organizationId = $this->option('organization') ? (int) $this->option('organization') : null;

        $this->info('Rebuilding organization chat stats...');

        $activeCounts = DB::table('chats')
            ->select('organization_id', DB::raw('COUNT(*) as aggregate'))
            ->whereNull('deleted_at')
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->groupBy('organization_id')
            ->pluck('aggregate', 'organization_id');

        $conversationCounts = DB::table('contacts')
            ->select('organization_id', DB::raw('COUNT(*) as aggregate'))
            ->whereNotNull('latest_chat_created_at')
            ->whereNull('deleted_at')
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->groupBy('organization_id')
            ->pluck('aggregate', 'organization_id');

        $organizationIds = $activeCounts->keys()
            ->merge($conversationCounts->keys())
            ->unique()
            ->values();

        if ($organizationIds->isEmpty()) {
            $this->info('No organizations found.');
            return self::SUCCESS;
        }

        $now = now();
        $rows = $organizationIds->map(fn ($id) => [
            'organization_id' => (int) $id,
            'active_chats_count' => (int) ($activeCounts[$id] ?? 0),
            'conversations_count' => (int) ($conversationCounts[$id] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::table('organization_chat_stats')->upsert(
            $rows,
            ['organization_id'],
            ['active_chats_count', 'conversations_count', 'updated_at']
        );

        $this->info('Rebuilt stats for '.$organizationIds->count().' organization(s).');

        return self::SUCCESS;
    }
}
