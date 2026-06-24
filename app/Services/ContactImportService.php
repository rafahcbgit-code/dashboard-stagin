<?php

namespace App\Services;

use App\Models\ContactGroup;
use App\Models\ContactImportBatch;
use App\Models\ContactImportRow;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Services\PhoneService;

class ContactImportService
{
    public const BACKGROUND_IMPORT_THRESHOLD = 10000;

    private const MAX_PREVIEW_ROWS = 100000;
    private const INSERT_CHUNK_SIZE = 2000;
    private const PROCESSING_CHUNK_SIZE = 1000;
    private const LARGE_IMPORT_CHUNK_SIZE = 1000;
    private const LARGE_IMPORT_FAILURE_PREVIEW_LIMIT = 1000;

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
        $now = now();
        foreach ($rows as $index => $row) {
            $insertRows[] = [
                'contact_import_batch_id' => $batch->id,
                'row_number' => $index + 2,
                'status' => 'pending',
                'is_removed' => false,
                'raw_data' => $this->encodeJson($row),
                'mapped_data' => null,
                'normalized_data' => null,
                'errors' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($insertRows) >= self::INSERT_CHUNK_SIZE) {
                DB::table('contact_import_rows')->insert($insertRows);
                $insertRows = [];
            }
        }

        if (! empty($insertRows)) {
            DB::table('contact_import_rows')->insert($insertRows);
        }

        return $batch->fresh();
    }

    public function inspectUploadedFile(UploadedFile $file): array
    {
        $extension = Str::lower((string) $file->getClientOriginalExtension());

        if (in_array($extension, ['csv', 'txt'], true)) {
            return $this->inspectCsvFile($file);
        }

        return $this->inspectSpreadsheetFile($file);
    }

    public function shouldUseBackgroundImport(array $inspection): bool
    {
        return (int) ($inspection['total_rows'] ?? 0) > self::BACKGROUND_IMPORT_THRESHOLD;
    }

    public function assertLargeImportRequiredHeaders(array $headers): void
    {
        $mapping = $this->buildLargeImportMapping($headers);

        if (empty($mapping['phone'])) {
            throw ValidationException::withMessages([
                'file' => 'Large imports must include a phone column.',
            ]);
        }

        if (empty($mapping['group_name'])) {
            throw ValidationException::withMessages([
                'file' => 'Large imports must include a group_name column so contacts can be added to audiences.',
            ]);
        }
    }

    public function createBackgroundImportBatch(
        UploadedFile $file,
        array $inspection,
        int $organizationId,
        int $userId
    ): ContactImportBatch {
        $headers = (array) ($inspection['headers'] ?? []);
        $mapping = $this->buildLargeImportMapping($headers);
        $storedPath = $file->storeAs(
            'contact-imports',
            (string) Str::uuid() . '.' . Str::lower((string) $file->getClientOriginalExtension())
        );

        return ContactImportBatch::query()->create([
            'organization_id' => $organizationId,
            'created_by' => $userId,
            'status' => 'queued',
            'mode' => 'background',
            'source_file_path' => $storedPath,
            'original_file_name' => $file->getClientOriginalName(),
            'headers' => $headers,
            'mapping' => $mapping,
            'selected_group_ids' => [],
            'group_assignment' => ['mode' => 'file_group_name'],
            'total_rows' => (int) ($inspection['total_rows'] ?? 0),
            'processed_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'failed_count' => 0,
            'report' => [
                'background' => true,
                'queued_at' => now()->toDateTimeString(),
            ],
        ]);
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
                ->where(function ($query): void {
                    $query->whereNotNull('mapped_data')
                        ->orWhereNotNull('normalized_data')
                        ->orWhereNotNull('errors')
                        ->orWhere('is_removed', true)
                        ->orWhere('status', '!=', 'pending');
                })
                ->update([
                    'is_removed' => false,
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
                ->chunkById(self::PROCESSING_CHUNK_SIZE, function ($rows) use ($defaultCountryCode, $batchMapping, $batch, &$invalidRowsPreview, &$invalidRowsCount, $invalidPreviewLimit): void {
                    $updates = [];
                    $now = now();

                    foreach ($rows as $row) {
                        $rawData = $this->decodeJsonArray($row->raw_data);
                        $currentMapped = $this->decodeJsonArray($row->mapped_data);

                        $mappedData = ! empty($currentMapped)
                            ? $currentMapped
                            : $this->buildMappedData($rawData, $batchMapping);

                        $mappedData['phone'] = $this->sanitizePhoneForImportStorage((string) ($mappedData['phone'] ?? ''));

                        $evaluation = $this->evaluateMappedRow($mappedData, $defaultCountryCode);

                        if (! empty($evaluation['errors'])) {
                            $invalidRowsCount++;
                            $invalidRow = [
                                'row_number' => (int) $row->row_number,
                                'status' => 'invalid',
                                'mapped_data' => $mappedData,
                                'errors' => array_values((array) $evaluation['errors']),
                            ];
                            if (count($invalidRowsPreview) < $invalidPreviewLimit) {
                                $invalidRowsPreview[] = $invalidRow;
                            }

                            $updates[] = [
                                'id' => (int) $row->id,
                                'contact_import_batch_id' => (int) $row->contact_import_batch_id,
                                'row_number' => (int) $row->row_number,
                                'is_removed' => 1,
                                'created_at' => $row->created_at,
                                'mapped_data' => $this->encodeJson($mappedData),
                                'normalized_data' => null,
                                'errors' => $this->encodeJson(array_values((array) $evaluation['errors'])),
                                'status' => 'invalid',
                                'updated_at' => $now,
                            ];
                            continue;
                        }

                        $updates[] = [
                            'id' => (int) $row->id,
                            'contact_import_batch_id' => (int) $row->contact_import_batch_id,
                            'row_number' => (int) $row->row_number,
                            'is_removed' => (int) $row->is_removed,
                            'created_at' => $row->created_at,
                            'mapped_data' => $this->encodeJson($mappedData),
                            'normalized_data' => $this->encodeJson($evaluation['normalized']),
                            'errors' => null,
                            'status' => 'valid',
                            'updated_at' => $now,
                        ];
                    }

                    if (! empty($updates)) {
                        DB::table('contact_import_rows')->upsert(
                            $updates,
                            ['id'],
                            ['is_removed', 'mapped_data', 'normalized_data', 'errors', 'status', 'updated_at']
                        );
                    }
                });

            $batch->update([
                'report' => array_merge((array) ($batch->report ?? []), [
                    'invalid_rows_report' => [
                        'count' => $invalidRowsCount,
                        'generated_at' => now()->toISOString(),
                    ],
                ]),
            ]);
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

        $mapped['phone'] = $this->sanitizePhoneForImportStorage((string) ($mapped['phone'] ?? ''));

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
                'errors' => $this->encodeJson(['Removed by user (Clear All invalid rows)']),
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

        $normalizedMode = in_array($assignmentMode, ['all_selected', 'split', 'file_group_name'], true) ? $assignmentMode : 'all_selected';

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
            'group_counts' => [],
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
        $totalValidRows = 0;
        $uniquePhoneCount = 0;
        $contactsRemaining = (new SubscriptionUsageService())
            ->summary($organizationId, SubscriptionUsageService::CONTACTS)['remaining'];
        $remainingNewContacts = $contactsRemaining === -1 ? null : max(0, (int) $contactsRemaining);
        $fileGroupIdsByPhone = $assignmentMode === 'file_group_name'
            ? $this->buildFileGroupIdsByPhone($batch, $organizationId, $userId, $groupCache)
            : [];

        $batchId = (int) $batch->id;

        DB::table('contact_import_rows')
            ->select(['id', 'contact_import_batch_id', 'row_number', 'is_removed', 'created_at', 'normalized_data'])
            ->where('contact_import_batch_id', $batch->id)
            ->where('is_removed', false)
            ->where('status', 'valid')
            ->orderBy('id')
            ->chunkById(self::PROCESSING_CHUNK_SIZE, function ($rows) use ($batchId, $organizationId, $userId, $selectedGroupIds, $assignmentMode, $fileGroupIdsByPhone, &$splitState, &$splitAssignmentByPhone, &$groupCache, &$created, &$updated, &$failed, &$totalValidRows, &$uniquePhoneCount, &$remainingNewContacts): void {
                $now = now();
                $rowStatusUpdates = [];
                $entriesByPhone = [];
                $rowMetaById = [];
                $chunkValidRows = 0;

                foreach ($rows as $row) {
                    $rowMetaById[(int) $row->id] = [
                        'contact_import_batch_id' => (int) $row->contact_import_batch_id,
                        'row_number' => (int) $row->row_number,
                        'is_removed' => (int) $row->is_removed,
                        'created_at' => $row->created_at,
                    ];

                    $normalized = $this->decodeJsonArray($row->normalized_data);
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
                            'errors' => $this->encodeJson([$error]),
                            'updated_at' => $now,
                        ];
                        continue;
                    }

                    $chunkValidRows++;
                    $isNewPhone = ! isset($entriesByPhone[$phone]);
                    if ($isNewPhone) {
                        $uniquePhoneCount++;
                    }

                    if ($isNewPhone) {
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
                    $totalValidRows += $chunkValidRows;
                    return;
                }
                
                $totalValidRows += $chunkValidRows;

                $allGroupNames = $assignmentMode === 'file_group_name'
                    ? collect($entriesByPhone)
                        ->flatMap(fn ($entry) => (array) ($entry['group_names'] ?? []))
                        ->map(fn ($name) => trim((string) $name))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all()
                    : [];

                $this->ensureGroupIdsForNames($allGroupNames, $organizationId, $userId, $groupCache, $now);

                foreach ($entriesByPhone as $phone => $entry) {
                    $groupIdsFromFile = $assignmentMode === 'file_group_name'
                        ? collect($fileGroupIdsByPhone[$phone] ?? [])
                        : collect();

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
                $existingContacts = DB::table('contacts')
                    ->where('organization_id', $organizationId)
                    ->whereIn('phone', $phones)
                    ->get(['id', 'uuid', 'organization_id', 'phone', 'first_name', 'last_name', 'created_by', 'created_at', 'metadata', 'deleted_at'])
                    ->keyBy('phone');

                $contactUpdates = [];
                $contactInserts = [];
                $limitFailedPhones = [];

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
                            'address' => $this->encodeJson($entry['address'] ?? []),
                            'metadata' => ! empty($mergedMetadata) ? $this->encodeJson($mergedMetadata) : null,
                            'deleted_at' => null,
                            'updated_at' => $now,
                        ];

                        $updated++;
                    } else {
                        if ($remainingNewContacts !== null && $remainingNewContacts <= 0) {
                            $limitFailedPhones[$phone] = true;
                            foreach ((array) $entry['row_numbers'] as $rowNumber) {
                                $failed[] = [
                                    'row' => $rowNumber,
                                    'error' => __('Contact limit reached!'),
                                ];
                            }

                            foreach ((array) $entry['row_ids'] as $rowId) {
                                $rowStatusUpdates[] = [
                                    'id' => (int) $rowId,
                                    'contact_import_batch_id' => (int) ($rowMetaById[(int) $rowId]['contact_import_batch_id'] ?? $batchId),
                                    'row_number' => (int) ($rowMetaById[(int) $rowId]['row_number'] ?? 0),
                                    'is_removed' => (int) ($rowMetaById[(int) $rowId]['is_removed'] ?? 0),
                                    'created_at' => $rowMetaById[(int) $rowId]['created_at'] ?? $now,
                                    'status' => 'failed',
                                    'errors' => $this->encodeJson([__('Contact limit reached!')]),
                                    'updated_at' => $now,
                                ];
                            }

                            continue;
                        }

                        $contactInserts[] = [
                            'uuid' => (string) Str::uuid(),
                            'organization_id' => $organizationId,
                            'first_name' => trim((string) ($entry['first_name'] ?? '')) !== '' ? trim((string) $entry['first_name']) : 'name',
                            'last_name' => trim((string) ($entry['last_name'] ?? '')) !== '' ? trim((string) $entry['last_name']) : null,
                            'phone' => $phone,
                            'email' => $entry['email'] ?: null,
                            'address' => $this->encodeJson($entry['address'] ?? []),
                            'metadata' => ! empty($entry['custom_attributes']) ? $this->encodeJson($entry['custom_attributes']) : null,
                            'created_by' => $userId,
                            'contact_source' => 'imported',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        $created++;
                        if ($remainingNewContacts !== null) {
                            $remainingNewContacts--;
                        }
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
                    if (isset($limitFailedPhones[$phone])) {
                        continue;
                    }

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
                            'contact_import_batch_id' => (int) ($rowMetaById[(int) $rowId]['contact_import_batch_id'] ?? $batchId),
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
                            'contact_import_batch_id' => (int) ($rowMetaById[(int) $rowId]['contact_import_batch_id'] ?? $batchId),
                            'row_number' => (int) ($rowMetaById[(int) $rowId]['row_number'] ?? 0),
                            'is_removed' => (int) ($rowMetaById[(int) $rowId]['is_removed'] ?? 0),
                            'created_at' => $rowMetaById[(int) $rowId]['created_at'] ?? $now,
                            'status' => 'failed',
                            'errors' => $this->encodeJson(['Failed to resolve contact id after import.']),
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
            ->where('status', 'removed')
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

        $duplicatesCount = $totalValidRows - $uniquePhoneCount;

        $report = [
            'total_rows' => ContactImportRow::query()->where('contact_import_batch_id', $batch->id)->count(),
            'created_count' => $created,
            'updated_count' => $updated,
            'success_count' => $created + $updated,
            'removed_count' => $removedCount,
            'invalid_count' => $invalidRows->count(),
            'failed_count' => count($failed) + $invalidRows->count(),
            'duplicates_count' => $duplicatesCount,
            'failed_rows_details' => array_values(array_merge($invalidFailures, $failed)),
            'executed_at' => now()->toDateTimeString(),
        ];

        $previousReport = (array) ($batch->report ?? []);

        if (isset($previousReport['invalid_rows_report'])) {
            $report['invalid_rows_report'] = $previousReport['invalid_rows_report'];
        }

        $batch->update([
            'status' => 'committed',
            'report' => $report,
        ]);

        return $report;
    }

    public function processBackgroundImport(ContactImportBatch $batch): array
    {
        DB::connection()->disableQueryLog();

        $sourceFilePath = (string) $batch->source_file_path;
        if ($sourceFilePath === '' || ! Storage::exists($sourceFilePath)) {
            throw new \RuntimeException('Import source file was not found.');
        }

        $headers = (array) ($batch->headers ?? []);
        $mapping = $this->buildLargeImportMapping($headers);
        $this->assertLargeImportRequiredHeaders($headers);

        $organizationId = (int) $batch->organization_id;
        $userId = (int) $batch->created_by;
        $groupCache = $this->loadGroupCache($organizationId);

        $created = 0;
        $updated = 0;
        $valid = 0;
        $invalid = 0;
        $processed = 0;
        $duplicates = 0;
        $failedRowsPreview = [];
        $seenPhones = [];
        $countedPhones = [];
        $contactsRemaining = (new SubscriptionUsageService())
            ->summary($organizationId, SubscriptionUsageService::CONTACTS)['remaining'];
        $remainingNewContacts = $contactsRemaining === -1 ? null : max(0, (int) $contactsRemaining);

        $batch->update([
            'status' => 'processing',
            'started_at' => now(),
            'error_message' => null,
        ]);

        $this->clearPreviousBackgroundRows($batch);

        try {
            foreach ($this->iterateStoredFileRows(Storage::path($sourceFilePath), $headers) as $rows) {
                if (empty($rows)) {
                    continue;
                }

                $now = now();
                $entriesByPhone = [];
                $invalidRows = [];

                foreach ($rows as $item) {
                    $processed++;
                    $rowNumber = (int) ($item['row_number'] ?? $processed + 1);
                    $rawData = (array) ($item['data'] ?? []);
                    $mappedData = $this->buildMappedData($rawData, $mapping);
                    $mappedData['phone'] = $this->sanitizePhoneForImportStorage((string) ($mappedData['phone'] ?? ''));

                    $evaluation = $this->evaluateMappedRow($mappedData, '');
                    $groupNames = $this->parseGroupNames((string) ($mappedData['group_name'] ?? ''));

                    if (empty($groupNames)) {
                        $evaluation['errors'][] = 'group_name is required for large imports.';
                    }

                    if (! empty($evaluation['errors'])) {
                        $invalid++;
                        $failedRowsPreview = $this->appendFailurePreview(
                            $failedRowsPreview,
                            $rowNumber,
                            implode(' | ', array_values((array) $evaluation['errors']))
                        );
                        $invalidRows[] = [
                            'contact_import_batch_id' => (int) $batch->id,
                            'row_number' => $rowNumber,
                            'status' => 'invalid',
                            'is_removed' => false,
                            'raw_data' => $this->encodeJson($rawData),
                            'mapped_data' => $this->encodeJson($mappedData),
                            'normalized_data' => null,
                            'errors' => $this->encodeJson(array_values((array) $evaluation['errors'])),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        continue;
                    }

                    $normalized = (array) $evaluation['normalized'];
                    $phone = trim((string) ($normalized['phone'] ?? ''));
                    if ($phone === '') {
                        $invalid++;
                        $failedRowsPreview = $this->appendFailurePreview($failedRowsPreview, $rowNumber, 'Missing normalized phone.');
                        continue;
                    }

                    if (isset($seenPhones[$phone])) {
                        $duplicates++;
                    }
                    $seenPhones[$phone] = true;
                    $valid++;

                    if (! isset($entriesByPhone[$phone])) {
                        $normalized['group_names'] = $groupNames;
                        $entriesByPhone[$phone] = [
                            'phone' => $phone,
                            'first_name' => $normalized['first_name'] ?? null,
                            'last_name' => $normalized['last_name'] ?? null,
                            'email' => $normalized['email'] ?? null,
                            'address' => (array) ($normalized['address'] ?? []),
                            'custom_attributes' => (array) ($normalized['custom_attributes'] ?? []),
                            'group_names' => $groupNames,
                            'row_numbers' => [$rowNumber],
                        ];
                        continue;
                    }

                    $existing = &$entriesByPhone[$phone];
                    $existing['row_numbers'][] = $rowNumber;
                    if (trim((string) ($normalized['first_name'] ?? '')) !== '') {
                        $existing['first_name'] = $normalized['first_name'];
                    }
                    if (trim((string) ($normalized['last_name'] ?? '')) !== '') {
                        $existing['last_name'] = $normalized['last_name'];
                    }
                    if (trim((string) ($normalized['email'] ?? '')) !== '') {
                        $existing['email'] = $normalized['email'];
                    }
                    $existing['address'] = array_merge((array) $existing['address'], (array) ($normalized['address'] ?? []));
                    $existing['custom_attributes'] = array_merge((array) $existing['custom_attributes'], (array) ($normalized['custom_attributes'] ?? []));
                    $existing['group_names'] = collect(array_merge((array) $existing['group_names'], $groupNames))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();
                    unset($existing);
                }

                if (! empty($invalidRows)) {
                    foreach (array_chunk($invalidRows, self::INSERT_CHUNK_SIZE) as $chunk) {
                        DB::table('contact_import_rows')->insert($chunk);
                    }
                }

                if (! empty($entriesByPhone)) {
                    $phones = array_keys($entriesByPhone);
                    $existingContacts = DB::table('contacts')
                        ->where('organization_id', $organizationId)
                        ->whereIn('phone', $phones)
                        ->get(['id', 'phone', 'metadata'])
                        ->keyBy('phone');
                    $limitFailedPhones = [];

                    foreach ($phones as $phone) {
                        if (isset($countedPhones[$phone])) {
                            continue;
                        }

                        if ($existingContacts->has($phone)) {
                            $updated++;
                        } else {
                            if ($remainingNewContacts !== null && $remainingNewContacts <= 0) {
                                $limitFailedPhones[$phone] = true;
                                $invalid++;

                                foreach ((array) ($entriesByPhone[$phone]['row_numbers'] ?? []) as $rowNumber) {
                                    $failedRowsPreview = $this->appendFailurePreview(
                                        $failedRowsPreview,
                                        (int) $rowNumber,
                                        __('Contact limit reached!')
                                    );
                                }

                                $countedPhones[$phone] = true;
                                continue;
                            }

                            $created++;
                            if ($remainingNewContacts !== null) {
                                $remainingNewContacts--;
                            }
                        }

                        $countedPhones[$phone] = true;
                    }

                    $allGroupNames = collect($entriesByPhone)
                        ->flatMap(fn ($entry) => (array) ($entry['group_names'] ?? []))
                        ->map(fn ($name) => trim((string) $name))
                        ->filter()
                        ->unique(fn ($name) => $this->groupCacheKey((string) $name))
                        ->values()
                        ->all();

                    $this->ensureGroupIdsForNames($allGroupNames, $organizationId, $userId, $groupCache, $now);

                    $contactRows = [];
                    foreach ($entriesByPhone as $phone => $entry) {
                        if (isset($limitFailedPhones[$phone])) {
                            continue;
                        }

                        $existingContact = $existingContacts->get($phone);
                        $existingMetadata = $existingContact
                            ? $this->decodeJsonArray($existingContact->metadata ?? null)
                            : [];
                        $mergedMetadata = array_merge($existingMetadata, (array) ($entry['custom_attributes'] ?? []));

                        $contactRows[] = [
                            'uuid' => (string) Str::uuid(),
                            'organization_id' => $organizationId,
                            'first_name' => trim((string) ($entry['first_name'] ?? '')) !== '' ? trim((string) $entry['first_name']) : 'name',
                            'last_name' => trim((string) ($entry['last_name'] ?? '')) !== '' ? trim((string) $entry['last_name']) : null,
                            'phone' => $phone,
                            'email' => trim((string) ($entry['email'] ?? '')) !== '' ? trim((string) $entry['email']) : null,
                            'address' => $this->encodeJson($entry['address'] ?? []),
                            'metadata' => ! empty($mergedMetadata) ? $this->encodeJson($mergedMetadata) : null,
                            'created_by' => $userId,
                            'contact_source' => 'imported',
                            'deleted_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if (! empty($contactRows)) {
                        DB::table('contacts')->upsert(
                            $contactRows,
                            ['organization_id', 'phone'],
                            ['first_name', 'last_name', 'email', 'address', 'metadata', 'contact_source', 'deleted_at', 'updated_at']
                        );
                    }

                    $contactIdsByPhone = DB::table('contacts')
                        ->where('organization_id', $organizationId)
                        ->whereIn('phone', $phones)
                        ->pluck('id', 'phone')
                        ->toArray();

                    $pivotRows = [];
                    foreach ($entriesByPhone as $phone => $entry) {
                        if (isset($limitFailedPhones[$phone])) {
                            continue;
                        }

                        $contactId = $contactIdsByPhone[$phone] ?? null;
                        if (! $contactId) {
                            continue;
                        }

                        foreach ((array) ($entry['group_names'] ?? []) as $groupName) {
                            $groupId = $groupCache[$this->groupCacheKey((string) $groupName)] ?? null;
                            if (! $groupId) {
                                continue;
                            }

                            $pivotRows[] = [
                                'contact_id' => (int) $contactId,
                                'contact_group_id' => (int) $groupId,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }

                    if (! empty($pivotRows)) {
                        DB::table('contact_contact_group')->insertOrIgnore(
                            collect($pivotRows)
                                ->unique(fn ($row) => $row['contact_id'] . ':' . $row['contact_group_id'])
                                ->values()
                                ->all()
                        );
                    }
                }

                $batch->update([
                    'processed_rows' => $processed,
                    'valid_rows' => $valid,
                    'invalid_rows' => $invalid,
                    'created_count' => $created,
                    'updated_count' => $updated,
                    'failed_count' => $invalid,
                    'report' => $this->buildBackgroundReport($batch, $created, $updated, $valid, $invalid, $duplicates, $failedRowsPreview, false),
                ]);
            }

            $report = $this->buildBackgroundReport($batch, $created, $updated, $valid, $invalid, $duplicates, $failedRowsPreview, true);

            $batch->update([
                'status' => 'committed',
                'processed_rows' => $processed,
                'valid_rows' => $valid,
                'invalid_rows' => $invalid,
                'created_count' => $created,
                'updated_count' => $updated,
                'failed_count' => $invalid,
                'finished_at' => now(),
                'report' => $report,
            ]);

            return $report;
        } catch (\Throwable $exception) {
            $batch->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
                'report' => array_merge((array) ($batch->report ?? []), [
                    'failed_at' => now()->toDateTimeString(),
                    'error_message' => $exception->getMessage(),
                ]),
            ]);

            throw $exception;
        }
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
        return $this->countRowsMissingCountryCode($batch) > 0;
    }

    public function countRowsMissingCountryCode(ContactImportBatch $batch): int
    {
        $mapping = (array) ($batch->mapping ?? []);
        $phoneColumn = trim((string) ($mapping['phone'] ?? ''));

        if ($phoneColumn === '') {
            return 0;
        }

        $count = 0;

        DB::table('contact_import_rows')
            ->select(['id', 'raw_data'])
            ->where('contact_import_batch_id', $batch->id)
            ->where('is_removed', false)
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use ($phoneColumn, &$count) {
                foreach ($rows as $row) {
                    $rawData = $this->decodeJsonArray($row->raw_data);
                    $phoneRaw = trim((string) $this->valueForSourceColumn($rawData, $phoneColumn));

                    if ($this->phoneNeedsDefaultCountryCode($phoneRaw)) {
                        $count++;
                    }
                }

                return true;
            });

        return $count;
    }

    public function existingGroupedContactsSummary(ContactImportBatch $batch, int $organizationId): array
    {
        $phones = [];

        DB::table('contact_import_rows')
            ->select(['id', 'normalized_data'])
            ->where('contact_import_batch_id', $batch->id)
            ->where('is_removed', false)
            ->where('status', 'valid')
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$phones) {
                foreach ($rows as $row) {
                    $normalized = $this->decodeJsonArray($row->normalized_data);
                    $phone = trim((string) ($normalized['phone'] ?? ''));
                    if ($phone !== '') {
                        $phones[$phone] = true;
                    }
                }

                return true;
            });

        $phones = array_keys($phones);
        if (empty($phones)) {
            return [
                'count' => 0,
                'sample_phones' => [],
            ];
        }

        $matchedPhones = DB::table('contacts')
            ->join('contact_contact_group', 'contacts.id', '=', 'contact_contact_group.contact_id')
            ->where('contacts.organization_id', $organizationId)
            ->whereNull('contacts.deleted_at')
            ->whereIn('contacts.phone', $phones)
            ->distinct()
            ->orderBy('contacts.phone')
            ->pluck('contacts.phone')
            ->all();

        return [
            'count' => count($matchedPhones),
            'sample_phones' => array_slice($matchedPhones, 0, 5),
        ];
    }

    private function inspectCsvFile(UploadedFile $file): array
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

            $headers = $this->normalizeHeaders(array_map(fn ($value) => trim((string) $value), (array) $headerValues));
            $totalRows = 0;

            while (($values = fgetcsv($handle)) !== false) {
                $hasNonEmpty = collect($values)->contains(fn ($value) => trim((string) $value) !== '');
                if ($hasNonEmpty) {
                    $totalRows++;
                }
            }

            return [
                'headers' => $headers,
                'total_rows' => $totalRows,
                'extension' => Str::lower((string) $file->getClientOriginalExtension()),
            ];
        } finally {
            fclose($handle);
        }
    }

    private function inspectSpreadsheetFile(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $reader = IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }

        $worksheetInfo = $reader->listWorksheetInfo($path);
        $firstSheet = $worksheetInfo[0] ?? [];
        $totalRows = max(0, (int) ($firstSheet['totalRows'] ?? 1) - 1);

        $reader->setReadFilter($this->rowRangeFilter(1, 1));
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        $rawHeaders = [];
        for ($column = 1; $column <= $highestColumnIndex; $column++) {
            $rawHeaders[] = trim((string) $sheet->getCell([$column, 1])->getValue());
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return [
            'headers' => $this->normalizeHeaders($rawHeaders),
            'total_rows' => $totalRows,
            'extension' => Str::lower((string) $file->getClientOriginalExtension()),
        ];
    }

    private function buildLargeImportMapping(array $headers): array
    {
        $mapping = [
            'first_name' => $this->findHeaderByAliases($headers, ['first_name', 'first name', 'name']),
            'last_name' => $this->findHeaderByAliases($headers, ['last_name', 'last name']),
            'phone' => $this->findHeaderByAliases($headers, ['phone', 'mobile', 'whatsapp']),
            'email' => $this->findHeaderByAliases($headers, ['email', 'mail']),
            'group_name' => $this->findHeaderByAliases($headers, ['group_name', 'group name']),
            'street' => $this->findHeaderByAliases($headers, ['street']),
            'city' => $this->findHeaderByAliases($headers, ['city']),
            'state' => $this->findHeaderByAliases($headers, ['state']),
            'zip' => $this->findHeaderByAliases($headers, ['zip', 'postal']),
            'country' => $this->findHeaderByAliases($headers, ['country']),
            'custom_attributes' => [],
        ];

        return $mapping;
    }

    private function findHeaderByAliases(array $headers, array $aliases): string
    {
        $normalizedAliases = collect($aliases)
            ->map(fn ($alias) => $this->normalizeColumnKey((string) $alias))
            ->filter()
            ->values()
            ->all();

        foreach ($headers as $header) {
            $normalizedHeader = $this->normalizeColumnKey((string) $header);
            if ($normalizedHeader === '') {
                continue;
            }

            foreach ($normalizedAliases as $normalizedAlias) {
                if ($normalizedHeader === $normalizedAlias) {
                    return (string) $header;
                }

                if (strlen($normalizedAlias) >= 5 && str_contains($normalizedHeader, $normalizedAlias)) {
                    return (string) $header;
                }
            }
        }

        return '';
    }

    private function loadGroupCache(int $organizationId): array
    {
        $groupCacheRaw = ContactGroup::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->pluck('id', 'name')
            ->toArray();

        $groupCache = [];
        foreach ($groupCacheRaw as $name => $id) {
            $groupCache[$this->groupCacheKey((string) $name)] = (int) $id;
        }

        return $groupCache;
    }

    private function clearPreviousBackgroundRows(ContactImportBatch $batch): void
    {
        ContactImportRow::query()
            ->where('contact_import_batch_id', $batch->id)
            ->delete();
    }

    private function iterateStoredFileRows(string $path, array $headers): \Generator
    {
        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['csv', 'txt'], true)) {
            yield from $this->iterateCsvRows($path, $headers);
            return;
        }

        yield from $this->iterateSpreadsheetRows($path, $headers);
    }

    private function iterateCsvRows(string $path, array $headers): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open stored import file.');
        }

        try {
            fgetcsv($handle);
            $chunk = [];
            $rowNumber = 1;

            while (($values = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $assoc = [];
                $hasNonEmpty = false;

                foreach ($headers as $index => $header) {
                    $value = isset($values[$index]) ? trim((string) $values[$index]) : '';
                    $assoc[$header] = $value;
                    if ($value !== '') {
                        $hasNonEmpty = true;
                    }
                }

                if (! $hasNonEmpty) {
                    continue;
                }

                $chunk[] = [
                    'row_number' => $rowNumber,
                    'data' => $assoc,
                ];

                if (count($chunk) >= self::LARGE_IMPORT_CHUNK_SIZE) {
                    yield $chunk;
                    $chunk = [];
                }
            }

            if (! empty($chunk)) {
                yield $chunk;
            }
        } finally {
            fclose($handle);
        }
    }

    private function iterateSpreadsheetRows(string $path, array $headers): \Generator
    {
        $reader = IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }

        $worksheetInfo = $reader->listWorksheetInfo($path);
        $firstSheet = $worksheetInfo[0] ?? [];
        $lastRow = (int) ($firstSheet['totalRows'] ?? 1);

        for ($startRow = 2; $startRow <= $lastRow; $startRow += self::LARGE_IMPORT_CHUNK_SIZE) {
            $endRow = min($lastRow, $startRow + self::LARGE_IMPORT_CHUNK_SIZE - 1);
            $reader = IOFactory::createReaderForFile($path);
            if (method_exists($reader, 'setReadDataOnly')) {
                $reader->setReadDataOnly(true);
            }
            $reader->setReadFilter($this->rowRangeFilter($startRow, $endRow));

            $spreadsheet = $reader->load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $chunk = [];

            for ($rowNumber = $startRow; $rowNumber <= $endRow; $rowNumber++) {
                $assoc = [];
                $hasNonEmpty = false;

                foreach ($headers as $index => $header) {
                    $value = trim((string) $sheet->getCell([$index + 1, $rowNumber])->getValue());
                    $assoc[$header] = $value;
                    if ($value !== '') {
                        $hasNonEmpty = true;
                    }
                }

                if ($hasNonEmpty) {
                    $chunk[] = [
                        'row_number' => $rowNumber,
                        'data' => $assoc,
                    ];
                }
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            if (! empty($chunk)) {
                yield $chunk;
            }
        }
    }

    private function rowRangeFilter(int $startRow, int $endRow): IReadFilter
    {
        return new class($startRow, $endRow) implements IReadFilter {
            public function __construct(private readonly int $startRow, private readonly int $endRow)
            {
            }

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row >= $this->startRow && $row <= $this->endRow;
            }
        };
    }

    private function appendFailurePreview(array $failedRowsPreview, int $rowNumber, string $error): array
    {
        if (count($failedRowsPreview) >= self::LARGE_IMPORT_FAILURE_PREVIEW_LIMIT) {
            return $failedRowsPreview;
        }

        $failedRowsPreview[] = [
            'row' => $rowNumber,
            'error' => $error,
        ];

        return $failedRowsPreview;
    }

    private function buildBackgroundReport(
        ContactImportBatch $batch,
        int $created,
        int $updated,
        int $valid,
        int $invalid,
        int $duplicates,
        array $failedRowsPreview,
        bool $finished
    ): array {
        return [
            'background' => true,
            'total_rows' => (int) $batch->total_rows,
            'created_count' => $created,
            'updated_count' => $updated,
            'success_count' => $created + $updated,
            'valid_count' => $valid,
            'invalid_count' => $invalid,
            'failed_count' => $invalid,
            'duplicates_count' => $duplicates,
            'failed_rows_details' => $failedRowsPreview,
            'invalid_rows_report' => [
                'count' => $invalid,
                'generated_at' => now()->toISOString(),
                'limited' => $invalid > count($failedRowsPreview),
            ],
            'executed_at' => $finished ? now()->toDateTimeString() : null,
        ];
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
            $mapped[$field] = $sourceColumn !== '' ? trim((string) $this->valueForSourceColumn($rawData, $sourceColumn)) : null;
        }

        $mapped['phone'] = $this->sanitizePhoneForImportStorage((string) ($mapped['phone'] ?? ''));

        $customAttributes = [];
        $customMapping = (array) ($mapping['custom_attributes'] ?? []);
        foreach ($customMapping as $customFieldName => $sourceColumn) {
            $sourceColumn = (string) $sourceColumn;
            if ($sourceColumn === '') {
                continue;
            }

            $customAttributes[$customFieldName] = trim((string) $this->valueForSourceColumn($rawData, $sourceColumn));
        }

        $mapped['custom_attributes'] = $customAttributes;

        return $mapped;
    }

    private function valueForSourceColumn(array $rawData, string $sourceColumn): mixed
    {
        if (array_key_exists($sourceColumn, $rawData)) {
            return $rawData[$sourceColumn];
        }

        $normalizedSourceColumn = $this->normalizeColumnKey($sourceColumn);
        foreach ($rawData as $column => $value) {
            if ($this->normalizeColumnKey((string) $column) === $normalizedSourceColumn) {
                return $value;
            }
        }

        return '';
    }

    private function normalizeColumnKey(string $column): string
    {
        $column = Str::lower(trim($column));

        return preg_replace('/[^a-z0-9]+/', '', $column) ?: '';
    }

    private function encodeJson(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }

    private function evaluateMappedRow(array $mappedData, string $defaultCountryCode): array
    {
        $errors = [];
        $phoneRaw = trim((string) ($mappedData['phone'] ?? ''));

        if ($phoneRaw === '') {
            $errors[] = 'Phone number is required.';
        }

        $normalizedPhone = $this->normalizePhone($phoneRaw, $defaultCountryCode);
        $e164Phone = $normalizedPhone ? PhoneService::getE164FormatForImport($normalizedPhone) : null;

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
        $phone = $this->sanitizePhoneForImportStorage($phone);
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

        // Accept international numbers that were entered without "+" first.
        if (strlen($phone) >= 10) {
            $candidateInternational = '+' . ltrim($phone, '+');
            if (PhoneService::getE164FormatForImport($candidateInternational)) {
                return $candidateInternational;
            }
        }

        $local = ltrim($phone, '0');
        if ($local === '') {
            return null;
        }

        $defaultCountryCode = $this->normalizeCountryCode($defaultCountryCode);
        if ($defaultCountryCode !== '') {
            return $defaultCountryCode . $local;
        }

        return null;
    }

    private function sanitizePhoneForImportStorage(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }

        $phone = $this->convertEasternArabicDigitsToAscii($phone);
        $phone = ltrim($phone, "=\t\n\r\0\x0B ");
        $phone = preg_replace('/[^\d+]/u', '', $phone) ?: '';

        if (str_starts_with($phone, '+')) {
            $digits = preg_replace('/\D+/', '', substr($phone, 1)) ?: '';

            return $digits !== '' ? '+' . $digits : '';
        }

        return preg_replace('/\D+/', '', $phone) ?: '';
    }

    private function phoneNeedsDefaultCountryCode(string $phone): bool
    {
        $phone = $this->sanitizePhoneForImportStorage($phone);
        if ($phone === '') {
            return false;
        }

        if (str_starts_with($phone, '+') || str_starts_with($phone, '00')) {
            return false;
        }

        if (strlen($phone) >= 10) {
            $candidateInternational = '+' . ltrim($phone, '+');
            if (PhoneService::getE164FormatForImport($candidateInternational)) {
                return false;
            }
        }

        return (bool) preg_match('/^\d{4,}$/', $phone);
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

    private function buildFileGroupIdsByPhone(ContactImportBatch $batch, int $organizationId, int $userId, array &$groupCache): array
    {
        $groupNamesByPhone = [];

        DB::table('contact_import_rows')
            ->select(['id', 'normalized_data'])
            ->where('contact_import_batch_id', $batch->id)
            ->where('is_removed', false)
            ->where('status', 'valid')
            ->orderBy('id')
            ->chunkById(self::PROCESSING_CHUNK_SIZE, function ($rows) use (&$groupNamesByPhone): void {
                foreach ($rows as $row) {
                    $normalized = $this->decodeJsonArray($row->normalized_data);
                    $phone = trim((string) ($normalized['phone'] ?? ''));

                    if ($phone === '') {
                        continue;
                    }

                    if (! isset($groupNamesByPhone[$phone])) {
                        $groupNamesByPhone[$phone] = [];
                    }

                    foreach ((array) ($normalized['group_names'] ?? []) as $groupName) {
                        $groupName = trim((string) $groupName);

                        if ($groupName !== '') {
                            $groupNamesByPhone[$phone][$this->groupCacheKey($groupName)] = $groupName;
                        }
                    }
                }
            }, 'id');

        $allGroupNames = collect($groupNamesByPhone)
            ->flatMap(fn ($names) => array_values((array) $names))
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn ($name) => $this->groupCacheKey((string) $name))
            ->values()
            ->all();

        $this->ensureGroupIdsForNames($allGroupNames, $organizationId, $userId, $groupCache, now());

        return collect($groupNamesByPhone)
            ->mapWithKeys(function (array $names, string $phone) use ($groupCache): array {
                $groupIds = collect($names)
                    ->map(fn ($name) => $groupCache[$this->groupCacheKey((string) $name)] ?? null)
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                return [$phone => $groupIds];
            })
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

        $prefix = trim((string) ($splitState['prefix'] ?? 'group'));
        $prefix = rtrim($prefix, "_ \t\n\r\0\x0B");
        $prefix = $prefix === '' ? 'group' : $prefix;
        $size = max(1, (int) ($splitState['size'] ?? 1));

        while (true) {
            if (($splitState['current_count'] ?? 0) >= $size) {
                $splitState['current_index'] = (int) ($splitState['current_index'] ?? 1) + 1;
                $splitState['current_count'] = 0;
            }

            $groupName = $prefix . '_' . ((int) ($splitState['current_index'] ?? 1));
            $groupKey = $this->groupCacheKey($groupName);

            $this->ensureGroupIdsForNames([$groupName], $organizationId, $userId, $groupCache, $now);
            $groupId = $groupCache[$groupKey] ?? null;

            if (! $groupId) {
                return null;
            }

            if (! isset($splitState['group_counts'][$groupKey])) {
                $splitState['group_counts'][$groupKey] = DB::table('contact_contact_group')
                    ->where('contact_group_id', (int) $groupId)
                    ->distinct('contact_id')
                    ->count('contact_id');
            }

            if ((int) $splitState['group_counts'][$groupKey] >= $size) {
                $splitState['current_index'] = (int) ($splitState['current_index'] ?? 1) + 1;
                $splitState['current_count'] = 0;
                continue;
            }

            $splitState['group_counts'][$groupKey] = (int) $splitState['group_counts'][$groupKey] + 1;
            $splitState['current_count'] = (int) $splitState['group_counts'][$groupKey];

            return (int) $groupId;
        }
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

