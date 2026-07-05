<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use App\Models\OrganizationChatStat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ArchiveOldChats extends Command
{
    protected $signature = 'chats:archive
        {--months=12 : Archive chats older than this many months}
        {--before= : Archive chats created before this date, YYYY-MM-DD}
        {--batch-size=500 : Number of chats to process per batch}
        {--limit= : Maximum number of chats to archive in this run}
        {--sleep=250 : Sleep between batches in milliseconds}
        {--include-latest-per-contact : Also archive the latest chat for inactive contacts}
        {--show-count : Count eligible chats before processing; this can be slow on large tables}
        {--allow-recent : Allow archiving chats newer than 30 days}
        {--execute : Actually copy and delete rows; without this option the command is dry-run}';

    protected $description = 'Archive old chats into chats_archive in verified batches without deleting unarchived rows';

    private const CHAT_COLUMNS = [
        'id',
        'uuid',
        'organization_id',
        'wam_id',
        'contact_id',
        'user_id',
        'type',
        'metadata',
        'media_id',
        'status',
        'is_read',
        'deleted_by',
        'deleted_at',
        'created_at',
    ];

    public function handle(): int
    {
        $batchSize = max(1, min((int) $this->option('batch-size'), 2000));
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $sleepMs = max(0, (int) $this->option('sleep'));
        $execute = (bool) $this->option('execute');
        $keepLatestPerContact = !(bool) $this->option('include-latest-per-contact');
        $cutoff = $this->cutoffDate();

        if (!Schema::hasTable('chats_archive')) {
            $this->error('The chats_archive table does not exist. Run php artisan migrate first.');
            return self::FAILURE;
        }

        if (!$this->option('allow-recent') && $cutoff->gt(now()->subDays(30))) {
            $this->error('Refusing to archive chats newer than 30 days. Use --allow-recent to override.');
            return self::FAILURE;
        }

        $this->info('Chat archive');
        $this->line('------------');
        $this->line('Cutoff: '.$cutoff->toDateTimeString());
        $this->line('Batch size: '.$batchSize);
        $this->line('Keep latest chat per contact: '.($keepLatestPerContact ? 'yes' : 'no'));
        $this->line('Mode: '.($execute ? 'EXECUTE' : 'DRY-RUN'));

        if ($limit !== null) {
            $this->line('Limit: '.$limit);
        }

        if (!$execute) {
            $sampleIds = $this->eligibleQuery($cutoff, $keepLatestPerContact)
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');

            if ($sampleIds->isEmpty()) {
                $this->info('No chats are eligible for archiving.');
                return self::SUCCESS;
            }

            $this->info('Dry-run sample size: '.$sampleIds->count());
            $this->line('First sample id: '.$sampleIds->first());
            $this->line('Last sample id: '.$sampleIds->last());

            if ($this->option('show-count')) {
                $eligibleCount = $this->eligibleQuery($cutoff, $keepLatestPerContact)->count();
                $this->info("Eligible chats: {$eligibleCount}");
            }

            $this->warn('Dry-run only. Add --execute to archive rows.');
            return self::SUCCESS;
        }

        if (!$this->confirm('Archive these chats now?', false)) {
            $this->info('Archive cancelled.');
            return self::SUCCESS;
        }

        $archived = 0;

        while ($limit === null || $archived < $limit) {
            $remaining = $limit === null ? $batchSize : min($batchSize, $limit - $archived);
            $ids = $this->eligibleQuery($cutoff, $keepLatestPerContact)
                ->orderBy('id')
                ->limit($remaining)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($ids === []) {
                break;
            }

            $this->archiveBatch($ids);

            $count = count($ids);
            $archived += $count;
            $this->line("Archived batch: {$count}; total archived: {$archived}");

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->newLine();
        $this->info("Archived chats: {$archived}");

        return self::SUCCESS;
    }

    private function cutoffDate(): Carbon
    {
        if ($this->option('before')) {
            return Carbon::parse((string) $this->option('before'))->startOfDay();
        }

        return now()->subMonths((int) $this->option('months'))->startOfDay();
    }

    private function eligibleQuery(Carbon $cutoff, bool $keepLatestPerContact)
    {
        $query = DB::table('chats')
            ->where('created_at', '<', $cutoff->toDateTimeString());

        if ($keepLatestPerContact) {
            $query->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('chats as newer_chats')
                    ->whereColumn('newer_chats.contact_id', 'chats.contact_id')
                    ->where(function ($query) {
                        $query->whereColumn('newer_chats.created_at', '>', 'chats.created_at')
                            ->orWhere(function ($query) {
                                $query->whereColumn('newer_chats.created_at', 'chats.created_at')
                                    ->whereColumn('newer_chats.id', '>', 'chats.id');
                            });
                    });
            });
        }

        return $query;
    }

    private function archiveBatch(array $ids): void
    {
        DB::transaction(function () use ($ids) {
            $countsByOrganization = DB::table('chats')
                ->whereIn('id', $ids)
                ->select('organization_id', DB::raw('COUNT(*) as aggregate'))
                ->groupBy('organization_id')
                ->pluck('aggregate', 'organization_id');

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $columns = '`'.implode('`, `', self::CHAT_COLUMNS).'`';

            DB::statement(
                "INSERT IGNORE INTO `chats_archive` ({$columns}, `archived_at`)
                 SELECT {$columns}, NOW()
                 FROM `chats`
                 WHERE `id` IN ({$placeholders})",
                $ids
            );

            $sourceCount = DB::table('chats')->whereIn('id', $ids)->count();
            $archiveCount = DB::table('chats_archive')->whereIn('id', $ids)->count();

            if ($sourceCount !== $archiveCount) {
                throw new \RuntimeException(
                    "Archive verification failed: source={$sourceCount}, archive={$archiveCount}"
                );
            }

            DB::table('chats')->whereIn('id', $ids)->delete();

            foreach ($countsByOrganization as $organizationId => $count) {
                OrganizationChatStat::decrementActiveChats((int) $organizationId, (int) $count);
            }
        }, 3);
    }
}
