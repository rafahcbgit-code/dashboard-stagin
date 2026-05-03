<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Models\ContactField;
use App\Models\ContactImportBatch;
use App\Models\ContactImportRow;
use App\Services\ContactImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ContactImportController extends BaseController
{
    public function __construct(private readonly ContactImportService $importService)
    {
    }

    public function preview(Request $request)
    {
        set_time_limit(0);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:51200'],
        ]);

        $file = $validated['file'];
        $organizationId = $this->organizationId();
        $userId = (int) auth()->id();

        Log::info('Contact import preview started', [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'file_name' => $file->getClientOriginalName(),
            'file_extension' => $file->getClientOriginalExtension(),
            'file_size_bytes' => $file->getSize(),
            'mime_type' => $file->getClientMimeType(),
        ]);

        try {
            $batch = $this->importService->createPreview(
                $file,
                $organizationId,
                $userId
            );
        } catch (Throwable $exception) {
            Log::error('Contact import preview failed', [
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'file_name' => $file->getClientOriginalName(),
                'file_extension' => $file->getClientOriginalExtension(),
                'file_size_bytes' => $file->getSize(),
                'mime_type' => $file->getClientMimeType(),
                'exception_message' => $exception->getMessage(),
                'exception_class' => $exception::class,
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }

        Log::info('Contact import preview completed', [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'batch_uuid' => $batch->uuid,
            'batch_status' => $batch->status,
            'headers_count' => count((array) ($batch->headers ?? [])),
        ]);

        // Fast preview response: only what Step 2 (mapping) needs immediately.
        return response()->json([
            'batch' => [
                'uuid' => $batch->uuid,
                'status' => $batch->status,
                'headers' => $batch->headers ?? [],
                'mapping' => $batch->mapping ?? [],
                'default_country_code' => $batch->default_country_code,
                'selected_group_ids' => $batch->selected_group_ids ?? [],
                'group_assignment' => $batch->group_assignment ?? null,
                'report' => $batch->report,
            ],
            'custom_fields' => ContactField::query()
                ->where('organization_id', $this->organizationId())
                ->whereNull('deleted_at')
                ->pluck('name')
                ->all(),
        ]);
    }

    public function map(Request $request, string $batchUuid)
    {
        set_time_limit(0);

        $batch = $this->findBatch($batchUuid);
        $this->assertNotCommitted($batch);

        $validated = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.phone' => ['required', 'string'],
            'mapping.first_name' => ['nullable', 'string'],
            'mapping.last_name' => ['nullable', 'string'],
            'mapping.email' => ['nullable', 'string'],
            'mapping.group_name' => ['nullable', 'string'],
            'mapping.street' => ['nullable', 'string'],
            'mapping.city' => ['nullable', 'string'],
            'mapping.state' => ['nullable', 'string'],
            'mapping.zip' => ['nullable', 'string'],
            'mapping.country' => ['nullable', 'string'],
            'mapping.custom_attributes' => ['nullable', 'array'],
        ]);

        $this->assertMappingUsesKnownHeaders($batch, $validated['mapping']);

        $batch = $this->importService->applyMapping($batch, $validated['mapping']);
        $response = $this->buildBatchResponse($batch);
        $response['batch']['requires_default_country_code'] = $this->importService->hasRowsMissingCountryCode($batch);

        return response()->json($response);
    }

    public function normalize(Request $request, string $batchUuid)
    {
        set_time_limit(0);

        $batch = $this->findBatch($batchUuid);
        $this->assertNotCommitted($batch);
        $this->assertBatchHasMapping($batch);

        $validated = $request->validate([
            'default_country_code' => ['nullable', 'string', 'max:8'],
        ]);

        $defaultCountryCode = (string) ($validated['default_country_code'] ?? '');

        if ($this->importService->hasRowsMissingCountryCode($batch) && trim($defaultCountryCode) === '') {
            throw ValidationException::withMessages([
                'default_country_code' => 'Please choose a default country code for local phone numbers.',
            ]);
        }

        $normalization = $this->importService->normalize($batch, $defaultCountryCode);
        $batch = $normalization['batch'];

        return response()->json(array_merge(
            $this->buildBatchResponse($batch),
            [
                'invalid_preview' => [
                    'count' => (int) ($normalization['invalid_rows_count'] ?? 0),
                    'rows' => (array) ($normalization['invalid_rows_preview'] ?? []),
                    'limited' => (bool) ($normalization['invalid_rows_preview_limited'] ?? false),
                ],
                'existing_grouped_contacts' => $this->importService->existingGroupedContactsSummary(
                    $batch,
                    $this->organizationId()
                ),
            ]
        ));
    }

    public function rows(Request $request, string $batchUuid)
    {
        $batch = $this->findBatch($batchUuid);

        return response()->json(
            $this->buildBatchResponse(
                $batch,
                (int) $request->query('page', 1),
                (int) $request->query('per_page', 15),
                (string) $request->query('status', 'all')
            )
        );
    }

    public function updateRow(Request $request, string $batchUuid, int $rowId)
    {
        $batch = $this->findBatch($batchUuid);
        $this->assertNotCommitted($batch);
        $this->assertBatchAllowsRowEdits($batch);
        $row = $this->findRow($batch, $rowId);

        $validated = $request->validate([
            'first_name' => ['nullable', 'string'],
            'last_name' => ['nullable', 'string'],
            'phone' => ['required', 'string'],
            'email' => ['nullable', 'string'],
            'group_name' => ['nullable', 'string'],
            'street' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'state' => ['nullable', 'string'],
            'zip' => ['nullable', 'string'],
            'country' => ['nullable', 'string'],
            'custom_attributes' => ['nullable', 'array'],
        ]);

        $updated = $this->importService->updateRow($batch, $row, $validated);

        return response()->json([
            'row' => $updated,
            'batch' => $this->batchStats($batch->fresh()),
        ]);
    }

    public function deleteRow(string $batchUuid, int $rowId)
    {
        $batch = $this->findBatch($batchUuid);
        $this->assertNotCommitted($batch);
        $row = $this->findRow($batch, $rowId);
        $deleted = $this->importService->removeRow($row);

        return response()->json([
            'row' => $deleted,
            'batch' => $this->batchStats($batch->fresh()),
        ]);
    }

    public function clearInvalidRows(string $batchUuid)
    {
        $batch = $this->findBatch($batchUuid);
        $this->assertNotCommitted($batch);
        $this->assertBatchAllowsRowEdits($batch);

        $removedCount = $this->importService->removeInvalidRows($batch);

        return response()->json([
            'removed_count' => $removedCount,
            'batch' => $this->buildBatchResponse($batch->fresh(), 1, 15, 'invalid'),
        ]);
    }

    public function assignGroups(Request $request, string $batchUuid)
    {
        $batch = $this->findBatch($batchUuid);
        $this->assertNotCommitted($batch);
        $this->assertBatchNormalized($batch);

        $validated = $request->validate([
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer'],
            'new_group_name' => ['nullable', 'string', 'max:255'],
            'assignment_mode' => ['required', 'string', 'in:all_selected,split,file_group_name'],
            'split_group_size' => ['nullable', 'integer', 'min:1', 'max:50000'],
            'split_group_prefix' => ['nullable', 'string', 'max:120'],
        ]);

        if (($validated['assignment_mode'] ?? 'all_selected') === 'split') {
            $request->validate([
                'split_group_size' => ['required', 'integer', 'min:1', 'max:50000'],
                'split_group_prefix' => ['required', 'string', 'max:120'],
            ]);
        }

        $validGroupIds = collect($validated['group_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();

        if ($validGroupIds->isNotEmpty()) {
            $ownedGroupIds = \App\Models\ContactGroup::query()
                ->where('organization_id', $this->organizationId())
                ->whereNull('deleted_at')
                ->whereIn('id', $validGroupIds->all())
                ->pluck('id');

            if ($ownedGroupIds->count() !== $validGroupIds->count()) {
                throw ValidationException::withMessages([
                    'group_ids' => 'One or more groups are invalid for the current organization.',
                ]);
            }
        }

        $batch = $this->importService->assignGroups(
            $batch,
            $validGroupIds->all(),
            $validated['new_group_name'] ?? null,
            $validated['assignment_mode'] ?? 'all_selected',
            isset($validated['split_group_size']) ? (int) $validated['split_group_size'] : null,
            isset($validated['split_group_prefix']) ? (string) $validated['split_group_prefix'] : null,
            $this->organizationId(),
            auth()->id()
        );

        return response()->json($this->buildBatchResponse($batch));
    }

    public function commit(string $batchUuid)
    {
        set_time_limit(0);

        $batch = $this->findBatch($batchUuid);
        $this->assertNotCommitted($batch);
        $this->assertBatchNormalized($batch);

        $invalidCount = ContactImportRow::query()
            ->where('contact_import_batch_id', $batch->id)
            ->where('is_removed', false)
            ->where('status', 'invalid')
            ->count();

        if ($invalidCount > 0) {
            throw ValidationException::withMessages([
                'batch' => 'There are invalid rows. Fix or remove them before import.',
            ]);
        }

        $report = $this->importService->commit(
            $batch,
            $this->organizationId(),
            auth()->id()
        );

        return response()->json([
            'report' => $report,
            'batch' => $this->buildBatchResponse($batch->fresh()),
        ]);
    }

    public function reportCsv(string $batchUuid): StreamedResponse
    {
        $batch = $this->findBatch($batchUuid);
        $details = (array) ($batch->report['failed_rows_details'] ?? []);

        return response()->streamDownload(function () use ($details) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Row', 'Error']);

            foreach ($details as $item) {
                fputcsv($handle, [
                    $item['row'] ?? '',
                    $item['error'] ?? '',
                ]);
            }

            fclose($handle);
        }, 'contact-import-failures.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function invalidReportCsv(string $batchUuid): StreamedResponse
    {
        $batch = $this->findBatch($batchUuid);
        $storedReport = (array) ($batch->report['invalid_rows_report']['rows'] ?? []);
        $batchId = (int) $batch->id;

        return response()->streamDownload(function () use ($storedReport, $batchId) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Row', 'Phone', 'First Name', 'Last Name', 'Email', 'Audince', 'Errors']);

            foreach ($storedReport as $item) {
                $mappedData = (array) ($item['mapped_data'] ?? []);

                fputcsv($handle, [
                    $item['row_number'] ?? '',
                    $mappedData['phone'] ?? '',
                    $mappedData['first_name'] ?? '',
                    $mappedData['last_name'] ?? '',
                    $mappedData['email'] ?? '',
                    $mappedData['group_name'] ?? '',
                    implode(' | ', (array) ($item['errors'] ?? [])),
                ]);
            }

            if (empty($storedReport)) {
                ContactImportRow::query()
                    ->where('contact_import_batch_id', $batchId)
                    ->where('status', 'invalid')
                    ->orderBy('row_number')
                    ->chunk(1000, function ($rows) use ($handle): void {
                        foreach ($rows as $row) {
                            $mappedData = (array) ($row->mapped_data ?? []);

                            fputcsv($handle, [
                                $row->row_number,
                                $mappedData['phone'] ?? '',
                                $mappedData['first_name'] ?? '',
                                $mappedData['last_name'] ?? '',
                                $mappedData['email'] ?? '',
                                $mappedData['group_name'] ?? '',
                                implode(' | ', (array) ($row->errors ?? [])),
                            ]);
                        }
                    });
            }

            fclose($handle);
        }, 'contact-import-invalid-numbers.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function buildBatchResponse(ContactImportBatch $batch, int $page = 1, int $perPage = 15, string $status = 'all'): array
    {
        $rows = $this->importService->paginateRows($batch, $page, max(1, min($perPage, 100)), $status);
        $missingDefaultCountryCodeCount = 0;

        if ($batch->status === 'mapped') {
            $missingDefaultCountryCodeCount = $this->importService->countRowsMissingCountryCode($batch);
        }

        return [
            'batch' => [
                'uuid' => $batch->uuid,
                'status' => $batch->status,
                'headers' => $batch->headers ?? [],
                'mapping' => $batch->mapping ?? [],
                'default_country_code' => $batch->default_country_code,
                'selected_group_ids' => $batch->selected_group_ids ?? [],
                'group_assignment' => $batch->group_assignment ?? null,
                'report' => $batch->report,
                'requires_default_country_code' => $missingDefaultCountryCodeCount > 0,
                'missing_default_country_code_count' => $missingDefaultCountryCodeCount,
                'stats' => $this->batchStats($batch),
            ],
            'rows' => $rows,
            'custom_fields' => ContactField::query()
                ->where('organization_id', $this->organizationId())
                ->whereNull('deleted_at')
                ->pluck('name')
                ->all(),
        ];
    }

    private function batchStats(ContactImportBatch $batch): array
    {
        $stats = DB::table('contact_import_rows')
            ->where('contact_import_batch_id', $batch->id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'valid' THEN 1 ELSE 0 END) as valid")
            ->selectRaw("SUM(CASE WHEN status = 'invalid' THEN 1 ELSE 0 END) as invalid")
            ->selectRaw("SUM(CASE WHEN status = 'removed' THEN 1 ELSE 0 END) as removed")
            ->selectRaw("SUM(CASE WHEN status = 'imported' THEN 1 ELSE 0 END) as imported")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->first();

        $storedInvalidCount = (int) data_get($batch->report, 'invalid_rows_report.count', 0);
        $databaseInvalidCount = (int) ($stats->invalid ?? 0);
        $deletedInvalidCount = max(0, $storedInvalidCount - $databaseInvalidCount);

        return [
            'total' => (int) ($stats->total ?? 0) + $deletedInvalidCount,
            'valid' => (int) ($stats->valid ?? 0),
            'invalid' => max($databaseInvalidCount, $storedInvalidCount),
            'removed' => (int) ($stats->removed ?? 0),
            'imported' => (int) ($stats->imported ?? 0),
            'failed' => (int) ($stats->failed ?? 0),
        ];
    }

    private function findBatch(string $uuid): ContactImportBatch
    {
        return ContactImportBatch::query()
            ->where('uuid', $uuid)
            ->where('organization_id', $this->organizationId())
            ->firstOrFail();
    }

    private function findRow(ContactImportBatch $batch, int $rowId): ContactImportRow
    {
        return ContactImportRow::query()
            ->where('id', $rowId)
            ->where('contact_import_batch_id', $batch->id)
            ->firstOrFail();
    }

    private function organizationId(): int
    {
        return (int) session()->get('current_organization');
    }

    private function assertNotCommitted(ContactImportBatch $batch): void
    {
        if ($batch->status === 'committed') {
            throw ValidationException::withMessages([
                'batch' => 'This import batch is already committed and cannot be modified.',
            ]);
        }
    }

    private function assertBatchHasMapping(ContactImportBatch $batch): void
    {
        $mapping = (array) ($batch->mapping ?? []);
        if (empty($mapping) || empty($mapping['phone'])) {
            throw ValidationException::withMessages([
                'mapping' => 'Please complete field mapping before normalization.',
            ]);
        }
    }

    private function assertBatchNormalized(ContactImportBatch $batch): void
    {
        if (! in_array($batch->status, ['normalized', 'group_assigned'], true)) {
            throw ValidationException::withMessages([
                'batch' => 'Please run normalization before this action.',
            ]);
        }
    }

    private function assertBatchAllowsRowEdits(ContactImportBatch $batch): void
    {
        if (! in_array($batch->status, ['mapped', 'normalized', 'group_assigned'], true)) {
            throw ValidationException::withMessages([
                'batch' => 'Please complete mapping before editing rows.',
            ]);
        }
    }

    private function assertMappingUsesKnownHeaders(ContactImportBatch $batch, array $mapping): void
    {
        $headers = collect($batch->headers ?? [])->map(fn ($header) => (string) $header)->all();
        $known = array_fill_keys($headers, true);
        foreach ($headers as $header) {
            $normalized = $this->normalizeImportColumnKey($header);
            if ($normalized !== '') {
                $known[$normalized] = true;
            }
        }

        $columns = [];
        foreach ($mapping as $key => $value) {
            if ($key === 'custom_attributes' && is_array($value)) {
                foreach ($value as $customField => $column) {
                    $columns[] = ['label' => "custom_attributes.$customField", 'column' => (string) $column];
                }
                continue;
            }

            $columns[] = ['label' => $key, 'column' => (string) $value];
        }

        foreach ($columns as $entry) {
            $column = trim($entry['column']);
            if ($column === '') {
                continue;
            }

            if (! isset($known[$column]) && ! isset($known[$this->normalizeImportColumnKey($column)])) {
                throw ValidationException::withMessages([
                    'mapping' => "Mapping for [{$entry['label']}] references unknown column [{$column}].",
                ]);
            }
        }
    }

    private function normalizeImportColumnKey(string $column): string
    {
        $column = Str::lower(trim($column));

        return preg_replace('/[^a-z0-9]+/', '', $column) ?: '';
    }
}
