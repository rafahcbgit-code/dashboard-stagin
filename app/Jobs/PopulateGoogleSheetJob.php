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

        // 5. Parse the submitted form JSON
        $parsedJson = json_decode($response->response_json, true) ?? [];

        Log::info('PopulateGoogleSheetJob: [DEBUG] raw parsed response', [
            'response_id'    => $this->metaFlowResponseId,
            'parsed_keys'    => array_keys($parsedJson),
            'parsed_values'  => $parsedJson,
            'asset_content_present' => !empty($flow->asset_content),
            'asset_content_preview' => $flow->asset_content ? substr($flow->asset_content, 0, 400) : null,
        ]);

        // Meta returns response keys based on the Footer on-click-action.payload keys
        // (e.g. "screen_0_TextArea_0") rather than the component name attributes
        // (e.g. "TextArea_de923b") that the sheet mapping was built with.
        // Build a normalized field_name → value map by reversing the Footer payload
        // expressions (${form.field_name}) found in the flow JSON.
        $submittedValues = $this->normalizeResponseValues($parsedJson, $flow);

        Log::info('PopulateGoogleSheetJob: [DEBUG] normalized submitted values', [
            'response_id'       => $this->metaFlowResponseId,
            'submitted_values'  => $submittedValues,
            'sheet_mapping'     => $flow->google_sheet_mapping,
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

            $columnValues[$sheetColumn] = match ($field) {
                '__timestamp'     => now()->toIso8601String(),
                '__contact_phone' => $contact?->phone ?? '',
                '__contact_name'  => trim(($contact?->first_name ?? '') . ' ' . ($contact?->last_name ?? '')),
                '__flow_token'    => $response->flow_token ?? '',
                default           => $submittedValues[$field] ?? '',
            };
        }

        // 7. Fetch actual column order from the sheet header row, then build the
        //    row array in that order so values land in the correct columns.
        $tab     = $flow->google_sheet_tab ?: 'Sheet1';
        $service = GoogleSheetsService::fromOrganization($this->organizationId);
        $headers = $service->getSheetHeaders($spreadsheetId, $tab);

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

    /**
     * Normalize the raw Meta response JSON into a field_name → value map.
     *
     * Meta returns keys from the Footer on-click-action.payload (e.g. "screen_0_TextArea_0"),
     * but the sheet mapping references component name attributes (e.g. "TextArea_de923b").
     * We parse the flow's Footer payload expressions "${form.field_name}" to build the
     * reverse mapping, so every field_name resolves to the correct submitted value.
     */
    private function normalizeResponseValues(array $parsedJson, MetaFlow $flow): array
    {
        // Start with direct keys — covers metadata fields and any future Meta format changes
        $values = $parsedJson;

        if (!$flow->asset_content) {
            return $values;
        }

        try {
            $json    = json_decode($flow->asset_content, true);
            $screens = $json['screens'] ?? [];
            $footersFound = 0;

            foreach ($screens as $screen) {
                $children = $screen['layout']['children'] ?? [];

                foreach ($children as $child) {
                    // Unwrap Form containers
                    $items = ($child['type'] === 'Form') ? ($child['children'] ?? []) : [$child];

                    foreach ($items as $item) {
                        if (($item['type'] ?? '') !== 'Footer') {
                            continue;
                        }

                        $footersFound++;
                        $payload = $item['on-click-action']['payload'] ?? [];

                        Log::info('PopulateGoogleSheetJob: [DEBUG] footer payload found', [
                            'screen_id'   => $screen['id'] ?? '?',
                            'payload'     => $payload,
                        ]);

                        // payload: { "screen_0_TextArea_0": "${form.TextArea_de923b}", ... }
                        foreach ($payload as $payloadKey => $expression) {
                            // Extract field name from "${form.field_name}"
                            if (preg_match('/\$\{form\.(.+?)\}/', (string) $expression, $matches)) {
                                $fieldName = $matches[1];
                                $resolved  = $parsedJson[$payloadKey] ?? null;

                                Log::info('PopulateGoogleSheetJob: [DEBUG] payload key resolved', [
                                    'payload_key'  => $payloadKey,
                                    'field_name'   => $fieldName,
                                    'value_found'  => $resolved !== null,
                                    'value'        => $resolved,
                                ]);

                                if ($resolved !== null) {
                                    $values[$fieldName] = $resolved;
                                }
                            }
                        }
                    }
                }
            }

            Log::info('PopulateGoogleSheetJob: [DEBUG] normalization complete', [
                'footers_found' => $footersFound,
                'screens_count' => count($screens),
            ]);
        } catch (\Throwable $e) {
            // Malformed flow JSON — fall back to direct key lookup
            Log::warning('PopulateGoogleSheetJob: [DEBUG] normalizeResponseValues exception', [
                'error' => $e->getMessage(),
            ]);
        }

        return $values;
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
