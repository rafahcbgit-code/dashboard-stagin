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
        {--dry-run : Show how many events would be created without writing}';

    protected $description = 'Backfill delivered WhatsApp template message usage events from existing chat records.';

    private SubscriptionUsageService $usageService;
    private array $seenChatIds = [];
    private int $created = 0;
    private int $existing = 0;
    private int $skipped = 0;

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
        $organizationId = $this->option('organization') !== null
            ? (int) $this->option('organization')
            : null;

        $this->info($dryRun
            ? 'Dry run: no usage events will be written.'
            : 'Backfilling delivered template message usage events...');

        foreach ($this->candidateQueries($organizationId) as $sourceName => $query) {
            $this->line("Processing {$sourceName}...");

            $query->orderBy('chats.id')->chunk($chunkSize, function ($rows) use ($dryRun): void {
                foreach ($rows as $row) {
                    $this->processCandidate($row, $dryRun);
                }
            });
        }

        $this->newLine();
        $this->info("Created: {$this->created}");
        $this->info("Already existing: {$this->existing}");
        $this->info("Skipped: {$this->skipped}");

        return self::SUCCESS;
    }

    private function candidateQueries(?int $organizationId): array
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

        return $queries;
    }

    private function baseChatQuery(?int $organizationId): Builder
    {
        $query = DB::table('chats')
            ->where('chats.type', 'outbound')
            ->whereNotNull('chats.wam_id')
            ->whereIn('chats.status', ['delivered', 'read'])
            ->select([
                'chats.id as chat_id',
                'chats.organization_id',
                'chats.wam_id',
                'chats.created_at',
            ])
            ->distinct();

        if ($organizationId) {
            $query->where('chats.organization_id', $organizationId);
        }

        return $query;
    }

    private function processCandidate(object $row, bool $dryRun): void
    {
        $chatId = (int) $row->chat_id;
        if (isset($this->seenChatIds[$chatId])) {
            $this->skipped++;
            return;
        }

        $this->seenChatIds[$chatId] = true;

        $idempotencyKey = 'template-delivered:' . $row->wam_id;

        $exists = SubscriptionUsageEvent::where('organization_id', (int) $row->organization_id)
            ->where('resource', SubscriptionUsageService::TEMPLATE_MESSAGES)
            ->where('idempotency_key', $idempotencyKey)
            ->exists();

        if ($exists) {
            $this->existing++;
            return;
        }

        if ($dryRun) {
            $this->created++;
            return;
        }

        $occurredAt = $this->deliveredAt($chatId) ?: Carbon::parse($row->created_at);

        $this->usageService->recordUsage(
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

        $this->created++;
    }

    private function deliveredAt(int $chatId): ?Carbon
    {
        if (! Schema::hasTable('chat_status_logs')) {
            return null;
        }

        $createdAt = DB::table('chat_status_logs')
            ->where('chat_id', $chatId)
            ->where(function (Builder $query): void {
                $query->where('metadata', 'like', '%"status":"delivered"%')
                    ->orWhere('metadata', 'like', '%"status": "delivered"%')
                    ->orWhere('metadata', 'like', '%"status":"read"%')
                    ->orWhere('metadata', 'like', '%"status": "read"%');
            })
            ->orderByRaw("CASE WHEN metadata LIKE '%\"status\":\"delivered\"%' OR metadata LIKE '%\"status\": \"delivered\"%' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->value('created_at');

        return $createdAt ? Carbon::parse($createdAt) : null;
    }
}
