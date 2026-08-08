<?php

namespace App\Jobs;

use App\Models\ChatNote;
use App\Models\Organization;
use App\Services\OrganizationMailConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendChatNoteEmailNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(
        private readonly int $noteId,
        private readonly int $organizationId,
    ) {
        $this->onQueue('default');
    }

    public function handle(OrganizationMailConfigService $mailService): void
    {
        $organization = Organization::query()
            ->select(['id', 'name', 'metadata'])
            ->find($this->organizationId);

        if (! $organization) {
            return;
        }

        $metadata = $organization->metadata ? json_decode($organization->metadata, true) : [];
        $config = $metadata['tickets']['note_email_notifications'] ?? [];

        if (empty($config['enabled'])) {
            return;
        }

        $note = ChatNote::query()
            ->with([
                'contact:id,uuid,organization_id,first_name,last_name,phone',
                'creator:id,first_name,last_name,email',
            ])
            ->whereKey($this->noteId)
            ->whereNull('deleted_at')
            ->first();

        if (! $note || ! $note->contact || (int) $note->contact->organization_id !== $this->organizationId) {
            return;
        }

        $emails = $this->resolveRecipientEmails((string) ($config['recipient_scope'] ?? 'assigned_agent'), (int) $note->contact_id);

        if ($emails === []) {
            return;
        }

        $contactName = trim((string) ($note->contact->full_name ?? ''));
        if ($contactName === '') {
            $contactName = (string) ($note->contact->phone ?? 'Unknown contact');
        }

        $creatorName = trim((string) ($note->creator?->full_name ?? ''));
        if ($creatorName === '') {
            $creatorName = 'A team member';
        }

        $subject = 'New note added to a conversation';
        $body = implode("\n", [
            "A new note was added to a conversation in {$organization->name}.",
            '',
            "Contact: {$contactName}",
            "Added by: {$creatorName}",
            '',
            'Note:',
            Str::limit((string) $note->content, 500),
            '',
            'Open conversation:',
            url('/chats/' . $note->contact->uuid),
        ]);

        $sent = $mailService->sendCustomEmailToMany($this->organizationId, $emails, $subject, $body);

        Log::info('SendChatNoteEmailNotificationJob: completed', [
            'note_id' => $this->noteId,
            'organization_id' => $this->organizationId,
            'recipient_scope' => $config['recipient_scope'] ?? 'assigned_agent',
            'recipient_count' => count($emails),
            'sent_count' => $sent,
        ]);
    }

    private function resolveRecipientEmails(string $scope, int $contactId): array
    {
        if ($scope === 'all_team') {
            return DB::table('teams')
                ->join('users', 'users.id', '=', 'teams.user_id')
                ->where('teams.organization_id', $this->organizationId)
                ->whereNull('teams.deleted_at')
                ->where('teams.status', 'active')
                ->whereNull('users.deleted_at')
                ->whereNotNull('users.email')
                ->where('users.email', '!=', '')
                ->distinct()
                ->pluck('users.email')
                ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
                ->values()
                ->all();
        }

        $assignedAgent = DB::table('chat_tickets')
            ->join('users', 'users.id', '=', 'chat_tickets.assigned_to')
            ->where('chat_tickets.contact_id', $contactId)
            ->where('chat_tickets.organization_id', $this->organizationId)
            ->whereNotNull('chat_tickets.assigned_to')
            ->whereNull('users.deleted_at')
            ->orderByDesc('chat_tickets.id')
            ->value('users.email');

        return filter_var($assignedAgent, FILTER_VALIDATE_EMAIL) ? [$assignedAgent] : [];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendChatNoteEmailNotificationJob failed', [
            'note_id' => $this->noteId,
            'organization_id' => $this->organizationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
