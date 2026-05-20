<?php

namespace App\Jobs\Flow;

use App\Models\Organization;
use App\Services\WhatsappService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\FlowBuilder\Models\FlowUserData;

class SendFlowRejoinMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $flowUserDataId;
    public int $organizationId;
    public string $contactUuid;
    public string $message;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(
        int $flowUserDataId,
        int $organizationId,
        string $contactUuid,
        string $message
    ) {
        $this->queue = 'default';
        $this->flowUserDataId = $flowUserDataId;
        $this->organizationId = $organizationId;
        $this->contactUuid = $contactUuid;
        $this->message = $message;
    }

    public function handle(): void
    {
        // Double-check we haven't already sent (prevents duplicates if job is retried)
        $flowData = FlowUserData::find($this->flowUserDataId);
        if ($flowData && $flowData->rejoin_sent_at) {
            Log::info('FlowRejoinMessage: already sent, skipping', [
                'flow_data_id' => $this->flowUserDataId,
            ]);
            return;
        }

        try {
            $org = Organization::find($this->organizationId);
            $metadata = $org && $org->metadata ? json_decode($org->metadata, true) : [];

            $whatsappService = new WhatsappService(
                $metadata['whatsapp']['access_token'] ?? null,
                config('graph.api_version'),
                $metadata['whatsapp']['app_id'] ?? null,
                $metadata['whatsapp']['phone_number_id'] ?? null,
                $metadata['whatsapp']['waba_id'] ?? null,
                $this->organizationId
            );

            $whatsappService->sendMessage(
                $this->contactUuid,
                $this->message,
                null,
                'text'
            );

            // Mark as sent
            if ($flowData) {
                $flowData->rejoin_sent_at = now();
                $flowData->save();
            }

            Log::info('FlowRejoinMessage: sent reminder', [
                'flow_data_id' => $this->flowUserDataId,
                'organization_id' => $this->organizationId,
                'contact_uuid' => $this->contactUuid,
            ]);
        } catch (\Throwable $e) {
            Log::error('FlowRejoinMessage: failed to send', [
                'flow_data_id' => $this->flowUserDataId,
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage(),
            ]);
            throw $e; // Re-throw so the job retries
        }
    }
}