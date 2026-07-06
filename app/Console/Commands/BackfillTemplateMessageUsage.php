<?php

namespace App\Console\Commands;

use App\Models\SubscriptionUsageEvent;
use App\Services\SubscriptionUsageService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillTemplateMessageUsage extends Command
{
    protected $signature = 'subscription:backfill-template-messages
        {--organization= : Limit backfill to a single organization ID}
        {--chunk=500 : Number of rows to process per chunk}
        {--dry-run : Show how many events would be created without writing}
        {--diagnose : Print source counts before processing}
        {--include-unmarked : Also count legacy outbound delivered/read chats that cannot be proven as templates}
        {--dedupe-in-memory : Track processed chat IDs in PHP memory for more accurate dry-run duplicate counts; avoid on very large datasets}
        {--use-chat-created-at : Use chats.created_at for occurred_at instead of querying chat_status_logs per message}
        {--use-status-log-delivered-at : Use chat_status_logs delivered/read time for occurred_at; slower on large datasets}
        {--status-source=chat : Delivered status source: chat, logs, or both}
        {--fast-dry-run : In dry-run mode, skip per-row existing checks for speed on very large datasets}
        {--progress-every=50000 : Print progress after this many candidate rows are scanned}';

    protected $description = 'Backfill delivered WhatsApp template message usage events from existing chat records.';

    private SubscriptionUsageService $usageService;
    private array $seenChatIds = [];
    private int $created = 0;
    private int $existing = 0;
    private int $skipped = 0;
    private int $scanned = 0;
    private int $progressEvery = 50000;
    private bool $dedupeInMemory = false;
    private bool $useChatCreatedAt = true;
    private string $statusSource = 'chat';
    private array $subscriptionIdCache = [];

    public function handle(SubscriptionUsageService $usageService): int
    {
        $this->usageService = $usageService;

        if (! Schema::hasTable('subscription_usage_events')) {
            $this->error('subscription_usage_events table does not exist. Run migrations first.');
            return self::FAILURE;
        }

        if (! Schema::hasTable('chats')) {
            $this->error('chats table does not exist.');
            return self::FAILURE;
        }

        $chunkSize = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $this->dedupeInMemory = (bool) $this->option('dedupe-in-memory');
        $this->progressEvery = max(0, (int) $this->option('progress-every'));
        $this->useChatCreatedAt = (bool) $this->option('use-chat-created-at')
            || ! (bool) $this->option('use-status-log-delivered-at')
            || ! Schema::hasTable('chat_status_logs');
        $this->statusSource = strtolower((string) $this->option('status-source'));
        $organizationId = $this->option('organization') !== null
            ? (int) $this->option('organization')
            : null;

        if (! in_array($this->statusSource, ['chat', 'logs', 'both'], true)) {
            $this->error('--status-source must be one of: chat, logs, both.');
            return self::FAILURE;
        }

        if ($this->statusSource !== 'chat' && ! Schema::hasTable('chat_status_logs')) {
            $this->warn('chat_status_logs table does not exist. Falling back to --status-source=chat.');
            $this->statusSource = 'chat';
        }

        DB::disableQueryLog();

        $this->info($dryRun
            ? 'Dry run: no usage events will be written.'
            : 'Backfilling delivered template message usage events...');

        if ($this->statusSource !== 'chat') {
            $this->warn('Using chat_status_logs in candidate filtering is slower on large datasets. Prefer --status-source=chat for the fastest production backfill.');
        }

        if (! $this->useChatCreatedAt) {
            $this->warn('Using chat_status_logs for occurred_at is slower on large datasets. Omit --use-status-log-delivered-at for the fastest production backfill.');
        }

        if ($dryRun && ! (bool) $this->option('fast-dry-run')) {
            $this->warn('Dry-run without --fast-dry-run checks existing usage events per candidate and can be slow on very large datasets.');
        }

        if ((bool) $this->option('diagnose')) {
            $this->warn('Diagnostics run COUNT queries and can be heavy on very large tables.');
            $this->printDiagnostics($organizationId);
        }

        foreach ($this->candidateQueries($organizationId, (bool) $this->option('include-unmarked')) as $sourceName => $query) {
            $this->line("Processing {$sourceName}...");

            $query->chunkById($chunkSize, function ($rows) use ($dryRun): void {
                $this->processCandidateChunk($rows, $dryRun);
            }, 'chats.id', 'chat_id');
        }

        $this->newLine();
        $this->info("Created: {$this->created}");
        $this->info("Already existing: {$this->existing}");
        $this->info("Skipped: {$this->skipped}");
        $this->info("Scanned candidate rows: {$this->scanned}");

        return self::SUCCESS;
    }

    private function candidateQueries(?int $organizationId, bool $includeUnmarked = false): array
    {
        $queries = [
            'marked-template-metadata' => $this->baseChatQuery($organizationId)
                ->where(function (Builder $query): void {
                    $query->whereRaw(
                        "CASE WHEN JSON_VALID(chats.metadata) THEN JSON_UNQUOTE(JSON_EXTRACT(chats.metadata, '$.billing.is_template')) ELSE NULL END IN ('true', '1')"
                    )
                    ->orWhereRaw(
                        "CASE WHEN JSON_VALID(chats.metadata) THEN JSON_UNQUOTE(JSON_EXTRACT(chats.metadata, '$.message_type')) ELSE NULL END = 'template'"
                    )
                    ->orWhereRaw(
                        "CASE WHEN JSON_VALID(chats.metadata) THEN JSON_UNQUOTE(JSON_EXTRACT(chats.metadata, '$.type')) ELSE NULL END = 'carousel'"
                    );
                })
                ->selectRaw("'template_metadata' as source_type, chats.id as source_id"),
        ];

        if (Schema::hasTable('campaign_logs') && Schema::hasTable('campaigns')) {
            $queries['campaigns'] = $this->baseChatQuery($organizationId)
                ->join('campaign_logs', 'campaign_logs.chat_id', '=', 'chats.id')
                ->join('campaigns', 'campaigns.id', '=', 'campaign_logs.campaign_id')
                ->where('campaign_logs.status', 'success')
                ->whereNotNull('campaigns.template_id')
                ->selectRaw("'campaign' as source_type, campaign_logs.campaign_id as source_id");
        }

        foreach ([
            'salla' => 'salla_webhook_deliveries',
            'zid' => 'zid_webhook_deliveries',
            'shopify' => 'shopify_webhook_deliveries',
            'woocommerce' => 'woocommerce_webhook_deliveries',
        ] as $source => $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'sent_chat_id')) {
                continue;
            }

            $queries[$source] = $this->baseChatQuery($organizationId)
                ->join($table, "{$table}.sent_chat_id", '=', 'chats.id')
                ->selectRaw("'{$source}' as source_type, {$table}.id as source_id");
        }

        if (Schema::hasTable('outbound_messages') && Schema::hasColumn('outbound_messages', 'sent_chat_id')) {
            $queries['outbound-messages'] = $this->baseChatQuery($organizationId)
                ->join('outbound_messages', 'outbound_messages.sent_chat_id', '=', 'chats.id')
                ->whereNotNull('outbound_messages.template_id')
                ->selectRaw('COALESCE(outbound_messages.source, "outbound_message") as source_type, outbound_messages.id as source_id');
        }

        if ($includeUnmarked) {
            $queries['legacy-unmarked-outbound'] = $this->baseChatQuery($organizationId)
                ->selectRaw("'legacy_unmarked_outbound' as source_type, chats.id as source_id");
        }

        return $queries;
    }

    private function baseChatQuery(?int $organizationId): Builder
    {
        $query = DB::table('chats')
            ->where('chats.type', 'outbound')
            ->whereNotNull('chats.wam_id')
            ->where($this->deliveredStatusConstraint())
            ->select([
                'chats.id as chat_id',
                'chats.organization_id',
                'chats.wam_id',
                'chats.created_at',
            ]);

        if ($organizationId) {
            $query->where('chats.organization_id', $organizationId);
        }

        return $query;
    }

    private function deliveredStatusConstraint(): callable
    {
        return function (Builder $query): void {
            if ($this->statusSource === 'chat') {
                $query->whereIn('chats.status', ['delivered', 'read']);
                return;
            }

            $logsConstraint = function (Builder $statusQuery): void {
                $statusQuery->selectRaw('1')
                    ->from('chat_status_logs')
                    ->whereColumn('chat_status_logs.chat_id', 'chats.id')
                    ->where($this->statusLogDeliveredConstraint());
            };

            if ($this->statusSource === 'logs') {
                $query->whereExists($logsConstraint);
                return;
            }

            $query->whereIn('chats.status', ['delivered', 'read'])
                ->orWhereExists($logsConstraint);
        };
    }

    private function statusLogDeliveredConstraint(): callable
    {
        return function (Builder $query): void {
            $query->where('metadata', 'like', '%"status":"delivered"%')
                ->orWhere('metadata', 'like', '%"status": "delivered"%')
                ->orWhere('metadata', 'like', '%"status":"read"%')
                ->orWhere('metadata', 'like', '%"status": "read"%');
        };
    }

    private function processCandidate(object $row, bool $dryRun): void
    {
        $chatId = (int) $row->chat_id;
        $this->scanned++;

        if ($this->dedupeInMemory) {
            if (isset($this->seenChatIds[$chatId])) {
                $this->skipped++;
                return;
            }

            $this->seenChatIds[$chatId] = true;
        }

        $idempotencyKey = 'template-delivered:' . $row->wam_id;

        if ($dryRun) {
            if ((bool) $this->option('fast-dry-run')) {
                $this->created++;
                return;
            }

            $exists = SubscriptionUsageEvent::where('organization_id', (int) $row->organization_id)
                ->where('resource', SubscriptionUsageService::TEMPLATE_MESSAGES)
                ->where('idempotency_key', $idempotencyKey)
                ->exists();

            if ($exists) {
                $this->existing++;
                return;
            }

            $this->created++;
            return;
        }

        $occurredAt = $this->useChatCreatedAt
            ? Carbon::parse($row->created_at)
            : ($this->deliveredAt($chatId) ?: Carbon::parse($row->created_at));

        $usageEvent = $this->usageService->recordUsage(
            (int) $row->organization_id,
            SubscriptionUsageService::TEMPLATE_MESSAGES,
            1,
            $idempotencyKey,
            (string) ($row->source_type ?? 'template_backfill'),
            is_numeric($row->source_id ?? null) ? (int) $row->source_id : $chatId,
            [
                'chat_id' => $chatId,
                'wam_id' => $row->wam_id,
                'backfilled' => true,
                'backfill_source' => (string) ($row->source_type ?? 'template_backfill'),
            ],
            $occurredAt
        );

        if ($usageEvent?->wasRecentlyCreated) {
            $this->created++;
            return;
        }

        $this->existing++;
    }

    private function processCandidateChunk($rows, bool $dryRun): void
    {
        if ($dryRun) {
            foreach ($rows as $row) {
                $this->processCandidate($row, true);
                $this->reportProgress();
            }

            return;
        }

        $now = now()->toDateTimeString();
        $events = [];
        $deliveredAtByChatId = $this->useChatCreatedAt ? [] : $this->deliveredAtForRows($rows);

        foreach ($rows as $row) {
            $chatId = (int) $row->chat_id;
            $this->scanned++;

            if ($this->dedupeInMemory) {
                if (isset($this->seenChatIds[$chatId])) {
                    $this->skipped++;
                    $this->reportProgress();
                    continue;
                }

                $this->seenChatIds[$chatId] = true;
            }

            $occurredAt = $this->useChatCreatedAt
                ? Carbon::parse($row->created_at)
                : ($deliveredAtByChatId[$chatId] ?? Carbon::parse($row->created_at));

            $events[] = [
                'organization_id' => (int) $row->organization_id,
                'subscription_id' => $this->subscriptionIdForOrganization((int) $row->organization_id),
                'resource' => SubscriptionUsageService::TEMPLATE_MESSAGES,
                'quantity' => 1,
                'source_type' => (string) ($row->source_type ?? 'template_backfill'),
                'source_id' => is_numeric($row->source_id ?? null) ? (int) $row->source_id : $chatId,
                'idempotency_key' => 'template-delivered:' . $row->wam_id,
                'metadata' => json_encode([
                    'chat_id' => $chatId,
                    'wam_id' => $row->wam_id,
                    'backfilled' => true,
                    'backfill_source' => (string) ($row->source_type ?? 'template_backfill'),
                ]),
                'occurred_at' => $occurredAt->toDateTimeString(),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $this->reportProgress();
        }

        if ($events === []) {
            return;
        }

        $inserted = DB::table('subscription_usage_events')->insertOrIgnore($events);
        $this->created += (int) $inserted;
        $this->existing += count($events) - (int) $inserted;
    }

    private function deliveredAtForRows($rows): array
    {
        if (! Schema::hasTable('chat_status_logs')) {
            return [];
        }

        $chatIds = collect($rows)
            ->pluck('chat_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($chatIds === []) {
            return [];
        }

        return DB::table('chat_status_logs')
            ->selectRaw('chat_id, MIN(created_at) as delivered_at')
            ->whereIn('chat_id', $chatIds)
            ->where($this->statusLogDeliveredConstraint())
            ->groupBy('chat_id')
            ->pluck('delivered_at', 'chat_id')
            ->map(fn ($createdAt) => Carbon::parse($createdAt))
            ->all();
    }

    private function subscriptionIdForOrganization(int $organizationId): ?int
    {
        if (array_key_exists($organizationId, $this->subscriptionIdCache)) {
            return $this->subscriptionIdCache[$organizationId];
        }

        $resourceAccountId = $this->usageService->resourceAccountId($organizationId);

        return $this->subscriptionIdCache[$organizationId] = DB::table('subscriptions')
            ->where('organization_id', $resourceAccountId)
            ->orderByDesc('id')
            ->value('id');
    }

    private function reportProgress(): void
    {
        if ($this->progressEvery > 0 && $this->scanned > 0 && $this->scanned % $this->progressEvery === 0) {
            $this->line("Scanned {$this->scanned} candidates; created {$this->created}, existing {$this->existing}, skipped {$this->skipped}.");
        }
    }

    private function deliveredAt(int $chatId): ?Carbon
    {
        if (! Schema::hasTable('chat_status_logs')) {
            return null;
        }

        $createdAt = DB::table('chat_status_logs')
            ->where('chat_id', $chatId)
            ->where($this->statusLogDeliveredConstraint())
            ->orderByRaw("CASE WHEN metadata LIKE '%\"status\":\"delivered\"%' OR metadata LIKE '%\"status\": \"delivered\"%' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->value('created_at');

        return $createdAt ? Carbon::parse($createdAt) : null;
    }

    private function printDiagnostics(?int $organizationId): void
    {
        $this->newLine();
        $this->line('Diagnostics:');
        $this->line('Outbound chats with wam_id: ' . $this->countOutboundChats($organizationId));
        $this->line('Outbound chats with delivered/read status: ' . $this->countDeliveredChats($organizationId));

        if (Schema::hasTable('chat_status_logs')) {
            $this->line('Outbound chats with delivered/read status logs: ' . $this->countDeliveredStatusLogs($organizationId));
        }

        foreach ($this->candidateQueries($organizationId, (bool) $this->option('include-unmarked')) as $sourceName => $query) {
            $this->line("Candidate {$sourceName}: " . DB::query()->fromSub($query, 'candidate_rows')->count());
        }

        $this->newLine();
    }

    private function countOutboundChats(?int $organizationId): int
    {
        $query = DB::table('chats')
            ->where('type', 'outbound')
            ->whereNotNull('wam_id');

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        return $query->count();
    }

    private function countDeliveredChats(?int $organizationId): int
    {
        $query = DB::table('chats')
            ->where('type', 'outbound')
            ->whereNotNull('wam_id')
            ->whereIn('status', ['delivered', 'read']);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        return $query->count();
    }

    private function countDeliveredStatusLogs(?int $organizationId): int
    {
        $query = DB::table('chats')
            ->where('chats.type', 'outbound')
            ->whereNotNull('chats.wam_id')
            ->whereExists(function (Builder $statusQuery): void {
                $statusQuery->selectRaw('1')
                    ->from('chat_status_logs')
                    ->whereColumn('chat_status_logs.chat_id', 'chats.id')
                    ->where($this->statusLogDeliveredConstraint());
            });

        if ($organizationId) {
            $query->where('chats.organization_id', $organizationId);
        }

        return $query->count();
    }
}
