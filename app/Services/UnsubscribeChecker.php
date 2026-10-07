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
            return false;
        }

        // Return false immediately if message is empty
        if (empty($messageText)) {
            return false;
        }

        $contactService = new ContactService($organization->id);

        // Decode organization's metadata
        $metadataArray = $organization->metadata
            ? json_decode($organization->metadata, true)
            : [];

        // Include configured words, previous values, and custom words used by
        // approved unsubscribe buttons so changing settings does not break
        // templates that are already in use.
        $triggers = $metadataArray['campaign_settings']['unsubscribe_trigger'] ?? '';
        $currentWords = preg_split('/\s*,\s*/u', (string) $triggers, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $historicalWords = is_array($metadataArray['campaign_settings']['unsubscribe_trigger_history'] ?? null)
            ? $metadataArray['campaign_settings']['unsubscribe_trigger_history']
            : [];
        $buttonWords = is_array($metadataArray['campaign_settings']['unsubscribe_button_triggers'] ?? null)
            ? $metadataArray['campaign_settings']['unsubscribe_button_triggers']
            : [];
        $triggerWords = array_values(array_unique(array_map(
            fn ($word) => $this->normalize((string) $word),
            array_merge($currentWords, $historicalWords, $buttonWords)
        )));
        $messageCleaned = $this->normalize($messageText);

        // Check if message matches any trigger word
        if (in_array($messageCleaned, $triggerWords, true)) {
            if ($contactId) {
                $contact = Contact::find($contactId);
                if ($contact) {
                    $contactService->updatePartial($contactId, ['is_subscribed' => 0]);
                } else {

                }
            } else {
                
            }
            return true;
        }

        
        return false;
    }

    private function normalize(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $value) ?? $value;
        $value = str_replace("ـ", '', $value);

        return mb_strtolower($value);
    }
}
