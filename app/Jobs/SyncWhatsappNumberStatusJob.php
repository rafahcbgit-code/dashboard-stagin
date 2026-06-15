<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\WhatsappNumberStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncWhatsappNumberStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;

    public $tries = 1;

    public function handle(WhatsappNumberStatusService $statusService): void
    {
        Organization::query()
            ->whereNotNull('metadata')
            ->select(['id', 'metadata'])
            ->chunkById(100, function ($organizations) use ($statusService) {
                foreach ($organizations as $organization) {
                    $metadata = json_decode($organization->metadata ?: '{}', true);
                    $whatsapp = is_array($metadata['whatsapp'] ?? null) ? $metadata['whatsapp'] : [];

                    if (empty($whatsapp['access_token']) || empty($whatsapp['phone_number_id'])) {
                        continue;
                    }

                    $statusService->sync($organization->id, 'scheduled_graph_api');
                }
            });
    }
}
