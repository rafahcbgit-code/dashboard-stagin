<?php

namespace App\Services;

use App\Helpers\WebhookHelper;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ContactService
{
    private $organizationId;

    public function __construct($organizationId)
    {
        $this->organizationId = $organizationId;
    }

    /**
     * Find or create a contact from webhook data.
     *
     * Used by ProcessWebhookJob for simpler contact lookup/creation
     * without requiring a full request object.
     *
     * WhatsApp sends phone numbers without + prefix (e.g., "14155551234").
     * This method handles normalization and lookup.
     *
     * @param  string  $phone The phone number (will be normalized to E.164 format)
     * @param  string|null  $name The contact's display name from WhatsApp profile
     * @return array{contact: Contact, isNew: bool}
     */
    public function findOrCreateFromWebhook(string $phone, string $name = null): array
    {
        // Normalize phone: WhatsApp sends without + prefix, add it for E.164 format
        $normalizedPhone = PhoneService::getE164Format($phone);

        // If E.164 validation fails, try with + prefix
        if ($normalizedPhone === null) {
            $normalizedPhone = PhoneService::getE164Format('+'.$phone);
        }

        // If still null, use normalized format (+ prefix)
        if ($normalizedPhone === null) {
            $normalizedPhone = PhoneService::normalize($phone);
        }

        // Look for existing contact - try multiple formats for compatibility
        $contact = Contact::withTrashed()->where('organization_id', $this->organizationId)
            ->where(function ($query) use ($normalizedPhone, $phone) {
                $query->where('phone', $normalizedPhone)
                    ->orWhere('phone', '+'.$phone)
                    ->orWhere('phone', $phone);
            })
            ->first();
            
            if ($contact) {
                if ($contact->trashed()) {
                    $contact->restore();
                }

                return ['contact' => $contact, 'isNew' => false];
            }

        // Parse name into first/last name
        $nameParts = $name ? explode(' ', $name, 2) : [];
        $firstName = $nameParts[0] ?? $normalizedPhone;
        $lastName = $nameParts[1] ?? '';

        $isNew = true;

        try {
            $contact = Contact::create([
                'organization_id' => $this->organizationId,
                'phone' => $normalizedPhone,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'is_subscribed' => 1,
                'bot_enable' => 1,
                'created_by' => 0, // System created
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $contact = $this->reconcileDuplicateContact($exception, $normalizedPhone, $phone);

            if (! $contact) {
                throw $exception;
            }

            $isNew = false;
        }

        if ($isNew) {
            $cleanContact = $contact->makeHidden(['id', 'organization_id', 'created_by']);
            WebhookHelper::triggerWebhookEvent('contact.created', $cleanContact, $this->organizationId);
        }

        return ['contact' => $contact, 'isNew' => $isNew];
    }

    protected function reconcileDuplicateContact(QueryException $exception, ?string $normalizedPhone, string $rawPhone): ?Contact
    {
        if (! $this->isDuplicatePhoneConstraint($exception)) {
            return null;
        }

        $candidates = array_filter([
            $normalizedPhone,
            '+' . $rawPhone,
            $rawPhone,
        ]);

        $contact = Contact::withTrashed()
            ->where('organization_id', $this->organizationId)
            ->whereIn('phone', $candidates)
            ->orderBy('id')
            ->first();

        if (! $contact) {
            Log::channel('webhook')->error('Duplicate contact constraint hit without recovery', [
                'organization_id' => $this->organizationId,
                'phone' => $rawPhone,
                'normalized_phone' => $normalizedPhone,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($contact->trashed()) {
            $contact->restore();
        }

        Log::channel('webhook')->warning('Recovered contact after duplicate phone constraint', [
            'organization_id' => $this->organizationId,
            'contact_id' => $contact->id,
            'phone' => $rawPhone,
            'normalized_phone' => $normalizedPhone,
            'exception' => $exception->getMessage(),
        ]);

        return $contact;
    }

    protected function isDuplicatePhoneConstraint(QueryException $exception): bool
    {
        return $exception->getCode() === '23000'
            && str_contains($exception->getMessage(), 'idx_contacts_org_phone_unique');
    }

    public function store(object $request, $uuid = null)
    {
        $normalizedPhone = PhoneService::getE164Format($request->phone);

        if ($uuid === null) {
            // Check if contact with same phone already exists (including soft-deleted)
            $contact = Contact::withTrashed()
                ->where('organization_id', $this->organizationId)
                ->where('phone', $normalizedPhone)
                ->first();

            if ($contact) {
                if ($contact->trashed()) {
                    $contact->restore();
                }
            } else {
                $contact = new Contact();
            }
        } else {
            $contact = Contact::where('uuid', $uuid)->firstOrFail();
        }
        $contact->first_name = $request->first_name;
        $contact->last_name = $request->last_name;
        $contact->email = $request->email;
        $contact->is_subscribed = (int) $request->is_subscribed;
        $contact->phone = $normalizedPhone;

        if ($request->hasFile('file')) {
            $storage = Setting::where('key', 'storage_system')->first()->value;
            $fileName = $request->file('file')->getClientOriginalName();
            $fileContent = $request->file('file');

            if ($storage === 'local') {
                $file = Storage::disk('local')->put('public', $fileContent);
                $mediaFilePath = $file;

                $contact->avatar = '/media/'.ltrim($mediaFilePath, '/');
            } elseif ($storage === 'aws') {
                $file = $request->file('file');
                $uploadedFile = $file->store('uploads/media/contacts/'.$this->organizationId, 's3');
                $mediaFilePath = Storage::disk('s3')->url($uploadedFile);

                $contact->avatar = $mediaFilePath;
            }
        }

        if ($uuid === null) {
            $contact->organization_id = $this->organizationId;
            $contact->created_by = auth()->user() ? auth()->user()->id : 0;
            $contact->created_at = now();
        }

        $address = json_encode([
            'street' => $request->street,
            'city' => $request->city,
            'state' => $request->state,
            'zip' => $request->zip,
            'country' => $request->country,
        ]);

        $contact->address = $address;

        $incomingMetadata = $request->metadata ?? null;
        if (is_string($incomingMetadata)) {
            $decodedMetadata = json_decode($incomingMetadata, true);
            $incomingMetadata = json_last_error() === JSON_ERROR_NONE ? $decodedMetadata : [];
        }

        if (!is_array($incomingMetadata)) {
            $incomingMetadata = [];
        }

        if ($uuid !== null) {
            $existingMetadata = $contact->metadata ? json_decode($contact->metadata, true) : [];
            if (!is_array($existingMetadata)) {
                $existingMetadata = [];
            }

            $incomingMetadata = array_merge($existingMetadata, $incomingMetadata);
        }

        $contact->metadata = !empty($incomingMetadata) ? json_encode($incomingMetadata) : null;
        $contact->updated_at = now();
        $contact->save();

        $groups = $request->input('group', []);
        if (empty($groups)) {
            $contact->contactGroups()->detach();
        }

        if ($request->group) {
            $normalizedGroups = collect($request->group)
                ->map(function ($item) {
                    if (is_string($item) || is_numeric($item)) {
                        return trim((string) $item);
                    }

                    if (is_array($item)) {
                        if (isset($item['value'])) {
                            return trim((string) $item['value']);
                        }

                        if (isset($item['uuid'])) {
                            return trim((string) $item['uuid']);
                        }

                        if (isset($item['id'])) {
                            return trim((string) $item['id']);
                        }
                    }

                    return null;
                })
                ->filter(fn ($value) => !is_null($value) && $value !== '')
                ->values();

            $groupIdsByUuid = ContactGroup::whereIn('uuid', $normalizedGroups->all())->pluck('id')->toArray();
            $groupIdsById = ContactGroup::whereIn('id', $normalizedGroups->filter(fn ($value) => ctype_digit($value))->all())->pluck('id')->toArray();
            $groupIds = array_values(array_unique(array_merge($groupIdsByUuid, $groupIdsById)));
            $contact->contactGroups()->sync($groupIds);
        }

        // Prepare a clean contact object for webhook
        $cleanContact = $contact->makeHidden(['id', 'organization_id', 'created_by']);

        // Trigger webhook
        WebhookHelper::triggerWebhookEvent($uuid === null ? 'contact.created' : 'contact.updated', $cleanContact, $this->organizationId);

        return $contact;
    }

    //A new method was added because the store method requires sending all fields, whereas here only a single field is being updated

    public function updatePartial($uuid, array $data)
    {
        $contact = Contact::where('id', $uuid)->firstOrFail();

        foreach ($data as $field => $value) {
            if ($field === 'phone') {
                $contact->phone = PhoneService::getE164Format($value);
            } else {
                $contact->{$field} = $value;
            }
        }

        $contact->updated_at = now();
        $contact->save();

        return $contact;
    }

    public function favorite(object $request, $uuid)
    {
        $contact = Contact::where('uuid', $uuid)->firstOrFail();
        $contact->is_favorite = $request->favorite;
        $contact->updated_at = date('Y-m-d H:i:s');
        $contact->save();
    }

    public function delete($uuids)
    {
        $deletedContacts = [];

        if (empty($uuids)) {
            // Delete all contacts (soft delete)
            $contacts = Contact::where('organization_id', $this->organizationId)->get();
            Contact::whereNotNull('id')->where('organization_id', $this->organizationId)->delete();

            // Prepare deleted contacts for the webhook
            foreach ($contacts as $contact) {
                $deletedContacts[] = [
                    'uuid' => $contact->uuid,
                    'deleted_at' => now()->toISOString(), // Assuming you're using Laravel's Carbon
                ];
            }

            //Mark all unread chats as read
            Chat::where('organization_id', $this->organizationId)
                ->where('type', 'inbound')
                ->whereNull('deleted_at')
                ->where('is_read', 0)
                ->update([
                    'is_read' => 1,
                ]);
        } else {
            // Delete contacts by UUIDs (soft delete)
            foreach ($uuids as $uuid) {
                $contact = Contact::where('uuid', $uuid)->firstOrFail();

                // Prepare deleted contact for the webhook
                $deletedContacts[] = [
                    'uuid' => $contact->uuid,
                    'deleted_at' => now()->toISOString(),
                ];

                //Mark all unread chats as read
                Chat::where('contact_id', $contact->id)
                    ->where('type', 'inbound')
                    ->whereNull('deleted_at')
                    ->where('is_read', 0)
                    ->update([
                        'is_read' => 1,
                    ]);
            }

            Contact::whereIn('uuid', $uuids)->where('organization_id', $this->organizationId)->delete();
        }

        // Trigger webhook with deleted contacts
        WebhookHelper::triggerWebhookEvent('contact.deleted', [
            'list' => $deletedContacts,
        ], $this->organizationId);
    }
}
