<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\ContactChatState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class BackfillLatestChatState extends Command
{
    protected $signature = 'contacts:backfill-latest-chat-state
        {--organization-id= : Backfill only one organization ID}
        {--chunk=500 : Contacts processed per database query}
        {--sleep=25 : Milliseconds to pause between chunks (0 disables throttling)}';

    protected $description = 'Backfill the materialized latest chat state used by the awaiting-reply filter';

    public function handle(): int
    {
        $chunkSize = max(1, min((int) $this->option('chunk'), 1000));
        $sleepMilliseconds = max(0, (int) $this->option('sleep'));
        $organizationId = $this->option('organization-id');
        $processed = 0;
        $touchedOrganizationIds = [];

        if ($organizationId !== null) {
            Cache::forget(Contact::awaitingReplyStateCompletenessCacheKey((int) $organizationId));
        }

        $query = Contact::query()
            ->select(['id', 'organization_id'])
            ->whereNotNull('latest_chat_created_at')
            // Match the awaiting-reply filter semantics: chats that have been
            // completely archived are not visible through latestChatForState.
            // Skipping them keeps the command resumable and lets an otherwise
            // complete organization use the indexed state path.
            ->whereHas('chats')
            // A stopped command resumes cheaply: already-written state rows are
            // maintained by the Chat model and do not need to be read again.
            ->whereDoesntHave('latestChatState')
            ->with(['latestChatForState' => function ($chatQuery) {
                // latestOfMany joins chats to an aggregate subquery, so every
                // selected column must be qualified to avoid an ambiguous name.
                $chatQuery->select([
                    'chats.id',
                    'chats.contact_id',
                    'chats.type',
                    'chats.created_at',
                ]);
            }]);

        if ($organizationId !== null) {
            $query->where('organization_id', (int) $organizationId);
        }

        $query->chunkById($chunkSize, function ($contacts) use (&$processed, &$touchedOrganizationIds, $sleepMilliseconds): void {
            $states = [];

            foreach ($contacts as $contact) {
                $touchedOrganizationIds[(int) $contact->organization_id] = true;
                $chat = $contact->latestChatForState;

                if (! $chat) {
                    continue;
                }

                $states[] = [
                    'contact_id' => (int) $contact->id,
                    'organization_id' => (int) $contact->organization_id,
                    'latest_chat_id' => (int) $chat->id,
                    'latest_chat_type' => $chat->type,
                    'latest_chat_created_at' => $chat->getRawOriginal('created_at') ?? $chat->created_at,
                ];
            }

            ContactChatState::syncMany($states);
            $processed += count($states);

            if ($sleepMilliseconds > 0) {
                usleep($sleepMilliseconds * 1000);
            }
        });

        // Re-evaluate the per-organization markers on the next filter request.
        // No global cache must make a complete organization use the fallback.
        foreach (array_keys($touchedOrganizationIds) as $touchedOrganizationId) {
            Cache::forget(Contact::awaitingReplyStateCompletenessCacheKey((int) $touchedOrganizationId));
        }

        $this->info("Latest chat state backfilled for {$processed} contacts.");

        return self::SUCCESS;
    }
}
