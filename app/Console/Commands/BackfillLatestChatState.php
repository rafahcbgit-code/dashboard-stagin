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
        Cache::forget('awaiting_reply_state_backfill_complete');

        $chunkSize = max(1, min((int) $this->option('chunk'), 1000));
        $sleepMilliseconds = max(0, (int) $this->option('sleep'));
        $organizationId = $this->option('organization-id');
        $processed = 0;

        $query = Contact::query()
            ->select(['id', 'organization_id'])
            ->whereNotNull('latest_chat_created_at')
            ->with('latestChatForState:id,contact_id,type,created_at');

        if ($organizationId !== null) {
            $query->where('organization_id', (int) $organizationId);
        }

        $query->chunkById($chunkSize, function ($contacts) use (&$processed, $sleepMilliseconds): void {
            foreach ($contacts as $contact) {
                $chat = $contact->latestChatForState;

                if (! $chat) {
                    continue;
                }

                ContactChatState::syncLatest(
                    (int) $contact->id,
                    (int) $contact->organization_id,
                    $chat->getRawOriginal('created_at') ?? $chat->created_at,
                    (int) $chat->id,
                    $chat->type
                );

                $processed++;
            }

            if ($sleepMilliseconds > 0) {
                usleep($sleepMilliseconds * 1000);
            }
        });

        // Re-evaluate the marker on the next filter request after this run.
        Cache::forget('awaiting_reply_state_backfill_complete');

        $this->info("Latest chat state backfilled for {$processed} contacts.");

        return self::SUCCESS;
    }
}
