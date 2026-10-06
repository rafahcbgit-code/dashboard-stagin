<?php

namespace App\Services;

use App\Models\Label;
use App\Models\Team;
use Illuminate\Support\Facades\Cache;

final class PreSetupService
{
    private const CACHE_TTL_SECONDS = 300;

    /** @var array<int, string> */
    private const TEAM_STEP_IDS = ['team_members', 'conversation_distribution', 'ticketing', 'email_notifications'];

    /**
     * @return array{steps: array<int, array<string, mixed>>, summary: array<string, int>, mandatoryReady: bool, dismissed: bool}
     */
    public function forOrganization(int $organizationId, bool $isAgent, bool $dismissed, array $metadata, array $counts): array
    {
        $signals = Cache::remember(
            "org_{$organizationId}_pre_setup_signals_v2",
            self::CACHE_TTL_SECONDS,
            fn () => $this->completionSignals($organizationId, $metadata, $counts)
        );

        $definitions = $this->definitions();
        $steps = [];
        foreach ($definitions as $definition) {
            if ($isAgent && in_array($definition['id'], self::TEAM_STEP_IDS, true)) {
                continue;
            }

            $steps[] = [
                ...$definition,
                'title' => __($definition['title']),
                'why' => __($definition['why']),
                'description' => isset($definition['description']) ? __($definition['description']) : null,
                'completed' => (bool) ($signals[$definition['id']] ?? false),
            ];
        }

        $prerequisiteIds = ['whatsapp_connected', 'timezone', 'contacts_imported', 'unsubscribe_configured', 'first_template'];
        $mandatoryReady = collect($prerequisiteIds)->every(fn ($id) => (bool) ($signals[$id] ?? false));
        $reviewItems = $this->readinessReviewItems($steps, $metadata, $counts);
        foreach ($steps as &$step) {
            if ($step['id'] === 'readiness_review') {
                $step['review'] = [
                    'total' => count($reviewItems),
                    'completed' => count(array_filter($reviewItems, fn ($item) => $item['completed'])),
                    'needsAction' => count(array_filter($reviewItems, fn ($item) => in_array($item['state'], ['needs_action', 'blocked'], true))),
                    'blocked' => count(array_filter($reviewItems, fn ($item) => $item['state'] === 'blocked')),
                    'unknown' => count(array_filter($reviewItems, fn ($item) => $item['state'] === 'unknown')),
                    'items' => $reviewItems,
                    'advisories' => $this->readinessAdvisories($metadata),
                ];
            }
        }
        unset($step);

        $total = count($steps);
        $completed = count(array_filter($steps, fn ($step) => $step['completed']));
        $mandatoryPending = count(array_filter($steps, fn ($step) => $step['mandatory'] && ! $step['completed']));

        return [
            'steps' => $steps,
            'summary' => compact('total', 'completed', 'mandatoryPending'),
            'mandatoryReady' => $mandatoryReady,
            'dismissed' => $dismissed && ! $mandatoryReady,
        ];
    }

    /** @return array<string, bool> */
    private function completionSignals(int $organizationId, array $metadata, array $counts): array
    {
        $whatsapp = is_array($metadata['whatsapp'] ?? null) ? $metadata['whatsapp'] : [];
        $tickets = is_array($metadata['tickets'] ?? null) ? $metadata['tickets'] : [];
        $emailSettings = is_array($metadata['email_settings'] ?? null) ? $metadata['email_settings'] : [];
        $unsubscribe = $metadata['campaign_settings']['unsubscribe_trigger'] ?? null;

        $signals = [
            'whatsapp_connected' => ! empty($whatsapp['phone_number_id']) && strtoupper((string) ($whatsapp['number_status'] ?? '')) === 'CONNECTED',
            // TODO(phase-1b): Add a guarded owner-only test-send flow; dashboard rendering never sends messages.
            'whatsapp_test_message' => ! empty($metadata['pre_setup']['test_message_sent_at']),
            'timezone' => is_string($metadata['timezone'] ?? null) && trim($metadata['timezone']) !== '',
            'working_hours' => $this->hasWorkingHours($metadata),
            'team_members' => Team::query()->where('organization_id', $organizationId)->whereNull('deleted_at')->count() > 1,
            'conversation_distribution' => ! empty($tickets['auto_assignment']),
            'ticketing' => ! empty($tickets['active']),
            'email_notifications' => ! empty($emailSettings['active']) && ! empty($emailSettings['host']) && ! empty($emailSettings['username']),
            'contacts_imported' => (int) ($counts['contacts'] ?? 0) > 0,
            'unsubscribe_configured' => is_string($unsubscribe) && trim($unsubscribe) !== '',
            'labels_created' => Label::query()->where('organization_id', $organizationId)->whereNull('deleted_at')->exists(),
            'first_template' => (int) ($counts['approved_templates'] ?? 0) > 0,
            'first_campaign' => (int) ($counts['campaigns'] ?? 0) > 0,
            'readiness_review' => false,
        ];

        $signals['readiness_review'] = collect([
            'whatsapp_connected', 'timezone', 'contacts_imported', 'unsubscribe_configured', 'first_template',
        ])->every(fn ($id) => $signals[$id]);

        return $signals;
    }

    private function hasWorkingHours(array $metadata): bool
    {
        if (array_key_exists('enable_working_hours', $metadata) && ! (bool) $metadata['enable_working_hours']) {
            return true;
        }

        $hours = $metadata['working_hours'] ?? null;
        return is_array($hours) && $hours !== [];
    }

    /** @return array<int, array{id: string, title: string, detail: string, completed: bool, route: string}> */
    private function readinessReviewItems(array $steps, array $metadata, array $counts): array
    {
        $requiredIds = ['whatsapp_connected', 'timezone', 'contacts_imported', 'unsubscribe_configured', 'first_template'];
        $stepById = collect($steps)->keyBy('id');

        return collect($requiredIds)->map(function (string $id) use ($stepById, $metadata, $counts): array {
            $step = $stepById->get($id);
            $detail = match ($id) {
                'whatsapp_connected' => $this->whatsappConnectionDetail($metadata),
                'timezone' => $this->timezoneDetail($metadata),
                'contacts_imported' => __(':count contacts are in your account, including unsubscribed contacts.', ['count' => number_format((int) ($counts['contacts'] ?? 0))]),
                'unsubscribe_configured' => $step['completed']
                    ? __('An unsubscribe keyword is configured.')
                    : __('No unsubscribe keyword is configured yet.'),
                'first_template' => __(':count approved templates are available.', ['count' => number_format((int) ($counts['approved_templates'] ?? 0))]),
                default => '',
            };

            return [
                'id' => $id,
                'title' => $step['title'],
                'detail' => $detail,
                'alertTitle' => $id === 'whatsapp_connected' && ! $step['completed']
                    ? $this->whatsappConnectionAlertTitle($metadata)
                    : null,
                'completed' => $step['completed'],
                'state' => $step['completed'] ? 'complete' : ($id === 'whatsapp_connected' ? $this->whatsappConnectionState($metadata) : 'needs_action'),
                'actionLabel' => $this->readinessActionLabel($id, $step['completed'], $metadata),
                'route' => $step['route'],
            ];
        })->all();
    }

    private function whatsappConnectionState(array $metadata): string
    {
        $whatsapp = is_array($metadata['whatsapp'] ?? null) ? $metadata['whatsapp'] : [];
        if (empty($whatsapp['phone_number_id'])) {
            return 'needs_action';
        }

        return strtoupper(trim((string) ($whatsapp['number_status'] ?? ''))) === 'DISCONNECTED'
            ? 'blocked'
            : 'unknown';
    }

    private function whatsappConnectionDetail(array $metadata): string
    {
        $whatsapp = is_array($metadata['whatsapp'] ?? null) ? $metadata['whatsapp'] : [];
        $status = strtoupper(trim((string) ($whatsapp['number_status'] ?? '')));
        $phoneNumber = trim((string) ($whatsapp['display_phone_number'] ?? ''));

        if (empty($whatsapp['phone_number_id'])) {
            return __('No WhatsApp number is connected yet. Set up a number to send campaigns and reply to customers.');
        }

        if (! empty($whatsapp['phone_number_id']) && $status === 'CONNECTED') {
            return $phoneNumber !== ''
                ? __('WhatsApp number :number is connected.', ['number' => $phoneNumber])
                : __('WhatsApp number is connected.');
        }

        if ($status === 'DISCONNECTED') {
            return $phoneNumber !== ''
                ? __('Reconnect :number to resume sending campaigns and replying to customers.', ['number' => $phoneNumber])
                : __('Reconnect your WhatsApp number to resume sending campaigns and replying to customers.');
        }

        return $phoneNumber !== ''
            ? __('We could not confirm the connection status for :number. Check the WhatsApp settings.', ['number' => $phoneNumber])
            : __('We could not confirm the WhatsApp connection status. Check the WhatsApp settings.');
    }

    private function whatsappConnectionAlertTitle(array $metadata): string
    {
        $whatsapp = is_array($metadata['whatsapp'] ?? null) ? $metadata['whatsapp'] : [];
        if (empty($whatsapp['phone_number_id'])) {
            return __('WhatsApp number is not connected');
        }

        return strtoupper(trim((string) ($whatsapp['number_status'] ?? ''))) === 'DISCONNECTED'
            ? __('WhatsApp number disconnected — campaigns and replies are unavailable right now.')
            : __('WhatsApp connection status could not be confirmed.');
    }

    private function readinessActionLabel(string $id, bool $completed, array $metadata): string
    {
        if ($completed) {
            return __('Review settings');
        }

        if ($id === 'whatsapp_connected') {
            return match ($this->whatsappConnectionState($metadata)) {
                'blocked' => __('Reconnect'),
                'unknown' => __('Check status'),
                default => __('Set up'),
            };
        }

        return match ($id) {
            'timezone' => __('Set timezone'),
            'contacts_imported' => __('Import contacts'),
            'unsubscribe_configured' => __('Configure'),
            'first_template' => __('Create template'),
            default => __('Set up'),
        };
    }

    private function timezoneDetail(array $metadata): string
    {
        $timezone = trim((string) ($metadata['timezone'] ?? ''));

        return $timezone !== ''
            ? __('Timezone: :timezone', ['timezone' => $timezone])
            : __('Timezone has not been set.');
    }

    /** @return array<int, array{label: string, value: string, tone: string}> */
    private function readinessAdvisories(array $metadata): array
    {
        $whatsapp = is_array($metadata['whatsapp'] ?? null) ? $metadata['whatsapp'] : [];
        if (empty($whatsapp['phone_number_id'])) {
            return [];
        }

        $advisories = [];
        $quality = strtoupper(trim((string) ($whatsapp['quality_rating'] ?? '')));
        $qualityDisplay = match ($quality) {
            'GREEN' => [__('Good'), 'success'],
            'YELLOW' => [__('Medium'), 'warning'],
            'RED', 'LOW' => [__('Low'), 'error'],
            default => [__('Not available'), 'neutral'],
        };
        $advisories[] = [
            'label' => __('WhatsApp number quality'),
            'value' => $qualityDisplay[0],
            'tone' => $qualityDisplay[1],
        ];

        $limit = $this->formatDailyMessagingLimit($whatsapp['whatsapp_business_manager_messaging_limit'] ?? null);
        if ($limit !== null) {
            $advisories[] = [
                'label' => __('Daily messaging limit'),
                'value' => $limit,
                'tone' => 'neutral',
            ];
        }

        return $advisories;
    }

    private function formatDailyMessagingLimit(mixed $rawLimit): ?string
    {
        if (! is_string($rawLimit) && ! is_int($rawLimit)) {
            return null;
        }

        $value = strtoupper(trim((string) $rawLimit));
        if ($value === '') {
            return null;
        }
        if (in_array($value, ['UNLIMITED', 'TIER_UNLIMITED'], true)) {
            return __('Unlimited tier');
        }

        if (is_numeric($value)) {
            return __(':count conversations per day', ['count' => number_format((int) $value)]);
        }

        if (preg_match('/^TIER_(\d+(?:\.\d+)?)([KM]?)$/', $value, $matches) !== 1) {
            return null;
        }

        $number = (float) $matches[1];
        $count = match ($matches[2]) {
            'K' => (int) ($number * 1000),
            'M' => (int) ($number * 1000000),
            default => (int) $number,
        };

        return __(':count conversations per day', ['count' => number_format($count)]);
    }

    /** @return array<int, array{id: string, group: string, title: string, why: string, mandatory: bool, route: string}> */
    private function definitions(): array
    {
        return [
            ['id' => 'whatsapp_connected', 'group' => 'connection', 'title' => 'Connect your WhatsApp number', 'why' => 'A connected number is required before the organization can send WhatsApp messages.', 'mandatory' => true, 'route' => '/settings/whatsapp'],
            ['id' => 'whatsapp_test_message', 'group' => 'connection', 'title' => 'Send a test message', 'why' => 'Testing a conversation can confirm that replies and approved templates work.', 'description' => 'To test a reply, ask a contact to message you first, then reply in their conversation. To start the conversation yourself, create and submit a WhatsApp template for approval. Once approved, open the contact’s conversation and send the template there.', 'mandatory' => false, 'route' => '/chats'],
            ['id' => 'unsubscribe_configured', 'group' => 'campaign', 'title' => 'Configure unsubscribe keywords', 'why' => 'An unsubscribe keyword lets contacts opt out of campaign messages.', 'mandatory' => true, 'route' => '/settings/campaign'],
            ['id' => 'first_template', 'group' => 'campaign', 'title' => 'Submit your first template for approval', 'why' => 'An approved template is required to start conversations with customers outside the service window.', 'mandatory' => true, 'route' => '/templates'],
            ['id' => 'contacts_imported', 'group' => 'campaign', 'title' => 'Import phone numbers', 'why' => 'Upload a contact list with phone numbers to choose an audience for your campaign.', 'mandatory' => true, 'route' => '/contacts'],
            ['id' => 'first_campaign', 'group' => 'campaign', 'title' => 'Send your first campaign', 'why' => 'Campaigns help you reach opted-in contacts with an approved message.', 'mandatory' => false, 'route' => '/campaigns'],
            ['id' => 'timezone', 'group' => 'time', 'title' => 'Set your timezone', 'why' => 'The timezone keeps scheduled activity aligned with your local business hours.', 'mandatory' => true, 'route' => '/settings'],
            ['id' => 'working_hours', 'group' => 'time', 'title' => 'Configure working hours', 'why' => 'Working hours help automations respond according to your business schedule.', 'mandatory' => false, 'route' => '/settings'],
            ['id' => 'team_members', 'group' => 'team', 'title' => 'Add team members', 'why' => 'Team members can share the inbox and handle conversations together.', 'mandatory' => false, 'route' => '/team'],
            ['id' => 'conversation_distribution', 'group' => 'team', 'title' => 'Set conversation distribution', 'why' => 'Automatic assignment helps distribute conversations among your team.', 'mandatory' => false, 'route' => '/settings/tickets'],
            ['id' => 'ticketing', 'group' => 'team', 'title' => 'Enable ticketing', 'why' => 'Ticketing settings help your team track and resolve conversations.', 'mandatory' => false, 'route' => '/settings/tickets'],
            ['id' => 'email_notifications', 'group' => 'team', 'title' => 'Configure email notifications', 'why' => 'Organization email settings can notify agents when conversations need attention.', 'mandatory' => false, 'route' => '/settings/notifications'],
            ['id' => 'labels_created', 'group' => 'contacts', 'title' => 'Create contact labels', 'why' => 'Labels make it easier to organize and target groups of contacts.', 'mandatory' => false, 'route' => '/settings/labels'],
            ['id' => 'readiness_review', 'group' => 'review', 'title' => 'Review account readiness', 'why' => 'The review summarizes required setup; it does not block navigation or campaign sending.', 'mandatory' => true, 'route' => '/dashboard'],
        ];
    }
}
