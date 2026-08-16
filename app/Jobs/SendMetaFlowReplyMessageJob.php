<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\Organization;
use App\Services\WhatsappService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\MetaFlows\Models\MetaFlow;
use Modules\MetaFlows\Models\MetaFlowResponse;
use Modules\MetaFlows\Services\MetaFlowResponseNormalizer;

class SendMetaFlowReplyMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(
        private readonly int $metaFlowResponseId,
        private readonly int $organizationId,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $lock = Cache::lock('meta-flow-reply:' . $this->metaFlowResponseId, 600);

        if (! $lock->get()) {
            Log::info('SendMetaFlowReplyMessageJob: duplicate execution skipped', [
                'response_id' => $this->metaFlowResponseId,
            ]);

            return;
        }

        try {
            $response = MetaFlowResponse::find($this->metaFlowResponseId);

            if (!$response || !$response->response_json) {
                Log::warning('SendMetaFlowReplyMessageJob: response not found or empty', [
                    'response_id' => $this->metaFlowResponseId,
                ]);
                return;
            }

            if ($response->reply_sent_at) {
                Log::info('SendMetaFlowReplyMessageJob: reply already sent, skipping', [
                    'response_id' => $this->metaFlowResponseId,
                ]);
                return;
            }

            $flow = MetaFlow::find($response->meta_flow_id);

            if (!$flow || !$flow->reply_message_enabled || !$flow->reply_message_template) {
                return;
            }

            $contact = Contact::find($response->contact_id);

            if (!$contact) {
                Log::warning('SendMetaFlowReplyMessageJob: contact not found', [
                    'contact_id' => $response->contact_id,
                ]);
                return;
            }

            $values = MetaFlowResponseNormalizer::normalize($response, $flow, $contact);
            $message = MetaFlowResponseNormalizer::substituteTemplate($flow->reply_message_template, $values);

            $organization = Organization::find($this->organizationId);
            $metadata = $organization ? json_decode($organization->metadata, true) ?? [] : [];
            $whatsapp = $metadata['whatsapp'] ?? [];

            $whatsappService = new WhatsappService(
                $whatsapp['access_token'] ?? '',
                config('graph.api_version'),
                $whatsapp['app_id'] ?? null,
                $whatsapp['phone_number_id'] ?? '',
                $whatsapp['waba_id'] ?? '',
                $this->organizationId,
            );

            $result = $whatsappService->sendMessage($contact->uuid, $message);

            if (!($result->success ?? false)) {
                throw new \RuntimeException('WhatsApp rejected the Meta Flow reply.');
            }

            $response->forceFill(['reply_sent_at' => now()])->save();

            Log::info('SendMetaFlowReplyMessageJob: reply message sent', [
                'response_id' => $this->metaFlowResponseId,
                'contact_id'  => $response->contact_id,
                'result'       => $result,
            ]);
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendMetaFlowReplyMessageJob: permanent failure', [
            'response_id'    => $this->metaFlowResponseId,
            'organization_id' => $this->organizationId,
            'error'          => $exception->getMessage(),
        ]);
    }
}
