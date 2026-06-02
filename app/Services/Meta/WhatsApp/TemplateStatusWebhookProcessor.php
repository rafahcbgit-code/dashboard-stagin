<?php

namespace App\Services\Meta\WhatsApp;

use App\Models\Organization;
use App\Models\Template;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TemplateStatusWebhookProcessor
{
    public function __construct(
        private readonly TemplateStatusMapper $mapper
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
        $requestId = $context['request_id'] ?? null;
        $organization = $organizationId > 0
            ? Organization::find($organizationId)
            : $this->findOrganizationByWabaId($wabaId);

        $this->log()->info('Template status webhook received', [
            'organization_id' => $organization?->id,
            'waba_id' => $wabaId,
            'template_id' => $templateId,
            'template_name' => $templateName,
            'template_language' => $templateLanguage,
            'event_type' => $mapping['event'],
            'request_id' => $requestId,
            'raw_payload' => $context['raw_payload'] ?? $change,
        ]);

        try {
            $template = $organization ? $this->findTemplate($organization->id, $value) : null;

            if (! $organization || ! $template) {
                $this->log()->warning('Template status webhook skipped', [
                    'organization_id' => $organization?->id,
                    'waba_id' => $wabaId,
                    'template_id' => $templateId,
                    'template_name' => $templateName,
                    'template_language' => $templateLanguage,
                    'event_type' => $mapping['event'],
                    'reason' => ! $organization ? 'Organization not found for WABA ID' : 'Template not found',
                    'request_id' => $requestId,
                ]);

                return;
            }

            if ($mapping['normalized_status'] === TemplateStatusMapper::UNKNOWN_INTERNAL_STATUS) {
                $this->log()->warning('Unknown template status event received', [
                    'organization_id' => $organization->id,
                    'template_id' => $templateId,
                    'template_name' => $templateName,
                    'template_language' => $templateLanguage,
                    'event_type' => $mapping['event'],
                    'request_id' => $requestId,
                ]);

                return;
            }

            DB::transaction(function () use ($template, $mapping, $value) {
                $template->status = $mapping['template_status'] ?? $template->status;
                $template->last_status_change_at = now();

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

                $template->updated_at = now();
                $template->save();
            });

            $this->sendStatusChangeEmail($organization, $template, $mapping['event'], $requestId);

            $this->log()->info('Template status webhook processed', [
                'organization_id' => $organization->id,
                'template_id' => $templateId,
                'template_name' => $templateName,
                'template_language' => $templateLanguage,
                'event_type' => $mapping['event'],
                'request_id' => $requestId,
            ]);
        } catch (\Throwable $e) {
            $this->log()->error('Template status webhook processing failed', [
                'organization_id' => $organization?->id,
                'template_id' => $templateId,
                'template_name' => $templateName,
                'template_language' => $templateLanguage,
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

    private function sendStatusChangeEmail(Organization $organization, Template $template, string $eventType, ?string $requestId): void
    {
        $recipientEmail = 'rafah@chatberry.net';
        $templateName = $template->name ?: 'Unknown template';
        $organizationName = $organization->name ?: 'Unknown organization';

        $message = implode("\n", [
            'This is a notification from Chatberry.',
            '',
            'A WhatsApp template status webhook was received and processed successfully.',
            "Template name: {$templateName}",
            "New status: {$eventType}",
            "Organization: {$organizationName}",
            '',
            'Chatberry Notification',
        ]);

        $this->log()->info('Template status email notification sending', [
            'organization_id' => $organization->id,
            'template_id' => $template->meta_id,
            'template_name' => $templateName,
            'event_type' => $eventType,
            'recipient_email' => $recipientEmail,
            'request_id' => $requestId,
        ]);

        try {
            Mail::raw($message, function ($mail) use ($recipientEmail, $templateName, $eventType) {
                $mail->to($recipientEmail)
                    ->subject("Chatberry template status update: {$templateName} - {$eventType}");
            });

            $this->log()->info('Template status email notification sent', [
                'organization_id' => $organization->id,
                'template_id' => $template->meta_id,
                'template_name' => $templateName,
                'event_type' => $eventType,
                'recipient_email' => $recipientEmail,
                'request_id' => $requestId,
            ]);
        } catch (\Throwable $e) {
            $this->log()->error('Template status email notification failed', [
                'organization_id' => $organization->id,
                'template_id' => $template->meta_id,
                'template_name' => $templateName,
                'event_type' => $eventType,
                'recipient_email' => $recipientEmail,
                'error' => $e->getMessage(),
                'request_id' => $requestId,
            ]);
        }
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

    private function findOrganizationByWabaId(?string $wabaId): ?Organization
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
