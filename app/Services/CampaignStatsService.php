<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CampaignStatsService
{
    private const COLUMNS = [
        'total_message_count',
        'total_sent_count',
        'total_delivered_count',
        'total_failed_count',
        'total_read_count',
        'processing_count',
        'queued_count',
    ];

    private static ?bool $available = null;

    public static function rebuildCampaignIds(iterable $campaignIds): void
    {
        if (! self::available()) {
            return;
        }

        $ids = collect($campaignIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        foreach ($ids->chunk(100) as $chunk) {
            $campaigns = DB::table('campaigns')
                ->whereIn('id', $chunk->all())
                ->pluck('organization_id', 'id');

            if ($campaigns->isEmpty()) {
                continue;
            }

            $stats = DB::table('campaign_logs')
                ->leftJoin('chats as chat', 'chat.id', '=', 'campaign_logs.chat_id')
                ->whereIn('campaign_logs.campaign_id', $campaigns->keys()->all())
                ->selectRaw('
                    campaign_logs.campaign_id,
                    COUNT(*) as total_message_count,
                    SUM(CASE WHEN campaign_logs.status = "success" AND chat.status IN ("accepted", "sent", "delivered", "read") THEN 1 ELSE 0 END) as total_sent_count,
                    SUM(CASE WHEN campaign_logs.status = "success" AND chat.status IN ("delivered", "read") THEN 1 ELSE 0 END) as total_delivered_count,
                    SUM(CASE WHEN campaign_logs.status IN ("failed", "permanently_failed") THEN 1 ELSE 0 END) +
                        SUM(CASE WHEN campaign_logs.status = "success" AND chat.status = "failed" THEN 1 ELSE 0 END) as total_failed_count,
                    SUM(CASE WHEN campaign_logs.status = "success" AND chat.status = "read" THEN 1 ELSE 0 END) as total_read_count,
                    SUM(CASE WHEN campaign_logs.status = "ongoing" THEN 1 ELSE 0 END) as processing_count,
                    SUM(CASE WHEN campaign_logs.status = "pending" THEN 1 ELSE 0 END) as queued_count
                ')
                ->groupBy('campaign_logs.campaign_id')
                ->get()
                ->keyBy('campaign_id');

            $now = now();
            $rows = [];
            foreach ($campaigns as $campaignId => $organizationId) {
                $stat = $stats->get($campaignId);
                $rows[] = [
                    'campaign_id' => (int) $campaignId,
                    'organization_id' => (int) $organizationId,
                    'total_message_count' => (int) ($stat->total_message_count ?? 0),
                    'total_sent_count' => (int) ($stat->total_sent_count ?? 0),
                    'total_delivered_count' => (int) ($stat->total_delivered_count ?? 0),
                    'total_failed_count' => (int) ($stat->total_failed_count ?? 0),
                    'total_read_count' => (int) ($stat->total_read_count ?? 0),
                    'processing_count' => (int) ($stat->processing_count ?? 0),
                    'queued_count' => (int) ($stat->queued_count ?? 0),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('campaign_stats')->upsert(
                $rows,
                ['campaign_id'],
                array_merge(self::COLUMNS, ['organization_id', 'updated_at'])
            );
        }
    }

    public static function rebuildForOrganization(int $organizationId): void
    {
        DB::table('campaigns')
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->select('id')
            ->chunkById(500, function ($campaigns): void {
                self::rebuildCampaignIds($campaigns->pluck('id'));
            });
    }

    public static function recordInsertedPending(int $campaignId, int $count): void
    {
        if ($campaignId <= 0 || $count <= 0 || ! self::available()) {
            return;
        }

        self::applyDeltas($campaignId, [
            'total_message_count' => $count,
            'queued_count' => $count,
        ]);
    }

    public static function recordSnapshotsChanged(iterable $oldSnapshots, iterable $newSnapshots): void
    {
        if (! self::available()) {
            return;
        }

        $old = collect($oldSnapshots)->keyBy('id');
        $new = collect($newSnapshots)->keyBy('id');
        $chatIds = $old->pluck('chat_id')->merge($new->pluck('chat_id'))->filter()->unique()->values();
        $chatStatuses = $chatIds->isEmpty()
            ? collect()
            : DB::table('chats')->whereIn('id', $chatIds->all())->pluck('status', 'id');

        $deltasByCampaign = [];
        foreach ($old->keys()->merge($new->keys())->unique() as $id) {
            $oldRow = $old->get($id);
            $newRow = $new->get($id);
            $campaignId = (int) (($newRow['campaign_id'] ?? null) ?: ($oldRow['campaign_id'] ?? 0));
            if ($campaignId <= 0) {
                continue;
            }

            $oldCounts = $oldRow ? self::classify(
                (string) ($oldRow['status'] ?? ''),
                self::chatStatus($chatStatuses, $oldRow['chat_id'] ?? null),
                true
            ) : self::zeroCounts();

            $newCounts = $newRow ? self::classify(
                (string) ($newRow['status'] ?? ''),
                self::chatStatus($chatStatuses, $newRow['chat_id'] ?? null),
                true
            ) : self::zeroCounts();

            foreach (self::COLUMNS as $column) {
                $delta = ($newCounts[$column] ?? 0) - ($oldCounts[$column] ?? 0);
                if ($delta !== 0) {
                    $deltasByCampaign[$campaignId][$column] = ($deltasByCampaign[$campaignId][$column] ?? 0) + $delta;
                }
            }
        }

        foreach ($deltasByCampaign as $campaignId => $deltas) {
            self::applyDeltas((int) $campaignId, $deltas);
        }
    }

    public static function recordChatStatusChanged(int $chatId, ?string $oldStatus, ?string $newStatus): void
    {
        if ($chatId <= 0 || $oldStatus === $newStatus || ! self::available()) {
            return;
        }

        $logs = DB::table('campaign_logs')
            ->where('chat_id', $chatId)
            ->where('status', 'success')
            ->get(['id', 'campaign_id', 'status', 'chat_id']);

        if ($logs->isEmpty()) {
            return;
        }

        $deltasByCampaign = [];
        foreach ($logs as $log) {
            $oldCounts = self::classify('success', $oldStatus, true);
            $newCounts = self::classify('success', $newStatus, true);

            foreach (self::COLUMNS as $column) {
                $delta = ($newCounts[$column] ?? 0) - ($oldCounts[$column] ?? 0);
                if ($delta !== 0) {
                    $deltasByCampaign[(int) $log->campaign_id][$column] =
                        ($deltasByCampaign[(int) $log->campaign_id][$column] ?? 0) + $delta;
                }
            }
        }

        foreach ($deltasByCampaign as $campaignId => $deltas) {
            unset($deltas['total_message_count']);
            self::applyDeltas((int) $campaignId, $deltas);
        }
    }

    public static function snapshot($log): array
    {
        return [
            'id' => (int) $log->id,
            'campaign_id' => (int) $log->campaign_id,
            'status' => $log->status,
            'chat_id' => $log->chat_id ? (int) $log->chat_id : null,
        ];
    }

    private static function classify(string $logStatus, ?string $chatStatus, bool $includeTotal): array
    {
        $counts = self::zeroCounts();
        $counts['total_message_count'] = $includeTotal ? 1 : 0;

        if ($logStatus === 'pending') {
            $counts['queued_count'] = 1;
        } elseif ($logStatus === 'ongoing') {
            $counts['processing_count'] = 1;
        } elseif (in_array($logStatus, ['failed', 'permanently_failed'], true)) {
            $counts['total_failed_count'] = 1;
        } elseif ($logStatus === 'success') {
            if (in_array($chatStatus, ['accepted', 'sent', 'delivered', 'read'], true)) {
                $counts['total_sent_count'] = 1;
            }

            if (in_array($chatStatus, ['delivered', 'read'], true)) {
                $counts['total_delivered_count'] = 1;
            }

            if ($chatStatus === 'read') {
                $counts['total_read_count'] = 1;
            }

            if ($chatStatus === 'failed') {
                $counts['total_failed_count'] = 1;
            }
        }

        return $counts;
    }

    private static function zeroCounts(): array
    {
        return array_fill_keys(self::COLUMNS, 0);
    }

    private static function chatStatus($statuses, mixed $chatId): ?string
    {
        return $chatId ? ($statuses[(int) $chatId] ?? null) : null;
    }

    private static function applyDeltas(int $campaignId, array $deltas): void
    {
        $deltas = collect($deltas)->only(self::COLUMNS)->filter(fn ($delta) => $delta !== 0);
        if ($deltas->isEmpty()) {
            return;
        }

        self::ensureRow($campaignId);

        $updates = ['updated_at' => now()];
        foreach ($deltas as $column => $delta) {
            $operator = $delta >= 0 ? '+' : '-';
            $amount = abs((int) $delta);
            $updates[$column] = DB::raw("GREATEST(`{$column}` {$operator} {$amount}, 0)");
        }

        DB::table('campaign_stats')->where('campaign_id', $campaignId)->update($updates);
    }

    private static function ensureRow(int $campaignId): void
    {
        $campaign = DB::table('campaigns')->where('id', $campaignId)->first(['id', 'organization_id']);
        if (! $campaign) {
            return;
        }

        DB::table('campaign_stats')->updateOrInsert(
            ['campaign_id' => $campaignId],
            [
                'organization_id' => (int) $campaign->organization_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private static function available(): bool
    {
        if (self::$available === null) {
            self::$available = Schema::hasTable('campaign_stats');
        }

        return self::$available;
    }
}
