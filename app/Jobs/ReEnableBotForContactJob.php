<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\ContactBotReenable;
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
        $reenableRecord = ContactBotReenable::find($this->contactId);

        if (!$reenableRecord) {
            Log::info('ReEnableBotForContactJob: no re-enable record found (agent manually toggled or already processed), skipping', [
                'contact_id' => $this->contactId,
            ]);
            return;
        }

        if ($reenableRecord->reenable_at->isFuture()) {
            Log::info('ReEnableBotForContactJob: re-enable time not yet reached, skipping', [
                'contact_id' => $this->contactId,
                'reenable_at' => $reenableRecord->reenable_at->toDateTimeString(),
            ]);
            return;
        }

        $contact = Contact::find($this->contactId);
        if (!$contact) {
            Log::info('ReEnableBotForContactJob: contact not found, cleaning up record', [
                'contact_id' => $this->contactId,
            ]);
            $reenableRecord->delete();
            return;
        }

        $contact->bot_enable = true;
        $contact->updated_at = now();
        $contact->save();

        $reenableRecord->delete();

        Log::info('ReEnableBotForContactJob: bot re-enabled automatically', [
            'contact_id' => $this->contactId,
            'organization_id' => $this->organizationId,
        ]);
    }
}