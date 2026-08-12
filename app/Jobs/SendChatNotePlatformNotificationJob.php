<?php

namespace App\Jobs;

use App\Events\ChatNoteNotificationEvent;
use App\Models\ChatNote;
use App\Models\NotificationLog;
use App\Services\OrganizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendChatNotePlatformNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(
        private readonly int $noteId,
        private readonly int $organizationId,
    ) {
        // The default queue is served in both local development and production.
        // Do not use a dedicated queue unless a worker is configured for it.
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $config = OrganizationService::getCachedMetadata($this->organizationId)['tickets']['note_platform_notifications'] ?? [];

        if (empty($config['enabled'])) {
            return;
        }

        $note = ChatNote::query()
            ->with([
                'contact:id,uuid,organization_id,first_name,last_name,phone',
                'creator:id,first_name,last_name',
            ])
            ->whereKey($this->noteId)
            ->whereNull('deleted_at')
            ->first();

        if (! $note || ! $note->contact || (int) $note->contact->organization_id !== $this->organizationId) {
            return;
        }

        $recipientIds = $this->resolveRecipientIds(
            (string) ($config['recipient_scope'] ?? 'assigned_agent'),
            (int) $note->contact_id,
        );

        if ($recipientIds === []) {
            return;
        }

        $contactName = trim((string) ($note->contact->full_name ?? '')) ?: (string) ($note->contact->phone ?? 'Unknown contact');
        $creatorName = trim((string) ($note->creator?->full_name ?? '')) ?: 'A team member';
        $payload = [
            'type' => 'chat_note_added',
            'contact_uuid' => $note->contact->uuid,
            'note_uuid' => $note->uuid,
            'message' => "New note added to {$contactName} by {$creatorName}",
        ];

        foreach ($recipientIds as $userId) {
            $notification = NotificationLog::create([
                'user_id' => $userId,
                'organization_id' => $this->organizationId,
                'notification_type' => 'chat_note_added',
                'payload' => $payload,
                'delivered' => true,
            ]);

            event(new ChatNoteNotificationEvent($userId, [
                'id' => $notification->id,
                'notification_type' => $notification->notification_type,
                'payload' => $notification->payload,
                'sent_at' => now()->toISOString(),
            ]));
        }
    }

    private function resolveRecipientIds(string $scope, int $contactId): array
    {
        if ($scope === 'all_team') {
            return DB::table('teams')
                ->join('users', 'users.id', '=', 'teams.user_id')
                ->where('teams.organization_id', $this->organizationId)
                ->whereNull('teams.deleted_at')
                ->where('teams.status', 'active')
                ->whereNull('users.deleted_at')
                ->distinct()
                ->pluck('users.id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        $assignedAgentId = DB::table('chat_tickets')
            ->join('users', 'users.id', '=', 'chat_tickets.assigned_to')
            ->where('chat_tickets.contact_id', $contactId)
            ->where('chat_tickets.organization_id', $this->organizationId)
            ->whereNotNull('chat_tickets.assigned_to')
            ->whereNull('users.deleted_at')
            ->latest('chat_tickets.id')
            ->value('users.id');

        return $assignedAgentId ? [(int) $assignedAgentId] : [];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendChatNotePlatformNotificationJob failed', [
            'note_id' => $this->noteId,
            'organization_id' => $this->organizationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
