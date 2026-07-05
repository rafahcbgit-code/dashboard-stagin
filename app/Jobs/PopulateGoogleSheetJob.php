<?php

namespace App\Jobs;

use App\Models\Contact;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\MetaFlows\Models\MetaFlow;
use Modules\MetaFlows\Models\MetaFlowResponse;
use Modules\MetaFlows\Services\GoogleSheetsService;
use Modules\MetaFlows\Services\MetaFlowResponseNormalizer;

class PopulateGoogleSheetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(
        private readonly int $metaFlowResponseId,
        private readonly int $organizationId,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        // 1. Load the response record — bail if missing or has no submitted data
        $response = MetaFlowResponse::find($this->metaFlowResponseId);

        if (!$response || !$response->response_json) {
            Log::warning('PopulateGoogleSheetJob: response not found or empty', [
                'response_id' => $this->metaFlowResponseId,
            ]);

            return;
        }

        // 2. Load the flow — bail if Google Sheets integration is not configured
        $flow = MetaFlow::find($response->meta_flow_id);

        if (!$flow || !$flow->google_sheet_enabled || !$flow->google_sheet_url) {
            return;
        }

        // 3. Extract the spreadsheet ID from the URL
        $spreadsheetId = GoogleSheetsService::extractSpreadsheetId($flow->google_sheet_url);

        if (!$spreadsheetId) {
            Log::warning('PopulateGoogleSheetJob: could not extract spreadsheet ID', [
                'response_id' => $this->metaFlowResponseId,
                'url'         => $flow->google_sheet_url,
            ]);

            return;
        }

        // 4. Load the contact for metadata fields
        $contact = Contact::find($response->contact_id);

        // 5. Normalize response values using the shared normalizer
        $submittedValues = MetaFlowResponseNormalizer::normalize($response, $flow, $contact);

        Log::info('PopulateGoogleSheetJob: normalized submitted values', [
            'response_id'      => $this->metaFlowResponseId,
            'submitted_values' => $submittedValues,
            'sheet_mapping'    => $flow->google_sheet_mapping,
        ]);

        // 6. Build a column-name → value dictionary from the mapping
        $mapping      = $flow->google_sheet_mapping ?? [];
        $columnValues = [];

        foreach ($mapping as $map) {
            $field       = $map['flow_field']   ?? '';
            $sheetColumn = $map['sheet_column'] ?? '';

            if (!$sheetColumn || $sheetColumn === '__skip__') {
                continue;
            }

            $columnValues[$sheetColumn] = $submittedValues[$field] ?? '';
        }

        // 7. Fetch actual column order from the sheet header row, then build the
        //    row array in that order so values land in the correct columns.
        $tab     = $flow->google_sheet_tab ?: 'Sheet1';
        $service = GoogleSheetsService::fromOrganization($this->organizationId);
        $headers = $service->getSheetHeaders($spreadsheetId, $tab);

        if (empty($mapping)) {
            $mapping = $this->buildAutomaticMapping($flow, $headers);
            $columnValues = $this->buildColumnValues($mapping, $submittedValues);

            Log::info('PopulateGoogleSheetJob: using automatic sheet mapping', [
                'response_id' => $this->metaFlowResponseId,
                'mapping'     => $mapping,
            ]);
        }

        $row = array_map(fn($header) => $columnValues[$header] ?? '', $headers);

        Log::info('PopulateGoogleSheetJob: [DEBUG] final row before append', [
            'response_id'   => $this->metaFlowResponseId,
            'sheet_headers' => $headers,
            'column_values' => $columnValues,
            'final_row'     => $row,
        ]);

        $service->appendRow($spreadsheetId, $tab, $row);

        Log::info('PopulateGoogleSheetJob: row appended', [
            'response_id'    => $this->metaFlowResponseId,
            'organization_id' => $this->organizationId,
            'spreadsheet_id' => $spreadsheetId,
        ]);
    }

    private function buildColumnValues(array $mapping, array $submittedValues): array
    {
        $columnValues = [];

        foreach ($mapping as $map) {
            $field       = $map['flow_field']   ?? '';
            $sheetColumn = $map['sheet_column'] ?? '';

            if (!$sheetColumn || $sheetColumn === '__skip__') {
                continue;
            }

            $columnValues[$sheetColumn] = $submittedValues[$field] ?? '';
        }

        return $columnValues;
    }

    private function buildAutomaticMapping(MetaFlow $flow, array $headers): array
    {
        $headersByKey = [];

        foreach ($headers as $header) {
            $headersByKey[$this->normalizeMappingKey($header)] = $header;
        }

        $mapping = [];

        foreach ($flow->extractFlowFields() as $field) {
            $fieldName = $field['name'] ?? '';
            $label     = $field['label'] ?? '';

            foreach ([$fieldName, $label] as $candidate) {
                $header = $headersByKey[$this->normalizeMappingKey($candidate)] ?? null;

                if ($fieldName && $header) {
                    $mapping[] = [
                        'flow_field'   => $fieldName,
                        'sheet_column' => $header,
                    ];

                    break;
                }
            }
        }

        return $mapping;
    }

    private function normalizeMappingKey(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    // Log permanent failures after all retries are exhausted
    public function failed(\Throwable $exception): void
    {
        Log::error('PopulateGoogleSheetJob: permanent failure', [
            'response_id'    => $this->metaFlowResponseId,
            'organization_id' => $this->organizationId,
            'error'          => $exception->getMessage(),
        ]);
    }
}
