<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Services\OrganizationMailConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\MetaFlows\Models\MetaFlow;
use Modules\MetaFlows\Models\MetaFlowResponse;
use Modules\MetaFlows\Services\MetaFlowResponseNormalizer;

class SendMetaFlowEmailNotificationJob implements ShouldQueue
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
            Log::warning('SendMetaFlowEmailNotificationJob: response not found or empty', [
                'response_id' => $this->metaFlowResponseId,
            ]);
            return;
        }

        $flow = MetaFlow::find($response->meta_flow_id);

        if (!$flow || !$flow->email_notification_enabled || !$flow->email_notification_config) {
            return;
        }

        $config = is_array($flow->email_notification_config)
            ? $flow->email_notification_config
            : json_decode($flow->email_notification_config, true) ?? [];

        $recipients = $config['recipients'] ?? [];

        if (empty($recipients)) {
            Log::warning('SendMetaFlowEmailNotificationJob: no recipients configured', [
                'response_id' => $this->metaFlowResponseId,
            ]);
            return;
        }

        $contact = Contact::find($response->contact_id);

        // Build normalized values and substitute in templates
        $values = MetaFlowResponseNormalizer::normalize($response, $flow, $contact);

        // Build a readable plain-text body from the submitted data if no template is provided
        $defaultSubject = 'New submission for: ' . $flow->name;
        $subjectTemplate = $config['subject_template'] ?? $defaultSubject;
        $bodyTemplate = $config['body_template'] ?? '';

        $subject = MetaFlowResponseNormalizer::substituteTemplate($subjectTemplate, $values);

        if (!empty($bodyTemplate)) {
            $body = MetaFlowResponseNormalizer::substituteTemplate($bodyTemplate, $values);
        } else {
            // Default: format submitted data as a readable list
            $body = "New submission received for: {$flow->name}\n\n";
            foreach ($values as $key => $value) {
                if (!str_starts_with($key, '__') && !empty($value)) {
                    $label = ucfirst(str_replace('_', ' ', $key));
                    $body .= "{$label}: {$value}\n";
                }
            }
            if (!empty($values['__contact_name'])) {
                $body .= "\nContact: {$values['__contact_name']} ({$values['__contact_phone']})";
            }
            $body .= "\nSubmitted at: {$values['__timestamp']}";
        }

        $mailService = new OrganizationMailConfigService();
        $sentCount = 0;

        foreach ($recipients as $email) {
            $email = trim($email);
            if (empty($email)) {
                continue;
            }

            try {
                $sent = $mailService->sendCustomEmail($this->organizationId, $email, $subject, $body);
                if ($sent) {
                    $sentCount++;
                }
            } catch (\Throwable $e) {
                Log::warning('SendMetaFlowEmailNotificationJob: failed to send to recipient', [
                    'response_id' => $this->metaFlowResponseId,
                    'email'       => $email,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        Log::info('SendMetaFlowEmailNotificationJob: emails sent', [
            'response_id'    => $this->metaFlowResponseId,
            'total_recipients' => count($recipients),
            'sent_count'     => $sentCount,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendMetaFlowEmailNotificationJob: permanent failure', [
            'response_id'    => $this->metaFlowResponseId,
            'organization_id' => $this->organizationId,
            'error'          => $exception->getMessage(),
        ]);
    }
}