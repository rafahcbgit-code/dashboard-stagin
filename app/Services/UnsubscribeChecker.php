<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Contact;
use Illuminate\Support\Facades\Log;

class UnsubscribeChecker
{
    /**
     * Check if the message matches any unsubscribe triggers of the organization.
     * If matched and a contact UUID is provided, update the contact's is_subscribed field to 0.
     *
     * @param  Organization $organization
     * @param  string $messageText
     * @param  string|null $contactUuid
     * @return bool
     */
  public function check(?Organization $organization = null, ?string $messageText = null, ?int $contactId = null): bool
    {
        // If no organization is provided, stop execution without error
        if (!$organization) {
            Log::warning('Unsubscribe check: organization not provided', [
                'contact_id' => $contactId,
                'message'    => $messageText,
            ]);
            return false;
        }

        // Return false immediately if message is empty
        if (empty($messageText)) {
            Log::info('Unsubscribe check: failed → message is empty', [
                'organization_id' => $organization->id,
                'contact_id'      => $contactId,
            ]);
            return false;
        }

        $contactService = new ContactService($organization->id);

        // Decode organization's metadata
        $metadataArray = $organization->metadata
            ? json_decode($organization->metadata, true)
            : [];

        // Get unsubscribe triggers from metadata
        $triggers = $metadataArray['campaign_settings']['unsubscribe_trigger'] ?? null;

        if (!$triggers) {
            Log::info('Unsubscribe check: failed → no unsubscribe trigger words defined', [
                'organization_id' => $organization->id,
                'contact_id'      => $contactId,
                'message'         => $messageText,
            ]);
            return false;
        }

        // Split trigger words by comma
        $triggerWords   = array_map('strtolower', preg_split('/\s*,\s*/', $triggers, -1, PREG_SPLIT_NO_EMPTY));
        $messageCleaned = strtolower(trim($messageText));

        // Check if message matches any trigger word
        if (in_array($messageCleaned, $triggerWords, true)) {
            if ($contactId) {
                $contact = Contact::find($contactId);
                if ($contact) {
                    $contactService->updatePartial($contactId, ['is_subscribed' => 0]);
                    Log::info('Unsubscribe check: success → contact unsubscribed', [
                        'organization_id' => $organization->id,
                        'contact_id'      => $contactId,
                        'trigger_word'    => $messageCleaned,
                    ]);
                } else {
                    Log::warning('Unsubscribe check: contact not found despite matching trigger word', [
                        'organization_id' => $organization->id,
                        'contact_id'      => $contactId,
                        'trigger_word'    => $messageCleaned,
                    ]);
                }
            } else {
                Log::info('Unsubscribe check: success → trigger word matched but no contactId provided', [
                    'organization_id' => $organization->id,
                    'trigger_word'    => $messageCleaned,
                ]);
            }
            return true;
        }

        // Return false if no match
        Log::info('Unsubscribe check: failed → no match found', [
            'organization_id' => $organization->id,
            'contact_id'      => $contactId,
            'message'         => $messageText,
            'triggers'        => $triggerWords,
        ]);
        return false;
    }
}
