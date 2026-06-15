<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\WhatsappNumberStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncWhatsappNumberStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const DISPATCHES_PER_SECOND = 5;

    private const QUERY_CHUNK_SIZE = 500;

    private const FULL_SYNC_LOCK_KEY = 'whatsapp-number-status-full-sync';

    private const FULL_SYNC_LOCK_TTL_SECONDS = 21600;

    public int $timeout;

    public int $tries;

    public array $backoff = [60, 300, 900];

    public function __construct(public ?int $organizationId = null)
    {
        $this->timeout = $organizationId === null ? 300 : 30;
        $this->tries = $organizationId === null ? 1 : 3;
    }

    public function middleware(): array
    {
        if ($this->organizationId === null) {
            return [];
        }

        return [
            (new WithoutOverlapping('whatsapp-number-status-'.$this->organizationId))
                ->releaseAfter(60)
                ->expireAfter(60),
        ];
    }

    public function handle(WhatsappNumberStatusService $statusService): void
    {
        if ($this->organizationId !== null) {
            $result = $statusService->sync($this->organizationId, 'scheduled_graph_api');

            if (! ($result['success'] ?? false)
                && ! ($result['skipped'] ?? false)
                && ($result['status'] ?? null) === null
                && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 900);
            }

            return;
        }

        if (! Cache::add(
            self::FULL_SYNC_LOCK_KEY,
            now()->toIso8601String(),
            self::FULL_SYNC_LOCK_TTL_SECONDS
        )) {
            Log::channel('whatsapp_number_status')->info('WhatsApp number status full sync skipped', [
                'reason' => 'another_full_sync_is_in_progress',
            ]);

            return;
        }

        $dispatched = 0;

        Organization::query()
            ->whereNotNull('metadata')
            ->select(['id', 'metadata'])
            ->chunkById(self::QUERY_CHUNK_SIZE, function ($organizations) use (&$dispatched) {
                foreach ($organizations as $organization) {
                    $metadata = json_decode($organization->metadata ?: '{}', true);
                    $whatsapp = is_array($metadata['whatsapp'] ?? null) ? $metadata['whatsapp'] : [];

                    if (empty($whatsapp['access_token']) || empty($whatsapp['phone_number_id'])) {
                        continue;
                    }

                    $delaySeconds = intdiv($dispatched, self::DISPATCHES_PER_SECOND);

                    self::dispatch($organization->id)
                        ->onConnection('redis')
                        ->onQueue('whatsapp-number-status')
                        ->delay(now()->addSeconds($delaySeconds));

                    $dispatched++;
                }
            });

        Log::channel('whatsapp_number_status')->info('WhatsApp number status jobs dispatched', [
            'organizations_count' => $dispatched,
            'dispatches_per_second' => self::DISPATCHES_PER_SECOND,
            'spread_seconds' => $dispatched > 0
                ? intdiv($dispatched - 1, self::DISPATCHES_PER_SECOND)
                : 0,
        ]);
    }
}
