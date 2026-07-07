<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\Contact;
use App\Models\Template;
use App\Services\OrganizationService;
use App\Services\WhatsappService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FinalizeCampaignSendResultsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 3;

    public function __construct(
        public int $logId,
        public int $organizationId,
        public int $campaignId,
        public int $templateId,
        public int $contactId,
        public int $createdBy,
        public array $result,
        public array $templateContent,
        public array $flowStubs = [],
    ) {
        $this->queue = config('campaigns.queues.post_send', 'campaign-post-send');
    }

    public function handle(): void
    {
        $wamId = data_get($this->result, 'data.messages.0.id');
        if (! $wamId) {
            Log::warning('Campaign finalizer skipped because WhatsApp message id is missing', [
                'campaign_log_id' => $this->logId,
                'campaign_id' => $this->campaignId,
                'organization_id' => $this->organizationId,
            ]);

            return;
        }

        $lock = Cache::store('redis')->lock($this->lockKey($wamId), 60);
        if (! $lock->get()) {
            $this->release(2);

            return;
        }

        try {
            $log = CampaignLog::find($this->logId);
            if (! $log) {
                return;
            }

            $existingChat = Chat::where('organization_id', $this->organizationId)
                ->where('wam_id', $wamId)
                ->first();

            if ($existingChat) {
                $this->linkCampaignLogToChat($log, $existingChat);
                $this->ensureChatLogExists($existingChat);

                return;
            }

            $contact = Contact::find($this->contactId);
            $campaign = Campaign::find($this->campaignId);
            $template = Template::find($this->templateId);

            if (! $contact || ! $campaign || ! $template) {
                Log::warning('Campaign finalizer missing required model', [
                    'campaign_log_id' => $this->logId,
                    'campaign_id' => $this->campaignId,
                    'template_id' => $this->templateId,
                    'contact_id' => $this->contactId,
                    'has_contact' => (bool) $contact,
                    'has_campaign' => (bool) $campaign,
                    'has_template' => (bool) $template,
                ]);

                return;
            }

            $whatsappService = $this->makeWhatsappService();
            $result = $this->toObject($this->result);
            $chatMetadata = $this->buildChatMetadata($whatsappService, $campaign, $template, $contact);
            $mediaId = $whatsappService->getMediaIdFromCampaign($this->campaignId);

            try {
                $created = $whatsappService->createChatLogFromResponse(
                    $result,
                    $contact,
                    $this->createdBy,
                    $this->organizationId,
                    $chatMetadata,
                    $mediaId,
                    $this->campaignId,
                    $this->flowStubs
                );
            } catch (\Throwable $e) {
                $existingChat = Chat::where('organization_id', $this->organizationId)
                    ->where('wam_id', $wamId)
                    ->first();

                if (! $existingChat) {
                    throw $e;
                }

                $created = ['chat' => $existingChat];
            }

            $this->linkCampaignLogToChat($log, $created['chat']);
            $this->ensureChatLogExists($created['chat']);
        } finally {
            optional($lock)->release();
        }
    }

    private function makeWhatsappService(): WhatsappService
    {
        $config = OrganizationService::getCachedMetadata($this->organizationId);

        return new WhatsappService(
            $config['whatsapp']['access_token'] ?? null,
            'v18.0',
            $config['whatsapp']['app_id'] ?? null,
            $config['whatsapp']['phone_number_id'] ?? null,
            $config['whatsapp']['waba_id'] ?? null,
            $this->organizationId
        );
    }

    private function buildChatMetadata(
        WhatsappService $whatsappService,
        Campaign $campaign,
        Template $template,
        Contact $contact
    ): string {
        $templateMetadata = json_decode($template->metadata, true);
        $isCarousel = is_array($templateMetadata) && ($templateMetadata['template_type'] ?? '') === 'carousel';

        if ($isCarousel) {
            return $whatsappService->buildCarouselTemplateChatMessage(
                $contact->uuid,
                $template->id,
                $template
            );
        }

        return $whatsappService->buildCampaignTemplateChatMessage(
            json_decode($campaign->metadata),
            $contact->uuid,
            $contact,
            null,
            $template
        );
    }

    private function linkCampaignLogToChat(CampaignLog $log, Chat $chat): void
    {
        if ((int) $log->chat_id === (int) $chat->id) {
            return;
        }

        $log->chat_id = $chat->id;
        $log->save();
    }

    private function ensureChatLogExists(Chat $chat): void
    {
        $exists = ChatLog::where('entity_type', 'chat')
            ->where('entity_id', $chat->id)
            ->exists();

        if ($exists) {
            return;
        }

        ChatLog::insert([
            'contact_id' => $chat->contact_id,
            'entity_type' => 'chat',
            'entity_id' => $chat->id,
            'created_at' => now(),
        ]);
    }

    private function toObject(array $payload): object
    {
        return json_decode(json_encode($payload));
    }

    private function lockKey(string $wamId): string
    {
        return 'campaign:finalize:'.$this->organizationId.':'.sha1($wamId);
    }
}
