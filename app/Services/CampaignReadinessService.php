<?php

namespace App\Services;

/**
 * Pre-send readiness checks for campaigns.
 *
 * Deliberately pure (no queries, no side effects): CampaignService passes the
 * organization metadata it already loaded and receives the blocking/warning
 * items back, so the API layer, the campaign form and any future surface can
 * share one source of truth instead of each re-implementing its own checks.
 *
 * Missing or unknown connection state blocks sending until the sender can be
 * verified as connected. Unknown quality remains a warning, not a blocker.
 */
final class CampaignReadinessService
{
    public const KEY_NUMBER_DISCONNECTED = 'number_disconnected';
    public const KEY_NUMBER_UNKNOWN = 'number_unknown';
    public const KEY_QUALITY_LOW = 'quality_low';
    public const KEY_TEMPLATE_NOT_APPROVED = 'template_not_approved';

    /** Template statuses Meta treats as usable for outbound messaging. */
    private const SENDABLE_TEMPLATE_STATUSES = ['APPROVED', 'ACTIVE'];

    /**
     * Checks that need the customer to fix something before we can send.
     * Wording follows What happened → Why it matters → What to do next.
     */
    private const BLOCKERS = [
        self::KEY_NUMBER_DISCONNECTED => "Your WhatsApp number isn't connected, so this campaign can't be sent yet. Reconnect your number, then create the campaign again.",
        self::KEY_NUMBER_UNKNOWN => "We couldn't verify that your WhatsApp number is connected. Sync or reconnect it before sending this campaign.",
        self::KEY_TEMPLATE_NOT_APPROVED => "This message template isn't approved yet, so it can't be used in a campaign. Pick another template or wait for Meta's review to finish.",
    ];

    /** Checks that deserve attention but must not stop an informed business user. */
    private const WARNINGS = [
        self::KEY_QUALITY_LOW => "Your number's quality rating is low. Sending a large campaign now may put your number at risk — consider a smaller, more engaged audience first.",
    ];

    /**
     * @param  array       $metadata       Organization metadata (the array CampaignService already reads).
     * @param  string|null $templateStatus Optional templates.status value of the template being sent.
     * @return array{ready: bool, blockers: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
     */
    public function check(array $metadata, ?string $templateStatus = null, ?string $templateCategory = null, bool $hasUnsubscribeAction = false): array
    {
        $whatsapp = is_array($metadata['whatsapp'] ?? null) ? $metadata['whatsapp'] : [];

        $blockers = [];
        $warnings = [];

        $numberStatus = $this->normalize($whatsapp['number_status'] ?? null);

        if ($numberStatus === WhatsappNumberStatusService::STATUS_DISCONNECTED) {
            $blockers[] = $this->item(self::KEY_NUMBER_DISCONNECTED, self::BLOCKERS[self::KEY_NUMBER_DISCONNECTED]);
        } elseif ($numberStatus === '' || $numberStatus === WhatsappNumberStatusService::STATUS_UNKNOWN) {
            $blockers[] = $this->item(self::KEY_NUMBER_UNKNOWN, self::BLOCKERS[self::KEY_NUMBER_UNKNOWN]);
        }

        $quality = $this->normalize($whatsapp['quality_rating'] ?? null);
        if (in_array($quality, ['LOW', 'RED'], true)) {
            $warnings[] = $this->item(self::KEY_QUALITY_LOW, self::WARNINGS[self::KEY_QUALITY_LOW]);
        }

        $status = $this->normalize($templateStatus);
        $templateReady = $status !== '' && in_array($status, self::SENDABLE_TEMPLATE_STATUSES, true);
        if ($status !== '' && ! in_array($status, self::SENDABLE_TEMPLATE_STATUSES, true)) {
            $blockers[] = $this->item(self::KEY_TEMPLATE_NOT_APPROVED, self::BLOCKERS[self::KEY_TEMPLATE_NOT_APPROVED]);
        }

        $unsubscribeReady = $this->normalize($templateCategory) !== 'MARKETING'
            || ($hasUnsubscribeAction && trim((string) ($metadata['campaign_settings']['unsubscribe_trigger'] ?? 'stop')) !== '');
        if (! $unsubscribeReady) {
            $warnings[] = $this->item(
                'unsubscribe_missing',
                __('This marketing template has no unsubscribe button. You can still send the campaign, but adding one helps recipients opt out.')
            );
        }

        return [
            'ready' => $blockers === [],
            'number_ready' => $numberStatus === WhatsappNumberStatusService::STATUS_CONNECTED,
            'template_ready' => $templateReady,
            'unsubscribe_ready' => $unsubscribeReady,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{key: string, message: string, action: array{path: string, label: string}}
     */
    private function item(string $key, string $message): array
    {
        return [
            'key' => $key,
            'message' => __($message),
            'action' => $this->actionFor($key),
        ];
    }

    /** Direct remediation: never make the customer search the platform for the fix. */
    private function actionFor(string $key): array
    {
        return match ($key) {
            self::KEY_NUMBER_DISCONNECTED, self::KEY_NUMBER_UNKNOWN, self::KEY_QUALITY_LOW => [
                'path' => '/settings/whatsapp',
                'label' => __('Fix WhatsApp connection'),
            ],
            self::KEY_TEMPLATE_NOT_APPROVED => [
                'path' => '/templates',
                'label' => __('View templates'),
            ],
            default => ['path' => '/settings', 'label' => __('Open settings')],
        };
    }

    private function normalize(mixed $value): string
    {
        return strtoupper(trim((string) $value));
    }
}
