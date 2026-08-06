<?php

namespace App\Console\Commands;

use App\Models\Contact;
use Illuminate\Console\Command;

class BackfillLatestChatState extends Command
{
    protected $signature = 'contacts:backfill-latest-chat-state
        {--organization-id= : Backfill only one organization ID}
        {--chunk=500 : Contacts processed per database query}';

    protected $description = 'Backfill the materialized latest chat state used by the awaiting-reply filter';

    public function handle(): int
    {
        $chunkSize = max(1, min((int) $this->option('chunk'), 1000));
        $organizationId = $this->option('organization-id');
        $processed = 0;

        $query = Contact::query()
            ->select('id')
            ->whereNotNull('latest_chat_created_at')
            ->with('latestChatForState:id,contact_id,type,created_at');

        if ($organizationId !== null) {
            $query->where('organization_id', (int) $organizationId);
        }

        $query->chunkById($chunkSize, function ($contacts) use (&$processed): void {
            foreach ($contacts as $contact) {
                $chat = $contact->latestChatForState;

                if (! $chat) {
                    continue;
                }

                Contact::syncLatestChatState(
                    (int) $contact->id,
                    $chat->getRawOriginal('created_at') ?? $chat->created_at,
                    (int) $chat->id,
                    $chat->type
                );

                $processed++;
            }
        });

        $this->info("Latest chat state backfilled for {$processed} contacts.");

        return self::SUCCESS;
    }
}
