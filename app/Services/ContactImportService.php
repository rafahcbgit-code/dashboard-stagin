<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ContactImportBatch;
use App\Models\ContactImportRow;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Services\PhoneService;

class ContactImportService
{
    private const MAX_PREVIEW_ROWS = 100000;

    private const STANDARD_TARGET_FIELDS = [
        'first_name',
        'last_name',
        'phone',
        'email',
        'group_name',
        'street',
        'city',
        'state',
        'zip',
        'country',
    ];

    public function createPreview(UploadedFile $file, int $organizationId, int $userId): ContactImportBatch
    {
        [$headers, $rows] = $this->parseUploadedFile($file);

        $batch = ContactImportBatch::query()->create([
            'organization_id' => $organizationId,
            'created_by' => $userId,
            'status' => 'uploaded',
            'headers' => $headers,
            'selected_group_ids' => [],
        ]);

        $insertRows = [];
        foreach ($rows as $index => $row) {
            $insertRows[] = [
                'contact_import_batch_id' => $batch->id,
                'row_number' => $index + 2,
                'status' => 'pending',
                'is_removed' => false,
                'raw_data' => json_encode($row),
                'mapped_data' => null,
                'normalized_data' => null,
                'errors' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($insertRows) >= 2000) {
                DB::table('contact_import_rows')->insert($insertRows);
                $insertRows = [];
            }
        }

        if (! empty($insertRows)) {
            DB::table('contact_import_rows')->insert($insertRows);
        }

        return $batch->fresh();
    }

    public function applyMapping(ContactImportBatch $batch, array $mapping): ContactImportBatch
    {
        DB::transaction(function () use ($batch, $mapping): void {
            $batch->update([
                'mapping' => $mapping,
                'status' => 'mapped',
            ]);

            // Keep mapping step lightweight: do not process each row here.
            // Row-level transformations are evaluated during normalize/commit.
            ContactImportRow::query()
                ->where('contact_import_batch_id', $batch->id)
                ->where('is_removed', false)
                ->where(function ($query): void {
                    $query->whereNotNull('mapped_data')
                        ->orWhereNotNull('normalized_data')
                        ->orWhereNotNull('errors')
                        ->orWhere('status', '!=', 'pending');
                })
                ->update([
                    'mapped_data' => null,
                    'normalized_data' => null,
                    'errors' => null,
                    'status' => 'pending',
                    'updated_at' => now(),
                ]);
        });

        return $batch->fresh();
    }

    public function normalize(ContactImportBatch $batch, string $defaultCountryCode): array
    {
        $defaultCountryCode = $this->normalizeCountryCode($defaultCountryCode);
        $batchMapping = (array) ($batch->mapping ?? []);
        $invalidRowsPreview = [];
        $invalidRowsCount = 0;
        $invalidPreviewLimit = 1000;

        DB::transaction(function () use ($batch, $defaultCountryCode, $batchMapping, &$invalidRowsPreview, &$invalidRowsCount, $invalidPreviewLimit): void {
            $batch->update([
                'default_country_code' => $defaultCountryCode,
                'status' => 'normalized',
            ]);

            // Performance: normalize in bulk with query builder + upsert (single write per chunk).
            DB::table('contact_import_rows')
                ->select(['id', 'contact_import_batch_id', 'row_number', 'is_removed', 'created_at', 'raw_data', 'mapped_data'])
                ->where('contact_import_batch_id', $batch->id)
                ->where('is_removed', false)
                ->orderBy('id')
                ->chunkById(1000, function ($rows) use ($defaultCountryCode, $batchMapping, $batch, &$invalidRowsPreview, &$invalidRowsCount, $invalidPreviewLimit): void {
                    $updates = [];
                    $invalidIdsToDelete = [];
                    $now = now();

                    foreach ($rows as $row) {
                        $rawData = $this->decodeJsonArray($row->raw_data);
                        $currentMapped = $this->decodeJsonArray($row->mapped_data);

                        $mappedData = ! empty($currentMapped)
                            ? $currentMapped
                            : $this->buildMappedData($rawData, $batchMapping);

                        $evaluation = $this->evaluateMappedRow($mappedData, $defaultCountryCode);

                        if (! empty($evaluation['errors'])) {
                            $invalidRowsCount++;

                            if (count($invalidRowsPreview) < $invalidPreviewLimit) {
                                $invalidRowsPreview[] = [
                                    'row_number' => (int) $row->row_number,
                                    'status' => 'invalid',
                                    'mapped_data' => $mappedData,
                                    'errors' => array_values((array) $evaluation['errors']),
                                ];
                            }

                            $invalidIdsToDelete[] = (int) $row->id;
                            continue;
                        }

                        $updates[] = [
                            'id' => (int) $row->id,
                            'contact_import_batch_id' => (int) $row->contact_import_batch_id,
                            'row_number' => (int) $row->row_number,
                            'is_removed' => (int) $row->is_removed,
                            'created_at' => $row->created_at,
                            'mapped_data' => json_encode($mappedData),
                            'normalized_data' => json_encode($evaluation['normalized']),
                            'errors' => null,
                            'status' => 'valid',
                            'updated_at' => $now,
                        ];
                    }

                    if (! empty($updates)) {
                        DB::table('contact_import_rows')->upsert(
                            $updates,
                            ['id'],
                            ['mapped_data', 'normalized_data', 'errors', 'status', 'updated_at']
                        );
                    }

                    if (! empty($invalidIdsToDelete)) {
                        DB::table('contact_import_rows')
                            ->where('contact_import_batch_id', $batch->id)
                            ->whereIn('id', $invalidIdsToDelete)
                            ->delete();
                    }
                });
        });

        return [
            'batch' => $batch->fresh(),
            'invalid_rows_count' => $invalidRowsCount,
            'invalid_rows_preview' => $invalidRowsPreview,
            'invalid_rows_preview_limited' => $invalidRowsCount > $invalidPreviewLimit,
        ];
    }

    public function updateRow(ContactImportBatch $batch, ContactImportRow $row, array $payload): ContactImportRow
    {
        $baseMappedData = is_array($row->mapped_data) && ! empty($row->mapped_data)
            ? $row->mapped_data
            : $this->buildMappedData($row->raw_data ?? [], (array) ($batch->mapping ?? []));

        $mapped = array_merge($baseMappedData, Arr::only($payload, [
            'first_name',
            'last_name',
            'phone',
            'email',
            'group_name',
            'street',
            'city',
            'state',
            'zip',
            'country',
            'custom_attributes',
        ]));

        $evaluation = $this->evaluateMappedRow($mapped, (string) ($batch->default_country_code ?: ''));

        $row->update([
            'mapped_data' => $mapped,
            'normalized_data' => $evaluation['normalized'],
            'errors' => $evaluation['errors'],
            'status' => empty($evaluation['errors']) ? 'valid' : 'invalid',
        ]);

        return $row->fresh();
    }

    public function removeRow(ContactImportRow $row): ContactImportRow
    {
        $row->update([
            'is_removed' => true,
            'status' => 'removed',
            'errors' => ['Removed by user'],
        ]);

        return $row->fresh();
    }

    public function removeInvalidRows(ContactImportBatch $batch): int
    {
        return ContactImportRow::query()
            ->where('contact_import_batch_id', $batch->id)
            ->where('is_removed', false)
            ->where('status', 'invalid')
            ->update([
                'is_removed' => true,
                'status' => 'removed',
                'errors' => json_encode(['Removed by user (Clear All invalid rows)']),
                'updated_at' => now(),
            ]);
    }

    public function assignGroups(
        ContactImportBatch $batch,
        array $groupIds,
        ?string $newGroupName,
        string $assignmentMode,
        ?int $splitGroupSize,
        ?string $splitGroupPrefix,
        int $organizationId,
        int $userId
    ): ContactImportBatch
    {
        $assignedGroupIds = collect($groupIds)->map(fn ($id) => (int) $id)->filter()->values();

        $normalizedMode = in_array($assignmentMode, ['all_selected', 'split'], true) ? $assignmentMode : 'all_selected';

        if ($normalizedMode === 'all_selected') {
            $name = trim((string) $newGroupName);
            if ($name !== '') {
                $group = ContactGroup::query()
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at')
                    ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
                    ->first();

                if (! $group) {
                    $group = ContactGroup::query()->create([
                        'organization_id' => $organizationId,
                        'name' => $name,
                        'created_by' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $assignedGroupIds->push((int) $group->id);
            }
        }

        $batch->update([
            'selected_group_ids' => $assignedGroupIds->unique()->values()->all(),
            'group_assignment' => [
                'mode' => $normalizedMode,
                'split_group_size' => $normalizedMode === 'split' ? (int) ($splitGroupSize ?? 0) : null,
                'split_group_prefix' => $normalizedMode === 'split' ? trim((string) ($splitGroupPrefix ?? '')) : null,
            ],
            'status' => 'group_assigned',
        ]);

        return $batch->fresh();
    }

    public function commit(ContactImportBatch $batch, int $organizationId, int $userId): array
    {
        $selectedGroupIds = collect($batch->selected_group_ids ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $groupAssignment = (array) ($batch->group_assignment ?? []);
        $assignmentMode = (string) ($groupAssignment['mode'] ?? 'all_selected');
        $splitEnabled = $assignmentMode === 'split'
            && (int) ($groupAssignment['split_group_size'] ?? 0) > 0
            && trim((string) ($groupAssignment['split_group_prefix'] ?? '')) !== '';
        $splitState = [
            'enabled' => $splitEnabled,
            'size' => max(1, (int) ($groupAssignment['split_group_size'] ?? 0)),
            'prefix' => trim((string) ($groupAssignment['split_group_prefix'] ?? '')),
            'current_index' => 1,
            'current_count' => 0,
        ];
        $splitAssignmentByPhone = [];
        $groupCacheRaw = ContactGroup::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->pluck('id', 'name')
            ->toArray();
        $groupCache = [];
        foreach ($groupCacheRaw as $name => $id) {
            $groupCache[$this->groupCacheKey((string) $name)] = (int) $id;
        }

        $created = 0;
        $updated = 0;
        $failed = [];

        ContactImportRow::query()
            ->where('contact_import_batch_id', $batch->id)
            ->where('is_removed', false)
            ->where('status', 'valid')
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use ($organizationId, $userId, $selectedGroupIds, $assignmentMode, &$splitState, &$splitAssignmentByPhone, &$groupCache, &$created, &$updated, &$failed): void {
                $now = now();
                $rowStatusUpdates = [];
                $entriesByPhone = [];
                $rowMetaById = [];

                foreach ($rows as $row) {
                    $rowMetaById[(int) $row->id] = [
                        'contact_import_batch_id' => (int) $row->contact_import_batch_id,
                        'row_number' => (int) $row->row_number,
                        'is_removed' => (int) $row->is_removed,
                        'created_at' => $row->created_at,
                    ];

                    $normalized = is_array($row->normalized_data) ? $row->normalized_data : [];
                    $phone = trim((string) ($normalized['phone'] ?? ''));

                    if ($phone === '') {
                        $error = 'Missing normalized phone';
                        $failed[] = [
                            'row' => $row->row_number,
                            'error' => $error,
                        ];
                        $rowStatusUpdates[] = [
                            'id' => (int) $row->id,
                            'contact_import_batch_id' => (int) $row->contact_import_batch_id,
                            'row_number' => (int) $row->row_number,
                            'is_removed' => (int) $row->is_removed,
                            'created_at' => $row->created_at,
                            'status' => 'failed',
                            'errors' => json_encode([$error]),
                            'updated_at' => $now,
                        ];
                        continue;
                    }

                    if (! isset($entriesByPhone[$phone])) {
                        $entriesByPhone[$phone] = [
                            'phone' => $phone,
                            'first_name' => isset($normalized['first_name']) ? trim((string) $normalized['first_name']) : null,
                            'last_name' => $normalized['last_name'] ?? null,
                            'email' => $normalized['email'] ?? null,
                            'address' => (array) ($normalized['address'] ?? []),
                            'custom_attributes' => (array) ($normalized['custom_attributes'] ?? []),
                            'group_names' => collect($normalized['group_names'] ?? [])->filter()->unique()->values()->all(),
                            'row_ids' => [(int) $row->id],
                            'row_numbers' => [(int) $row->row_number],
                        ];
                        continue;
                    }

                    $existing = &$entriesByPhone[$phone];
                    $normalizedFirstName = isset($normalized['first_name']) ? trim((string) $normalized['first_name']) : null;
                    if ($normalizedFirstName !== null && $normalizedFirstName !== '') {
                        $existing['first_name'] = $normalizedFirstName;
                    }

                    $normalizedLastName = isset($normalized['last_name']) ? trim((string) $normalized['last_name']) : null;
                    if ($normalizedLastName !== null && $normalizedLastName !== '') {
                        $existing['last_name'] = $normalizedLastName;
                    }
                    $existing['email'] = $normalized['email'] ?: $existing['email'];
                    $existing['address'] = array_merge((array) $existing['address'], (array) ($normalized['address'] ?? []));
                    $existing['custom_attributes'] = array_merge((array) $existing['custom_attributes'], (array) ($normalized['custom_attributes'] ?? []));
                    $existing['group_names'] = collect(array_merge(
                        (array) $existing['group_names'],
                        collect($normalized['group_names'] ?? [])->filter()->values()->all()
                    ))->unique()->values()->all();
                    $existing['row_ids'][] = (int) $row->id;
                    $existing['row_numbers'][] = (int) $row->row_number;
                    unset($existing);
                }

                if (empty($entriesByPhone)) {
                    if (! empty($rowStatusUpdates)) {
                        DB::table('contact_import_rows')->upsert(
                            $rowStatusUpdates,
                            ['id'],
                            ['status', 'errors', 'updated_at']
                        );
                    }
                    return;
                }

                $allGroupNames = collect($entriesByPhone)
                    ->flatMap(fn ($entry) => (array) ($entry['group_names'] ?? []))
                    ->map(fn ($name) => trim((string) $name))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $this->ensureGroupIdsForNames($allGroupNames, $organizationId, $userId, $groupCache, $now);

                foreach ($entriesByPhone as $phone => $entry) {
                    $groupIdsFromFile = collect($entry['group_names'])
                        ->map(fn ($name) => $groupCache[$this->groupCacheKey((string) $name)] ?? null)
                        ->filter()
                        ->values();

                    $manualGroupIds = $assignmentMode === 'all_selected' ? $selectedGroupIds : collect();
                    $splitGroupId = null;

                    if ($splitState['enabled']) {
                        if (isset($splitAssignmentByPhone[$phone])) {
                            $splitGroupId = (int) $splitAssignmentByPhone[$phone];
                        } else {
                            $splitGroupId = $this->resolveSplitGroupId(
                                $organizationId,
                                $userId,
                                $groupCache,
                                $now,
                                $splitState
                            );
                            if ($splitGroupId) {
                                $splitAssignmentByPhone[$phone] = $splitGroupId;
                            }
                        }
                    }

                    $entriesByPhone[$phone]['all_group_ids'] = $manualGroupIds
                        ->merge($groupIdsFromFile)
                        ->when($splitGroupId, fn ($collection) => $collection->push($splitGroupId))
                        ->map(fn ($id) => (int) $id)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();
                }

                $phones = array_keys($entriesByPhone);
                $existingContacts = Contact::withTrashed()
                    ->where('organization_id', $organizationId)
                    ->whereIn('phone', $phones)
                    ->get(['id', 'uuid', 'organization_id', 'phone', 'first_name', 'last_name', 'created_by', 'created_at', 'metadata', 'deleted_at'])
                    ->keyBy('phone');

                $contactUpdates = [];
                $contactInserts = [];

                foreach ($entriesByPhone as $phone => $entry) {
                    $existingContact = $existingContacts->get($phone);

                    if ($existingContact) {
                        $existingMetadata = is_string($existingContact->metadata)
                            ? (json_decode($existingContact->metadata, true) ?: [])
                            : ((array) $existingContact->metadata);

                        $mergedMetadata = array_merge($existingMetadata, (array) $entry['custom_attributes']);
                        $resolvedFirstName = trim((string) ($entry['first_name'] ?? '')) !== ''
                            ? trim((string) $entry['first_name'])
                            : ((string) ($existingContact->first_name ?: 'name'));
                        $resolvedLastName = trim((string) ($entry['last_name'] ?? '')) !== ''
                            ? trim((string) $entry['last_name'])
                            : $existingContact->last_name;

                        $contactUpdates[] = [
                            'id' => (int) $existingContact->id,
                            'uuid' => (string) $existingContact->uuid,
                            'organization_id' => (int) $existingContact->organization_id,
                            'phone' => (string) $existingContact->phone,
                            'created_by' => (int) $existingContact->created_by,
                            'created_at' => $existingContact->created_at,
                            'first_name' => $resolvedFirstName,
                            'last_name' => $resolvedLastName,
                            'email' => $entry['email'] ?: null,
                            'address' => json_encode($entry['address'] ?? []),
                            'metadata' => ! empty($mergedMetadata) ? json_encode($mergedMetadata) : null,
                            'deleted_at' => null,
                            'updated_at' => $now,
                        ];

                        $updated++;
                    } else {
                        $contactInserts[] = [
                            'uuid' => (string) Str::uuid(),
                            'organization_id' => $organizationId,
                            'first_name' => trim((string) ($entry['first_name'] ?? '')) !== '' ? trim((string) $entry['first_name']) : 'name',
                            'last_name' => trim((string) ($entry['last_name'] ?? '')) !== '' ? trim((string) $entry['last_name']) : null,
                            'phone' => $phone,
                            'email' => $entry['email'] ?: null,
                            'address' => json_encode($entry['address'] ?? []),
                            'metadata' => ! empty($entry['custom_attributes']) ? json_encode($entry['custom_attributes']) : null,
                            'created_by' => $userId,
                            'contact_source' => 'imported',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        $created++;
                    }
                }

                if (! empty($contactUpdates)) {
                    DB::table('contacts')->upsert(
                        $contactUpdates,
                        ['id'],
                        ['first_name', 'last_name', 'email', 'address', 'metadata', 'deleted_at', 'updated_at']
                    );
                }

                if (! empty($contactInserts)) {
                    DB::table('contacts')->insert($contactInserts);
                }

                $contactIdsByPhone = DB::table('contacts')
                    ->where('organization_id', $organizationId)
                    ->whereIn('phone', $phones)
                    ->pluck('id', 'phone')
                    ->toArray();

                $desiredPairs = [];
                $failedRowIds = [];
                foreach ($entriesByPhone as $phone => $entry) {
                    $contactId = $contactIdsByPhone[$phone] ?? null;
                    if (! $contactId) {
                        foreach ((array) $entry['row_numbers'] as $rowNumber) {
                            $failed[] = [
                                'row' => $rowNumber,
                                'error' => 'Failed to resolve contact id after import.',
                            ];
                        }

                        foreach ((array) $entry['row_ids'] as $rowId) {
                            $failedRowIds[] = (int) $rowId;
                        }
                        continue;
                    }

                    foreach ((array) ($entry['all_group_ids'] ?? []) as $groupId) {
                        $desiredPairs[] = [
                            'contact_id' => (int) $contactId,
                            'contact_group_id' => (int) $groupId,
                        ];
                    }

                    foreach ((array) $entry['row_ids'] as $rowId) {
                        $rowStatusUpdates[] = [
                            'id' => (int) $rowId,
                            'contact_import_batch_id' => (int) ($rowMetaById[(int) $rowId]['contact_import_batch_id'] ?? $batch->id),
                            'row_number' => (int) ($rowMetaById[(int) $rowId]['row_number'] ?? 0),
                            'is_removed' => (int) ($rowMetaById[(int) $rowId]['is_removed'] ?? 0),
                            'created_at' => $rowMetaById[(int) $rowId]['created_at'] ?? $now,
                            'status' => 'imported',
                            'errors' => null,
                            'updated_at' => $now,
                        ];
                    }
                }

                if (! empty($desiredPairs)) {
                    $pivotInserts = collect($desiredPairs)
                        ->map(function ($pair) use ($now) {
                            return [
                                'contact_id' => (int) $pair['contact_id'],
                                'contact_group_id' => (int) $pair['contact_group_id'],
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        })
                        ->unique(fn ($pair) => $pair['contact_id'] . ':' . $pair['contact_group_id'])
                        ->values()
                        ->all();

                    if (! empty($pivotInserts)) {
                        DB::table('contact_contact_group')->insertOrIgnore($pivotInserts);
                    }
                }

                if (! empty($failedRowIds)) {
                    foreach ($failedRowIds as $rowId) {
                        $rowStatusUpdates[] = [
                            'id' => $rowId,
                            'contact_import_batch_id' => (int) ($rowMetaById[(int) $rowId]['contact_import_batch_id'] ?? $batch->id),
                            'row_number' => (int) ($rowMetaById[(int) $rowId]['row_number'] ?? 0),
                            'is_removed' => (int) ($rowMetaById[(int) $rowId]['is_removed'] ?? 0),
                            'created_at' => $rowMetaById[(int) $rowId]['created_at'] ?? $now,
                            'status' => 'failed',
                            'errors' => json_encode(['Failed to resolve contact id after import.']),
                            'updated_at' => $now,
                        ];
                    }
                }

                if (! empty($rowStatusUpdates)) {
                    DB::table('contact_import_rows')->upsert(
                        $rowStatusUpdates,
                        ['id'],
                        ['status', 'errors', 'updated_at']
                    );
                }
            });

        $removedCount = ContactImportRow::query()
            ->where('contact_import_batch_id', $batch->id)
            ->where('is_removed', true)
            ->count();

        $invalidRows = ContactImportRow::query()
            ->where('contact_import_batch_id', $batch->id)
            ->where('status', 'invalid')
            ->get(['row_number', 'errors']);

        $invalidFailures = $invalidRows->map(function (ContactImportRow $row): array {
            $errors = (array) $row->errors;
            return [
                'row' => $row->row_number,
                'error' => implode(' | ', $errors),
            ];
        })->all();

        $report = [
            'total_rows' => ContactImportRow::query()->where('contact_import_batch_id', $batch->id)->count(),
            'created_count' => $created,
            'updated_count' => $updated,
            'success_count' => $created + $updated,
            'removed_count' => $removedCount,
            'invalid_count' => $invalidRows->count(),
            'failed_count' => count($failed) + $invalidRows->count(),
            'failed_rows_details' => array_values(array_merge($invalidFailures, $failed)),
            'executed_at' => now()->toDateTimeString(),
        ];

        $batch->update([
            'status' => 'committed',
            'report' => $report,
        ]);

        return $report;
    }

    public function paginateRows(ContactImportBatch $batch, int $page = 1, int $perPage = 15, ?string $status = null): LengthAwarePaginator
    {
        $query = ContactImportRow::query()
            ->where('contact_import_batch_id', $batch->id)
            ->orderBy('row_number');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function hasRowsMissingCountryCode(ContactImportBatch $batch): bool
    {
        $mapping = (array) ($batch->mapping ?? []);
        $phoneColumn = trim((string) ($mapping['phone'] ?? ''));

        if ($phoneColumn === '') {
            return false;
        }

        $found = false;

        DB::table('contact_import_rows')
            ->select(['id', 'raw_data'])
            ->where('contact_import_batch_id', $batch->id)
            ->where('is_removed', false)
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use ($phoneColumn, &$found) {
                foreach ($rows as $row) {
                    $rawData = $this->decodeJsonArray($row->raw_data);
                    $phoneRaw = trim((string) ($rawData[$phoneColumn] ?? ''));

                    if ($this->phoneNeedsDefaultCountryCode($phoneRaw)) {
                        $found = true;
                        return false;
                    }
                }

                return true;
            });

        return $found;
    }

    private function parseUploadedFile(UploadedFile $file): array
    {
        $extension = Str::lower((string) $file->getClientOriginalExtension());

        if (in_array($extension, ['csv', 'txt'], true)) {
            return $this->parseCsvFile($file);
        }

        return $this->parseSpreadsheetFile($file);
    }

    private function parseCsvFile(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open uploaded file.');
        }

        try {
            $headerValues = fgetcsv($handle);
            if ($headerValues === false) {
                throw new \RuntimeException('Uploaded file is empty.');
            }

            $rawHeaders = array_map(fn ($value) => trim((string) $value), (array) $headerValues);
            $headers = $this->normalizeHeaders($rawHeaders);

            $rows = [];
            while (($values = fgetcsv($handle)) !== false) {
                if (count($rows) >= self::MAX_PREVIEW_ROWS) {
                    throw new \RuntimeException('The file is too large. Maximum allowed rows are ' . self::MAX_PREVIEW_ROWS . '.');
                }

                $assoc = [];
                $hasNonEmpty = false;

                foreach ($headers as $index => $header) {
                    $value = isset($values[$index]) ? trim((string) $values[$index]) : '';
                    $assoc[$header] = $value;
                    if ($value !== '') {
                        $hasNonEmpty = true;
                    }
                }

                if ($hasNonEmpty) {
                    $rows[] = $assoc;
                }
            }

            return [$headers, $rows];
        } finally {
            fclose($handle);
        }
    }

    private function parseSpreadsheetFile(UploadedFile $file): array
    {
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }

        $spreadsheet = $reader->load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();

        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        $rawHeaders = [];
        for ($column = 1; $column <= $highestColumnIndex; $column++) {
            $rawHeaders[] = trim((string) $sheet->getCell([$column, 1])->getValue());
        }

        $headers = $this->normalizeHeaders($rawHeaders);
        $rows = [];

        foreach ($sheet->getRowIterator(2) as $row) {
            if (count($rows) >= self::MAX_PREVIEW_ROWS) {
                throw new \RuntimeException('The file is too large. Maximum allowed rows are ' . self::MAX_PREVIEW_ROWS . '.');
            }

            $rowIndex = $row->getRowIndex();
            $assoc = [];
            $hasNonEmpty = false;

            foreach ($headers as $index => $header) {
                $value = trim((string) $sheet->getCell([$index + 1, $rowIndex])->getValue());
                $assoc[$header] = $value;
                if ($value !== '') {
                    $hasNonEmpty = true;
                }
            }

            if ($hasNonEmpty) {
                $rows[] = $assoc;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return [$headers, $rows];
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        $used = [];

        foreach ($headers as $index => $header) {
            $base = $header !== '' ? $header : 'Column ' . ($index + 1);
            $candidate = $base;
            $suffix = 1;

            while (isset($used[Str::lower($candidate)])) {
                $suffix++;
                $candidate = $base . ' (' . $suffix . ')';
            }

            $used[Str::lower($candidate)] = true;
            $normalized[] = $candidate;
        }

        return $normalized;
    }

    private function buildMappedData(array $rawData, array $mapping): array
    {
        $mapped = [];

        foreach (self::STANDARD_TARGET_FIELDS as $field) {
            $sourceColumn = (string) ($mapping[$field] ?? '');
            $mapped[$field] = $sourceColumn !== '' ? trim((string) ($rawData[$sourceColumn] ?? '')) : null;
        }

        $customAttributes = [];
        $customMapping = (array) ($mapping['custom_attributes'] ?? []);
        foreach ($customMapping as $customFieldName => $sourceColumn) {
            $sourceColumn = (string) $sourceColumn;
            if ($sourceColumn === '') {
                continue;
            }

            $customAttributes[$customFieldName] = trim((string) ($rawData[$sourceColumn] ?? ''));
        }

        $mapped['custom_attributes'] = $customAttributes;

        return $mapped;
    }

    private function evaluateMappedRow(array $mappedData, string $defaultCountryCode): array
    {
        $errors = [];
        $phoneRaw = trim((string) ($mappedData['phone'] ?? ''));

        if ($phoneRaw === '') {
            $errors[] = 'Phone number is required.';
        }

        $normalizedPhone = $this->normalizePhone($phoneRaw, $defaultCountryCode);
        $e164Phone = $normalizedPhone ? PhoneService::getE164Format($normalizedPhone) : null;

        if (! $e164Phone) {
            $errors[] = 'Invalid phone number format.';
        }

        $normalized = [
            'first_name' => trim((string) ($mappedData['first_name'] ?? '')) ?: null,
            'last_name' => trim((string) ($mappedData['last_name'] ?? '')) ?: null,
            'phone' => $e164Phone,
            'email' => trim((string) ($mappedData['email'] ?? '')) ?: null,
            'group_names' => $this->parseGroupNames((string) ($mappedData['group_name'] ?? '')),
            'address' => [
                'street' => trim((string) ($mappedData['street'] ?? '')) ?: null,
                'city' => trim((string) ($mappedData['city'] ?? '')) ?: null,
                'state' => trim((string) ($mappedData['state'] ?? '')) ?: null,
                'zip' => trim((string) ($mappedData['zip'] ?? '')) ?: null,
                'country' => trim((string) ($mappedData['country'] ?? '')) ?: null,
            ],
            'custom_attributes' => $this->sanitizeCustomAttributes((array) ($mappedData['custom_attributes'] ?? [])),
        ];

        return [
            'normalized' => $normalized,
            'errors' => $errors,
        ];
    }

    private function sanitizeCustomAttributes(array $customAttributes): array
    {
        $clean = [];

        foreach ($customAttributes as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }

            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private function normalizeCountryCode(string $countryCode): string
    {
        $countryCode = trim($countryCode);
        $countryCode = preg_replace('/[^\d+]/', '', $countryCode) ?: '';

        if ($countryCode !== '' && ! str_starts_with($countryCode, '+')) {
            $countryCode = '+' . ltrim($countryCode, '+');
        }

        return $countryCode;
    }

    private function normalizePhone(string $phone, string $defaultCountryCode): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        $phone = $this->convertEasternArabicDigitsToAscii($phone);

        $phone = preg_replace('/[^\d+]/u', '', $phone) ?: '';
        if ($phone === '') {
            return null;
        }

        if (str_starts_with($phone, '00')) {
            $phone = '+' . substr($phone, 2);
        }

        if (str_starts_with($phone, '+')) {
            $phone = '+' . ltrim(substr($phone, 1), '+');
            return $phone;
        }

        // If the number looks international already but without "+", keep it as such.
        $candidateInternational = '+' . ltrim($phone, '+');
        if (PhoneService::getE164Format($candidateInternational)) {
            return $candidateInternational;
        }

        $local = ltrim($phone, '0');
        if ($local === '') {
            return null;
        }

        $defaultCountryCode = $this->normalizeCountryCode($defaultCountryCode);
        if ($defaultCountryCode === '') {
            return null;
        }

        return $defaultCountryCode . $local;
    }

    private function phoneNeedsDefaultCountryCode(string $phone): bool
    {
        $phone = trim($phone);
        if ($phone === '') {
            return false;
        }

        $phone = $this->convertEasternArabicDigitsToAscii($phone);
        $phone = preg_replace('/[^\d+]/u', '', $phone) ?: '';

        if ($phone === '') {
            return false;
        }

        if (str_starts_with($phone, '+') || str_starts_with($phone, '00')) {
            return false;
        }

        $candidateInternational = '+' . ltrim($phone, '+');

        return ! (bool) PhoneService::getE164Format($candidateInternational);
    }

    private function convertEasternArabicDigitsToAscii(string $value): string
    {
        $arabicIndic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $extendedArabicIndic = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $asciiDigits = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $value = str_replace($arabicIndic, $asciiDigits, $value);
        $value = str_replace($extendedArabicIndic, $asciiDigits, $value);

        return $value;
    }

    private function parseGroupNames(string $groupName): array
    {
        if (trim($groupName) === '') {
            return [];
        }

        return collect(explode('|', $groupName))
            ->map(fn ($name) => trim($name))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function ensureGroupIdsForNames(array $groupNames, int $organizationId, int $userId, array &$groupCache, $now): void
    {
        $missingNames = [];

        foreach ($groupNames as $groupName) {
            $name = trim((string) $groupName);
            if ($name === '') {
                continue;
            }

            $cacheKey = $this->groupCacheKey($name);
            if (! isset($groupCache[$cacheKey])) {
                $missingNames[$cacheKey] = $name;
            }
        }

        if (empty($missingNames)) {
            return;
        }

        $foundGroups = ContactGroup::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereIn('name', array_values($missingNames))
            ->get(['id', 'name']);

        foreach ($foundGroups as $group) {
            $groupCache[$this->groupCacheKey((string) $group->name)] = (int) $group->id;
        }

        $toInsert = [];
        foreach ($missingNames as $cacheKey => $name) {
            if (isset($groupCache[$cacheKey])) {
                continue;
            }

            $toInsert[] = [
                'uuid' => (string) Str::uuid(),
                'organization_id' => $organizationId,
                'name' => $name,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($toInsert)) {
            DB::table('contact_groups')->insert($toInsert);

            $createdGroups = ContactGroup::query()
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->whereIn('name', array_column($toInsert, 'name'))
                ->get(['id', 'name']);

            foreach ($createdGroups as $group) {
                $groupCache[$this->groupCacheKey((string) $group->name)] = (int) $group->id;
            }
        }
    }

    private function groupCacheKey(string $name): string
    {
        return Str::lower(trim($name));
    }

    private function resolveSplitGroupId(int $organizationId, int $userId, array &$groupCache, $now, array &$splitState): ?int
    {
        if (!($splitState['enabled'] ?? false)) {
            return null;
        }

        if (($splitState['current_count'] ?? 0) >= ($splitState['size'] ?? 1)) {
            $splitState['current_index'] = (int) ($splitState['current_index'] ?? 1) + 1;
            $splitState['current_count'] = 0;
        }

        $prefix = trim((string) ($splitState['prefix'] ?? 'group'));
        $prefix = rtrim($prefix, "_ \t\n\r\0\x0B");
        $prefix = $prefix === '' ? 'group' : $prefix;
        $groupName = $prefix . '_' . ((int) ($splitState['current_index'] ?? 1));

        $this->ensureGroupIdsForNames([$groupName], $organizationId, $userId, $groupCache, $now);
        $groupId = $groupCache[$this->groupCacheKey($groupName)] ?? null;

        $splitState['current_count'] = (int) ($splitState['current_count'] ?? 0) + 1;

        return $groupId ? (int) $groupId : null;
    }

    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}

