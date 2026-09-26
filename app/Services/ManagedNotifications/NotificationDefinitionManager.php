<?php

namespace App\Services\ManagedNotifications;

use App\Models\NotificationDefinition;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class NotificationDefinitionManager
{
    public function __construct(
        private readonly NotificationCatalog $catalog,
        private readonly NotificationTemplateRenderer $renderer,
        private readonly WebhookUrlValidator $webhookUrls,
    ) {
    }

    public function update(string $typeKey, array $input, int $adminId): NotificationDefinition
    {
        $type = $this->catalog->get($typeKey);
        $mode = $input['delivery_mode'];

        if (! in_array($mode, ['dashboard', 'email', 'dashboard_email'], true)) {
            throw new InvalidArgumentException('Invalid delivery mode.');
        }

        foreach (['dashboard_template', 'email_subject', 'email_body'] as $field) {
            $this->renderer->validate($input[$field], $type['variables']);
        }

        if (preg_match('/[\r\n]/', $input['email_subject']) || mb_strlen($input['email_subject']) > 191) {
            throw new InvalidArgumentException('Email subject is invalid.');
        }

        $sender = strtolower(trim((string) ($input['sender_email'] ?? '')));
        $allowed = $this->catalog->approvedSenders();
        if (! filter_var($sender, FILTER_VALIDATE_EMAIL) || ! in_array($sender, $allowed, true)) {
            throw new InvalidArgumentException('Choose an approved sender email address.');
        }

        $url = trim((string) ($input['webhook_url'] ?? '')) ?: null;
        $this->webhookUrls->validate($url);
        $this->catalog->ensureDefinitions();

        return DB::transaction(function () use ($typeKey, $input, $mode, $sender, $url, $adminId) {
            $definition = NotificationDefinition::query()->where('type_key', $typeKey)->lockForUpdate()->firstOrFail();
            if ((int) $input['revision'] !== $definition->revision) {
                throw new InvalidArgumentException('Notification settings changed; reload before saving.');
            }

            $secret = trim((string) ($input['webhook_secret'] ?? ''));
            if ($url && $secret === '' && ! $definition->webhook_secret) {
                throw new InvalidArgumentException('A webhook signing secret is required.');
            }
            if ($secret !== '' && (strlen($secret) < 32 || strlen($secret) > 128)) {
                throw new InvalidArgumentException('Webhook signing secret must be 32 to 128 characters.');
            }

            $definition->fill([
                'enabled' => (bool) $input['enabled'],
                'delivery_mode' => $mode,
                'dashboard_template' => $input['dashboard_template'],
                'email_subject' => $input['email_subject'],
                'email_body' => $input['email_body'],
                'sender_email' => $sender,
                'webhook_url' => $url,
                'webhook_secret' => $url ? ($secret ?: $definition->webhook_secret) : null,
                'updated_by' => $adminId,
                'revision' => $definition->revision + 1,
            ]);
            $definition->save();

            return $definition;
        });
    }
}
