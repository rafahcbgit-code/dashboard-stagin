<?php

namespace App\Jobs;

use App\Models\Contact;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReEnableBotForContactJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    private int $contactId;
    private int $organizationId;

    public function __construct(int $contactId, int $organizationId)
    {
        $this->queue = 'default';
        $this->contactId = $contactId;
        $this->organizationId = $organizationId;
    }

    public function handle(): void
    {
        $contact = Contact::find($this->contactId);

        if (!$contact) {
            Log::info('ReEnableBotForContactJob: contact not found, skipping', [
                'contact_id' => $this->contactId,
            ]);
            return;
        }

        if ($contact->bot_auto_reenable_at === null) {
            Log::info('ReEnableBotForContactJob: agent manually toggled bot, skipping auto-reenable', [
                'contact_id' => $this->contactId,
            ]);
            return;
        }

        if ($contact->bot_auto_reenable_at->isFuture()) {
            Log::info('ReEnableBotForContactJob: auto-reenable time not yet reached, skipping', [
                'contact_id' => $this->contactId,
                'auto_reenable_at' => $contact->bot_auto_reenable_at->toDateTimeString(),
            ]);
            return;
        }

        $contact->bot_enable = true;
        $contact->bot_auto_reenable_at = null;
        $contact->updated_at = now();
        $contact->save();

        Log::info('ReEnableBotForContactJob: bot re-enabled automatically', [
            'contact_id' => $this->contactId,
            'organization_id' => $this->organizationId,
        ]);
    }
}