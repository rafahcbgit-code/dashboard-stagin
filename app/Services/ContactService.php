<?php

namespace App\Services;

use App\Helpers\WebhookHelper;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Setting;
use App\Support\ContactPageCache;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
    public function findOrCreateFromWebhook(string $phone, ?string $name = null): array
    {
        return $this->findOrCreateByPhone($phone, $name, 'whatsapp', true);
    }

    /**
     * Resolve a contact shared inside a chat without creating a duplicate.
     *
     * This intentionally uses the same idempotent path as webhook contact
     * creation. The chat UI can receive a formatted phone number while an
     * older contact may have been stored with spaces, punctuation, or without
     * the leading plus sign.
     */
    public function findOrCreateFromChatView(string $phone, ?string $name = null, int $createdBy = 0): Contact
    {
        return $this->findOrCreateByPhone($phone, $name, 'platform', false, $createdBy)['contact'];
    }

    /**
     * Find or create one contact for a phone number in this organization.
     * The unique database index is the final guard for concurrent requests;
     * duplicate-key errors are reconciled back to the contact that won.
     *
     * @return array{contact: Contact, isNew: bool}
     */
    private function findOrCreateByPhone(string $phone, ?string $name, string $source, bool $triggerWebhookEvent, int $createdBy = 0): array
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $contact = $this->findExistingByPhone($normalizedPhone, $phone);

        if ($contact) {
            $contact = $this->restoreAndCanonicalize($contact, $normalizedPhone);

            return ['contact' => $contact, 'isNew' => false];
        }

        $nameParts = $name ? explode(' ', trim($name), 2) : [];
        $firstName = $nameParts[0] ?? $normalizedPhone;
        $lastName = $nameParts[1] ?? '';

        try {
            $contact = Contact::create([
                'organization_id' => $this->organizationId,
                'phone' => $normalizedPhone,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'contact_source' => $source,
                'is_subscribed' => 1,
                'bot_enable' => 1,
                'created_by' => $createdBy,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            // Two View clicks/webhook jobs can pass the lookup together. The
            // unique organization+phone index makes one insert win; return it
            // instead of surfacing an error or inserting another contact.
            $contact = $this->reconcileDuplicateContact($exception, $normalizedPhone, $phone);

            if (! $contact) {
                throw $exception;
            }

            return ['contact' => $contact, 'isNew' => false];
        }

        if ($triggerWebhookEvent) {
            $cleanContact = $contact->makeHidden(['id', 'organization_id', 'created_by']);
            WebhookHelper::triggerWebhookEvent('contact.created', $cleanContact, $this->organizationId);
        }

        return ['contact' => $contact, 'isNew' => true];
    }

    private function normalizePhone(string $phone): string
    {
        $normalizedPhone = PhoneService::getE164Format($phone);

        if ($normalizedPhone === null) {
            $digits = preg_replace('/\D+/u', '', trim($phone)) ?: '';
            $candidate = str_starts_with($digits, '00')
                ? '+'.substr($digits, 2)
                : '+'.$digits;

            $normalizedPhone = PhoneService::getE164Format($candidate);
        }

        return $normalizedPhone ?? PhoneService::normalize(
            preg_replace('/\D+/u', '', trim($phone)) ?: trim($phone)
        );
    }

    private function findExistingByPhone(string $normalizedPhone, string $rawPhone): ?Contact
    {
        $rawCandidates = array_values(array_unique(array_filter([
            $normalizedPhone,
            trim($rawPhone),
            preg_replace('/\s+/', '', trim($rawPhone)),
            '+'.ltrim(trim($rawPhone), '+'),
        ])));

        $normalizedDigits = preg_replace('/\D+/u', '', $normalizedPhone) ?: '';
        $digitCandidates = array_values(array_unique(array_filter([
            $normalizedDigits,
            '00'.$normalizedDigits,
        ])));

        $phoneDigitsExpression = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '(', ''), ')', ''), '.', ''), '/', '')";

        return Contact::withTrashed()
            ->where('organization_id', $this->organizationId)
            ->where(function ($query) use ($rawCandidates, $digitCandidates, $phoneDigitsExpression) {
                $query->whereIn('phone', $rawCandidates);

                if ($digitCandidates) {
                    $query->orWhereRaw($phoneDigitsExpression.' IN ('.implode(',', array_fill(0, count($digitCandidates), '?')).')', $digitCandidates);
                }
            })
            // Prefer an already canonical record if both legacy and canonical
            // representations exist.
            ->orderByRaw('CASE WHEN phone = ? THEN 0 ELSE 1 END', [$normalizedPhone])
            ->orderBy('id')
            ->first();
    }

    private function restoreAndCanonicalize(Contact $contact, string $normalizedPhone): Contact
    {
        if ($contact->trashed()) {
            $contact->restore();
        }

        if ((string) $contact->phone !== $normalizedPhone) {
            try {
                $contact->phone = $normalizedPhone;
                $contact->save();
            } catch (QueryException $exception) {
                // A canonical record may have been inserted concurrently while
                // this legacy record was being repaired. Reuse that record.
                $canonical = Contact::withTrashed()
                    ->where('organization_id', $this->organizationId)
                    ->where('phone', $normalizedPhone)
                    ->first();

                if (! $canonical) {
                    throw $exception;
                }

                return $this->restoreAndCanonicalize($canonical, $normalizedPhone);
            }
        }

        return $contact;
    }

    protected function reconcileDuplicateContact(QueryException $exception, ?string $normalizedPhone, string $rawPhone): ?Contact
    {
        if (! $this->isDuplicatePhoneConstraint($exception)) {
            return null;
        }

        $contact = $this->findExistingByPhone($normalizedPhone ?? $this->normalizePhone($rawPhone), $rawPhone);

        if (! $contact) {
            Log::channel('webhook')->error('Duplicate contact constraint hit without recovery', [
                'organization_id' => $this->organizationId,
                'phone' => $rawPhone,
                'normalized_phone' => $normalizedPhone,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        Log::channel('webhook')->warning('Recovered contact after duplicate phone constraint', [
            'organization_id' => $this->organizationId,
            'contact_id' => $contact->id,
            'phone' => $rawPhone,
            'normalized_phone' => $normalizedPhone,
            'exception' => $exception->getMessage(),
        ]);

        return $this->restoreAndCanonicalize($contact, $normalizedPhone ?? $this->normalizePhone($rawPhone));
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
            if (empty($contact->contact_source)) {
                $source = method_exists($request, 'input')
                    ? $request->input('contact_source', 'platform')
                    : ($request->contact_source ?? 'platform');
                $contact->contact_source = $source ?: 'platform';
            }
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

        if (! is_array($incomingMetadata)) {
            $incomingMetadata = [];
        }

        if ($uuid !== null) {
            $existingMetadata = $contact->metadata ? json_decode($contact->metadata, true) : [];
            if (! is_array($existingMetadata)) {
                $existingMetadata = [];
            }

            $incomingMetadata = array_merge($existingMetadata, $incomingMetadata);
        }

        $contact->metadata = ! empty($incomingMetadata) ? json_encode($incomingMetadata) : null;
        $contact->updated_at = now();
        $contact->save();
        $this->forgetContactsCountCache();

        $groups = $request->input('group', []);
        if (empty($groups)) {
            $currentGroupIds = $contact->contactGroups()->pluck('contact_groups.id')->all();
            $contact->contactGroups()->detach();
            ContactGroupCountService::rebuildGroups($currentGroupIds);
        }

        if ($request->group) {
            $currentGroupIds = $contact->contactGroups()->pluck('contact_groups.id')->all();
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
                ->filter(fn ($value) => ! is_null($value) && $value !== '')
                ->values();

            $groupIdsByUuid = ContactGroup::whereIn('uuid', $normalizedGroups->all())->pluck('id')->toArray();
            $groupIdsById = ContactGroup::whereIn('id', $normalizedGroups->filter(fn ($value) => ctype_digit($value))->all())->pluck('id')->toArray();
            $groupIds = array_values(array_unique(array_merge($groupIdsByUuid, $groupIdsById)));
            $contact->contactGroups()->sync($groupIds);
            ContactGroupCountService::rebuildGroups(array_merge($currentGroupIds, $groupIds));
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
        $query = Contact::query()
            ->where('organization_id', $this->organizationId)
            ->whereNull('deleted_at');

        if (! empty($uuids)) {
            $query->whereIn('uuid', $uuids);
        }

        $this->deleteByQuery($query);
    }

    public function deleteByQuery(EloquentBuilder $query): int
    {
        $deletedAt = now();
        $deletedAtIso = $deletedAt->toISOString();
        $deletedCount = 0;
        $webhookContacts = [];

        (clone $query)
            ->select('contacts.id as id', 'contacts.uuid as uuid')
            ->chunkById(1000, function ($contacts) use (&$deletedCount, &$webhookContacts, $deletedAt, $deletedAtIso) {
                $contactIds = $contacts->pluck('id')->map(fn ($id) => (int) $id)->filter()->values()->all();

                if (empty($contactIds)) {
                    return;
                }

                $affectedGroupIds = DB::table('contact_contact_group')
                    ->whereIn('contact_id', $contactIds)
                    ->pluck('contact_group_id')
                    ->all();

                Chat::whereIn('contact_id', $contactIds)
                    ->where('type', 'inbound')
                    ->whereNull('deleted_at')
                    ->where('is_read', 0)
                    ->update(['is_read' => 1]);

                $deletedCount += DB::transaction(function () use ($contactIds, $deletedAt) {
                    $count = Contact::where('organization_id', $this->organizationId)
                        ->whereNull('deleted_at')->whereIn('id', $contactIds)
                        ->update(['deleted_at' => $deletedAt]);
                    DB::table('chat_message_search')->where('organization_id', $this->organizationId)
                        ->whereIn('contact_id', $contactIds)->delete();
                    return $count;
                });

                foreach ($contacts as $contact) {
                    $webhookContacts[] = [
                        'uuid' => $contact->uuid,
                        'deleted_at' => $deletedAtIso,
                    ];
                }

                ContactGroupCountService::rebuildGroups($affectedGroupIds);

                if (count($webhookContacts) >= 1000) {
                    $this->triggerDeletedContactsWebhook($webhookContacts);
                    $webhookContacts = [];
                }
            }, 'contacts.id', 'id');

        if (! empty($webhookContacts)) {
            $this->triggerDeletedContactsWebhook($webhookContacts);
        }

        if ($deletedCount > 0) {
            $this->forgetContactsCountCache();
        }

        return $deletedCount;
    }

    private function forgetContactsCountCache(): void
    {
        ContactPageCache::forgetContactsCount($this->organizationId);
    }

    private function triggerDeletedContactsWebhook(array $deletedContacts): void
    {
        WebhookHelper::triggerWebhookEvent('contact.deleted', [
            'list' => $deletedContacts,
        ], $this->organizationId);
    }
}
