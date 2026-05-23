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
use Illuminate\Support\Facades\Log;
use Modules\MetaFlows\Models\MetaFlow;
use Modules\MetaFlows\Models\MetaFlowResponse;
use Modules\MetaFlows\Services\MetaFlowResponseNormalizer;

class SendMetaFlowInteractiveReplyJob implements ShouldQueue
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
        $response = MetaFlowResponse::find($this->metaFlowResponseId);

        if (!$response || !$response->response_json) {
            Log::warning('SendMetaFlowInteractiveReplyJob: response not found or empty', [
                'response_id' => $this->metaFlowResponseId,
            ]);
            return;
        }

        $flow = MetaFlow::find($response->meta_flow_id);

        if (!$flow || !$flow->interactive_reply_enabled || !$flow->interactive_reply_config) {
            return;
        }

        $contact = Contact::find($response->contact_id);

        if (!$contact) {
            Log::warning('SendMetaFlowInteractiveReplyJob: contact not found', [
                'contact_id' => $response->contact_id,
            ]);
            return;
        }

        $config = is_array($flow->interactive_reply_config)
            ? $flow->interactive_reply_config
            : json_decode($flow->interactive_reply_config, true) ?? [];

        $bodyText = $config['body'] ?? '';
        $buttons = $config['buttons'] ?? [];

        if (empty($bodyText) || empty($buttons)) {
            Log::warning('SendMetaFlowInteractiveReplyJob: missing body or buttons', [
                'response_id' => $this->metaFlowResponseId,
            ]);
            return;
        }

        // Substitute variables in body text
        $values = MetaFlowResponseNormalizer::normalize($response, $flow, $contact);
        $bodyText = MetaFlowResponseNormalizer::substituteTemplate($bodyText, $values);

        // Ensure buttons have valid IDs and titles (max 3, max 20 chars per title)
        $buttons = array_slice($buttons, 0, 3);
        $buttons = array_map(function ($btn, $idx) {
            return [
                'id'    => $btn['id'] ?? ('btn_' . ($idx + 1)),
                'title' => mb_substr($btn['title'] ?? '', 0, 20),
            ];
        }, $buttons, array_keys($buttons));

        // Filter out buttons with empty titles
        $buttons = array_filter($buttons, fn($btn) => !empty(trim($btn['title'])));

        if (empty($buttons)) {
            Log::warning('SendMetaFlowInteractiveReplyJob: no valid buttons after filtering', [
                'response_id' => $this->metaFlowResponseId,
            ]);
            return;
        }

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

        $result = $whatsappService->sendMessage(
            $contact->uuid,
            $bodyText,
            null,
            'interactive buttons',
            array_values($buttons),
        );

        Log::info('SendMetaFlowInteractiveReplyJob: interactive reply sent', [
            'response_id' => $this->metaFlowResponseId,
            'contact_id'  => $response->contact_id,
            'buttons_count' => count($buttons),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendMetaFlowInteractiveReplyJob: permanent failure', [
            'response_id'    => $this->metaFlowResponseId,
            'organization_id' => $this->organizationId,
            'error'          => $exception->getMessage(),
        ]);
    }
}