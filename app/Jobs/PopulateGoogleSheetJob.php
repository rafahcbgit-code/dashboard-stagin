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

        // 6. Build the ordered row using the configured field mapping
        $mapping = $flow->google_sheet_mapping ?? [];
        $row     = [];

        foreach ($mapping as $map) {
            $field       = $map['flow_field']   ?? '';
            $sheetColumn = $map['sheet_column'] ?? '';

            if (!$sheetColumn || $sheetColumn === '__skip__') {
                continue;
            }

            $row[] = match ($field) {
                '__timestamp'     => now()->toIso8601String(),
                '__contact_phone' => $contact?->phone ?? '',
                '__contact_name'  => trim(($contact?->first_name ?? '') . ' ' . ($contact?->last_name ?? '')),
                '__flow_token'    => $response->flow_token ?? '',
                default           => $parsedJson[$field] ?? '',
            };
        }

        // 7. Append the row to the sheet
        $tab = $flow->google_sheet_tab ?: 'Sheet1';

        GoogleSheetsService::fromOrganization($this->organizationId)
            ->appendRow($spreadsheetId, $tab, $row);

        Log::info('PopulateGoogleSheetJob: row appended', [
            'response_id'    => $this->metaFlowResponseId,
            'organization_id' => $this->organizationId,
            'spreadsheet_id' => $spreadsheetId,
        ]);
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
