<?php

namespace App\Services\Meta\WhatsApp;

use App\Models\Organization;
use App\Models\Template;
use App\Services\WhatsappService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TemplateStatusWebhookProcessor
{
    public function __construct(
        private readonly TemplateStatusMapper $mapper,
        private readonly TemplateStatusNotificationRecorder $notifications
    ) {
    }

    public function process(array $change, int $organizationId = 0, array $context = []): void
    {
        $value = is_array($change['value'] ?? null) ? $change['value'] : [];
        $mapping = $this->mapper->map($value['event'] ?? null);
        $wabaId = $context['waba_id'] ?? null;
        $templateId = isset($value['message_template_id']) ? (string) $value['message_template_id'] : null;
        $templateName = $value['message_template_name'] ?? null;
        $templateLanguage = $value['message_template_language'] ?? null;
        $templateCategory = $this->extractTemplateCategory($value);
        $requestId = $context['request_id'] ?? null;
        $hasKnownStatus = $mapping['normalized_status'] !== TemplateStatusMapper::UNKNOWN_INTERNAL_STATUS;
        $isCategoryUpdate = ($change['field'] ?? null) === 'template_category_update';
        $organization = $organizationId > 0
            ? Organization::find($organizationId)
            : $this->findOrganizationByWabaId($wabaId);

        $this->log()->info('Template status webhook received', [
            'organization_id' => $organization?->id,
            'waba_id' => $wabaId,
            'template_id' => $templateId,
            'template_name' => $templateName,
            'template_language' => $templateLanguage,
            'template_category' => $templateCategory,
            'event_type' => $mapping['event'],
            'request_id' => $requestId,
            'raw_payload' => $context['raw_payload'] ?? $change,
        ]);

        try {
            if (! $organization) {
                $this->log()->warning('Template status webhook skipped', [
                    'organization_id' => null,
                    'waba_id' => $wabaId,
                    'template_id' => $templateId,
                    'template_name' => $templateName,
                    'template_language' => $templateLanguage,
                    'template_category' => $templateCategory,
                    'event_type' => $mapping['event'],
                    'reason' => 'Organization not found for WABA ID',
                    'request_id' => $requestId,
                ]);

                return;
            }

            if ($wabaId !== null && (string) data_get(json_decode($organization->metadata ?: '{}', true), 'whatsapp.waba_id') !== (string) $wabaId) {
                return;
            }

            if ($isCategoryUpdate) {
                $this->syncTemplates($organization, $requestId);

                return;
            }

            $template = $organization ? $this->findTemplate($organization->id, $value) : null;

            if (! $template) {
                $this->log()->warning('Template status webhook skipped', [
                    'organization_id' => $organization->id,
                    'waba_id' => $wabaId,
                    'template_id' => $templateId,
                    'template_name' => $templateName,
                    'template_language' => $templateLanguage,
                    'template_category' => $templateCategory,
                    'event_type' => $mapping['event'],
                    'reason' => 'Template not found',
                    'request_id' => $requestId,
                ]);

                return;
            }

            if (! $hasKnownStatus && ! $isCategoryUpdate) {
                $this->log()->warning('Unknown template status event received', [
                    'organization_id' => $organization->id,
                    'template_id' => $templateId,
                    'template_name' => $templateName,
                    'template_language' => $templateLanguage,
                    'template_category' => $templateCategory,
                    'event_type' => $mapping['event'],
                    'request_id' => $requestId,
                ]);

                if (! $templateCategory) {
                    return;
                }
            }

            DB::transaction(function () use ($template, $organization, $mapping, $value, $templateCategory, $hasKnownStatus, $wabaId) {
                $organization = Organization::query()->lockForUpdate()->find($organization->id);
                if (! $organization || ($wabaId !== null
                    && (string) data_get(json_decode($organization->metadata ?: '{}', true), 'whatsapp.waba_id') !== (string) $wabaId)) {
                    return;
                }
                $template = Template::query()->where('organization_id', $organization->id)
                    ->lockForUpdate()->findOrFail($template->id);
                $oldStatus = (string) $template->status;
                if ($templateCategory) {
                    $template->category = $templateCategory;
                }

                if ($hasKnownStatus) {
                    $template->status = $mapping['template_status'] ?? $template->status;
                    if ($oldStatus !== $template->status) {
                        $template->last_status_change_at = now();
                        $template->notification_transition_sequence++;
                    }

                    if (($mapping['event'] ?? null) === 'REJECTED') {
                        $template->last_rejection_reason = $value['reason'] ?? null;
                    } elseif ($mapping['clears_rejection']) {
                        $template->last_rejection_reason = null;
                    }

                    if ($mapping['quality_state'] !== null) {
                        $template->quality_state = $mapping['quality_state'];
                    } elseif ($mapping['clears_disabled_at']) {
                        $template->quality_state = null;
                    }

                    if (($mapping['event'] ?? null) === 'DISABLED') {
                        $disableDate = $value['disable_info']['disable_date'] ?? null;
                        $template->disabled_at = is_numeric($disableDate)
                            ? Carbon::createFromTimestamp((int) $disableDate)
                            : now();
                    } elseif ($mapping['clears_disabled_at']) {
                        $template->disabled_at = null;
                    }
                }

                $template->updated_at = now();
                $template->save();
                if ($hasKnownStatus) {
                    $this->notifications->record($template, $organization, $oldStatus, (string) $template->status);
                }
            });

            $this->log()->info('Template status webhook processed', [
                'organization_id' => $organization->id,
                'template_id' => $templateId,
                'template_name' => $templateName,
                'template_language' => $templateLanguage,
                'template_category' => $templateCategory,
                'event_type' => $mapping['event'],
                'request_id' => $requestId,
            ]);
        } catch (\Throwable $e) {
            $this->log()->error('Template status webhook processing failed', [
                'organization_id' => $organization?->id,
                'template_id' => $templateId,
                'template_name' => $templateName,
                'template_language' => $templateLanguage,
                'template_category' => $templateCategory,
                'event_type' => $mapping['event'],
                'error' => $e->getMessage(),
                'request_id' => $requestId,
            ]);

            throw $e;
        }
    }

    private function log()
    {
        return Log::channel('template_status_webhook');
    }

    protected function syncTemplates(Organization $organization, ?string $requestId): void
    {
        $metadata = json_decode($organization->metadata ?: '{}', true);
        $whatsapp = is_array($metadata['whatsapp'] ?? null) ? $metadata['whatsapp'] : [];
        $accessToken = $whatsapp['access_token'] ?? null;
        $wabaId = $whatsapp['waba_id'] ?? null;

        if (! is_string($accessToken) || trim($accessToken) === '' || ! is_string($wabaId) || trim($wabaId) === '') {
            throw new \RuntimeException('Cannot synchronize templates: WhatsApp access token or WABA ID is missing.');
        }

        $service = new WhatsappService(
            $accessToken,
            config('graph.api_version'),
            $whatsapp['app_id'] ?? null,
            $whatsapp['phone_number_id'] ?? null,
            $wabaId,
            $organization->id
        );

        $this->log()->info('Template category update triggered template sync', [
            'organization_id' => $organization->id,
            'waba_id' => $wabaId,
            'request_id' => $requestId,
        ]);

        $result = $service->syncTemplates();

        if (($result->success ?? true) === false) {
            $message = $result->message
                ?? data_get($result, 'data.error.error_user_msg')
                ?? data_get($result, 'data.error.message')
                ?? 'Template synchronization failed';

            throw new \RuntimeException($message);
        }

        $this->log()->info('Template sync completed after category update', [
            'organization_id' => $organization->id,
            'waba_id' => $wabaId,
            'request_id' => $requestId,
        ]);
    }

    private function findTemplate(int $organizationId, array $value): ?Template
    {
        $templateId = isset($value['message_template_id']) ? (string) $value['message_template_id'] : null;

        if ($templateId) {
            $template = Template::where('organization_id', $organizationId)
                ->where('meta_id', $templateId)
                ->first();

            if ($template) {
                return $template;
            }
        }

        $name = $value['message_template_name'] ?? null;
        $language = $value['message_template_language'] ?? null;

        if (! $name || ! $language) {
            return null;
        }

        return Template::where('organization_id', $organizationId)
            ->where('name', $name)
            ->where('language', $language)
            ->first();
    }

    private function extractTemplateCategory(array $value): ?string
    {
        foreach ([
            'message_template_category',
            'new_category',
            'category',
            'template_category',
        ] as $key) {
            $category = $value[$key] ?? null;

            if (is_string($category) && trim($category) !== '') {
                return strtoupper(trim($category));
            }
        }

        return null;
    }

    protected function findOrganizationByWabaId(?string $wabaId): ?Organization
    {
        if (! $wabaId) {
            return null;
        }

        return Organization::whereRaw(
            "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.whatsapp.waba_id')) = ?",
            [$wabaId]
        )->first();
    }
}
