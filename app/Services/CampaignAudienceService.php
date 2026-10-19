<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ContactField;
use Illuminate\Validation\ValidationException;

final class CampaignAudienceService
{
    /**
     * Return a bounded preview using the same subscription, block and phone
     * presence rules used when campaign logs are created.
     *
     * @return array{candidate_count:int,recipient_count:int,excluded_unsubscribed:int,excluded_blocked:int,excluded_without_phone:int,preview:array<int,array<string,mixed>>}
     */
    public function preview(int $organizationId, string $selection, array $dynamicFields = []): array
    {
        $groupId = null;
        if ($selection !== 'all') {
            $groupId = ContactGroup::query()
                ->where('uuid', $selection)
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->value('id');

            if (! $groupId) {
                throw ValidationException::withMessages([
                    'contacts' => __('Selected contact group is invalid or does not belong to your organization.'),
                ]);
            }
        }

        $base = Contact::query()
            ->where('contacts.organization_id', $organizationId)
            ->whereNull('contacts.deleted_at')
            ->when($groupId, fn ($query) => $query->whereExists(function ($subquery) use ($groupId) {
                $subquery->selectRaw('1')
                    ->from('contact_contact_group')
                    ->whereColumn('contact_contact_group.contact_id', 'contacts.id')
                    ->where('contact_contact_group.contact_group_id', $groupId);
            }));

        $candidateCount = (clone $base)->count();
        $excludedUnsubscribed = (clone $base)->where('contacts.is_subscribed', false)->count();
        $subscribed = (clone $base)->where('contacts.is_subscribed', true);
        $excludedBlocked = (clone $subscribed)->where('contacts.is_blocked', true)->count();
        $sendableAfterBlock = (clone $subscribed)->where(function ($query) {
            $query->whereNull('contacts.is_blocked')->orWhere('contacts.is_blocked', false);
        });
        $excludedWithoutPhone = (clone $sendableAfterBlock)
            ->where(fn ($query) => $query->whereNull('contacts.phone')->orWhere('contacts.phone', ''))
            ->count();

        $eligible = (clone $sendableAfterBlock)
            ->whereNotNull('contacts.phone')
            ->where('contacts.phone', '<>', '');

        $sampleContact = (clone $eligible)->orderBy('contacts.id')->first(['contacts.id', 'contacts.first_name', 'contacts.last_name', 'contacts.phone', 'contacts.email', 'contacts.metadata']);
        $allowedFields = ['first name', 'last name', 'name', 'phone', 'email'];
        $allowedFields = array_merge($allowedFields, ContactField::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->pluck('name')->map(fn ($name) => trim((string) $name))->all());
        $previewValues = [];
        if ($sampleContact) {
            $metadata = json_decode((string) $sampleContact->metadata, true);
            $metadata = is_array($metadata) ? $metadata : [];
            foreach (array_unique(array_intersect($dynamicFields, $allowedFields)) as $field) {
                $normalized = strtolower(trim((string) $field));
                $previewValues[$field] = match ($normalized) {
                    'first name' => $sampleContact->first_name,
                    'last name' => $sampleContact->last_name,
                    'name' => trim($sampleContact->first_name.' '.$sampleContact->last_name),
                    'phone' => $sampleContact->phone,
                    'email' => $sampleContact->email,
                    default => $metadata[$field] ?? collect($metadata)->first(fn ($value, $key) => \Illuminate\Support\Str::slug(trim((string) $key)) === \Illuminate\Support\Str::slug((string) $field)),
                };
            }
        }

        return [
            'candidate_count' => $candidateCount,
            'recipient_count' => (clone $eligible)->count(),
            'excluded_unsubscribed' => $excludedUnsubscribed,
            'excluded_blocked' => $excludedBlocked,
            'excluded_without_phone' => $excludedWithoutPhone,
            'preview' => (clone $eligible)
                ->orderBy('contacts.id')
                ->limit(5)
                ->get(['contacts.id', 'contacts.first_name', 'contacts.last_name'])
                ->map(fn (Contact $contact) => [
                    'id' => $contact->id,
                    'name' => trim($contact->first_name.' '.$contact->last_name) ?: __('Unnamed contact'),
                ])
                ->all(),
            'preview_values' => $previewValues,
        ];
    }
}
