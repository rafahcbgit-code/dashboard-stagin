<?php

namespace App\Support\Filters;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ContactFilter
{
    public function apply(Builder $query, Request $request): Builder
    {
        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $phoneSearch = $this->phoneSearch($search);
            $phoneOnlySearch = $this->isPhoneOnlySearch($search, $phoneSearch['digits']);
            $searchLike = '%' . $search . '%';

            $query->where(function (Builder $builder) use ($searchLike, $phoneSearch, $phoneOnlySearch) {
                if (! $phoneOnlySearch) {
                    $builder->where('contacts.first_name', 'like', $searchLike)
                        ->orWhere('contacts.last_name', 'like', $searchLike)
                        ->orWhereRaw("CONCAT(contacts.first_name, ' ', contacts.last_name) LIKE ?", [$searchLike])
                        ->orWhere('contacts.phone', 'like', $searchLike)
                        ->orWhere('contacts.email', 'like', $searchLike)
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(contacts.address, '$.country')) LIKE ?", [$searchLike]);
                }

                if ($phoneSearch['digits'] !== '') {
                    $phoneMethod = $phoneOnlySearch ? 'where' : 'orWhere';
                    $builder->{$phoneMethod}(function (Builder $phoneBuilder) use ($phoneSearch) {
                        $this->applyPhoneSearch($phoneBuilder, $phoneSearch);
                    });
                }
            });
        }

        $groupIds = $this->toIntArray($request->query('group_id', $request->query('group_ids', [])));
        $labelIds = $this->toIntArray($request->query('label_id', $request->query('labels', [])));
        $country = trim((string) $request->query('country', ''));
        $countryDialCode = preg_replace('/\D+/', '', trim((string) $request->query('country_dial_code', '')));
        $contactSource = strtolower(trim((string) $request->query('contact_source', '')));
        $subscriptionStatus = $request->query('subscription_status');
        $blockedStatus = $request->query('blocked_status');
        $noGroup = $this->toBool($request->query('no_group', false));
        $noLabel = $this->toBool($request->query('no_label', false));

        if (! empty($groupIds)) {
            $query->where(function (Builder $builder) use ($groupIds) {
                $builder->whereHas('contactGroups', function (Builder $groupsQuery) use ($groupIds) {
                    $groupsQuery->whereIn('contact_groups.id', $groupIds);
                })->orWhereIn('contacts.contact_group_id', $groupIds);
            });
        }

        if ($noGroup) {
            $query->where(function (Builder $builder) {
                $builder->whereNull('contacts.contact_group_id')
                    ->whereDoesntHave('contactGroups');
            });
        }

        if (! empty($labelIds)) {
            $query->whereHas('labels', function (Builder $labelsQuery) use ($labelIds) {
                $labelsQuery->whereIn('labels.id', $labelIds);
            });
        }

        if ($noLabel) {
            $query->whereDoesntHave('labels');
        }

        if ($country !== '') {
            $escapedCountry = addcslashes($country, '\%_');
            $query->where(function (Builder $builder) use ($country, $escapedCountry) {
                $builder->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(contacts.address, '$.country')) = ?", [$country])
                    ->orWhere('contacts.address', 'like', '%"country":"' . $escapedCountry . '"%');
            });
        }

        if ($countryDialCode !== '') {
            $query->where(function (Builder $builder) use ($countryDialCode) {
                $builder->where('contacts.phone', 'like', '+' . $countryDialCode . '%')
                    ->orWhere('contacts.phone', 'like', $countryDialCode . '%')
                    ->orWhere('contacts.phone', 'like', '00' . $countryDialCode . '%');
            });
        }

        if ($contactSource !== '') {
            if ($contactSource === 'platform') {
                $query->where(function (Builder $builder) {
                    $builder->where('contacts.contact_source', 'platform')
                        ->orWhereNull('contacts.contact_source');
                });
            } else {
                $query->where('contacts.contact_source', $contactSource);
            }
        }

        if ($subscriptionStatus !== null && $subscriptionStatus !== '') {
            if (in_array((string) $subscriptionStatus, ['subscribed', '1'], true)) {
                $query->where('contacts.is_subscribed', 1);
            } elseif (in_array((string) $subscriptionStatus, ['unsubscribed', '0'], true)) {
                $query->where('contacts.is_subscribed', 0);
            }
        }

        if ($blockedStatus !== null && $blockedStatus !== '') {
            if (in_array((string) $blockedStatus, ['blocked', '1'], true)) {
                $query->where('contacts.is_blocked', 1);
            } elseif (in_array((string) $blockedStatus, ['not_blocked', '0'], true)) {
                $query->where(function (Builder $builder) {
                    $builder->where('contacts.is_blocked', 0)
                        ->orWhereNull('contacts.is_blocked');
                });
            }
        }

        $createdFrom = $request->query('created_from');
        $createdTo = $request->query('created_to');

        if ($createdFrom && $createdTo) {
            $query->whereBetween('contacts.created_at', [
                Carbon::parse($createdFrom)->startOfDay(),
                Carbon::parse($createdTo)->endOfDay(),
            ]);
        } elseif ($createdFrom) {
            $query->where('contacts.created_at', '>=', Carbon::parse($createdFrom)->startOfDay());
        } elseif ($createdTo) {
            $query->where('contacts.created_at', '<=', Carbon::parse($createdTo)->endOfDay());
        }

        $customAttributes = $request->query('custom_attributes', []);
        if (is_array($customAttributes)) {
            foreach ($customAttributes as $key => $value) {
                if (! is_string($key) || trim((string) $value) === '') {
                    continue;
                }

                $path = '$."' . str_replace('"', '\"', $key) . '"';
                $likeValue = '%' . addcslashes((string) $value, '\%_') . '%';

                $query->where(function (Builder $builder) use ($path, $likeValue) {
                    $builder->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(contacts.metadata, ?)) LIKE ?", [$path, $likeValue])
                        ->orWhere('contacts.metadata', 'like', $likeValue);
                });
            }
        }

        $customAttributeKey = trim((string) $request->query('custom_attribute_key', ''));
        $customAttributeValue = trim((string) $request->query('custom_attribute_value', ''));

        if ($customAttributeKey !== '' && $customAttributeValue !== '') {
            $path = '$."' . str_replace('"', '\"', $customAttributeKey) . '"';
            $likeValue = '%' . addcslashes($customAttributeValue, '\%_') . '%';

            $query->where(function (Builder $builder) use ($path, $likeValue) {
                $builder->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(contacts.metadata, ?)) LIKE ?", [$path, $likeValue])
                    ->orWhere('contacts.metadata', 'like', $likeValue);
            });
        }

        return $query;
    }

    private function toIntArray(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($item) => (int) $item)
            ->filter(fn ($item) => $item > 0)
            ->values()
            ->all();
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }

    private function phoneSearch(string $value): array
    {
        preg_match_all('/\d+/', $value, $matches);

        $parts = $matches[0] ?? [];
        $digits = implode('', $parts);
        $exact = [];
        $contains = [];

        if ($digits !== '') {
            $exact[] = $digits;
            $exact[] = '+' . $digits;

            if (str_starts_with($digits, '00') && strlen($digits) > 2) {
                $exact[] = '+' . substr($digits, 2);
            }

            if (! str_starts_with($value, '+') && strlen($digits) < 10) {
                $contains[] = $digits;
            }
        }

        foreach ($parts as $part) {
            if (strlen($part) >= 6 && ! in_array($part, $contains, true)) {
                $contains[] = $part;
            }
        }

        return [
            'digits' => $digits,
            'exact' => $this->uniqueStrings($exact),
            'contains' => $this->uniqueStrings($contains),
        ];
    }

    private function isPhoneOnlySearch(string $search, string $digits): bool
    {
        return $digits !== ''
            && preg_match('/[[:alpha:]]/u', $search) !== 1
            && preg_match('/^\s*[\+0-9\-\.\(\)\/\s]+$/u', $search) === 1;
    }

    private function applyPhoneSearch(Builder $builder, array $phoneSearch): void
    {
        $hasCondition = false;

        if (! empty($phoneSearch['exact'])) {
            $builder->whereIn('contacts.phone', $phoneSearch['exact']);
            $hasCondition = true;
        }

        foreach ($phoneSearch['contains'] as $candidate) {
            $method = $hasCondition ? 'orWhere' : 'where';
            $builder->{$method}('contacts.phone', 'like', '%' . $candidate . '%');
            $hasCondition = true;
        }

        if (! $hasCondition) {
            $builder->whereRaw('1 = 0');
        }
    }

    private function uniqueStrings(array $values): array
    {
        return collect($values)
            ->filter(fn ($value): bool => is_string($value) && $value !== '')
            ->uniqueStrict()
            ->values()
            ->all();
    }
}
