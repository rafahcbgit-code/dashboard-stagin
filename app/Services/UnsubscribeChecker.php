<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Contact;

class UnsubscribeChecker
{
    /**
     * Constructor with dependency injection of ContactService
     */
    public function __construct(private ContactService $contactService)
    {
    }

    /**
     * Check if the message matches any unsubscribe triggers of the organization.
     * If matched and a contact UUID is provided, update the contact's is_subscribed field to 0.
     *
     * @param  Organization $organization
     * @param  string $messageText
     * @param  string|null $contactUuid
     * @return bool
     */
    public function check(Organization $organization, string $messageText, ?string $contactUuid = null): bool
    {
        // Return false immediately if message is empty
        if (!$messageText) {
            return false;
        }

        // Decode organization's metadata
        $metadataArray = $organization->metadata
            ? json_decode($organization->metadata, true)
            : [];

        // Get unsubscribe triggers from metadata
        $triggers = $metadataArray['campaign_settings']['unsubscribe_trigger'] ?? null;

        // Return false if no triggers defined
        if (!$triggers) {
            return false;
        }

        // Split trigger words by comma
        $triggerWords = preg_split('/\s*,\s*/', $triggers, -1, PREG_SPLIT_NO_EMPTY);

        // Normalize trigger words and message to lowercase for comparison
        $triggerWords = array_map('strtolower', $triggerWords);
        $messageCleaned = strtolower(trim($messageText));

        // Check if message matches any trigger word
        if (in_array($messageCleaned, $triggerWords, true)) {
            if ($contactUuid) {
                // Update the contact's is_subscribed field to 0 if contact exists
                $contact = Contact::where('uuid', $contactUuid)->first();
                if ($contact) {
                    $this->contactService->store((object)['is_subscribed' => 0], $contactUuid);
                }
            }
            return true;
        }

        // Return false if no match
        return false;
    }
}
