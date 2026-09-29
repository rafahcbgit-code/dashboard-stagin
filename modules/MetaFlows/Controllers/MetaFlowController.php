<?php

namespace Modules\MetaFlows\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use App\Helpers\DateTimeHelper;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Modules\MetaFlows\Models\MetaFlow;
use Modules\MetaFlows\Models\MetaFlowResponse;
use Modules\MetaFlows\Requests\StoreMetaFlowRequest;
use Modules\MetaFlows\Services\GoogleSheetsService;
use Modules\MetaFlows\Services\MetaFlowService;

class MetaFlowController extends BaseController
{
    // Build a service instance using the current session's organization credentials
    private function service(): MetaFlowService
    {
        $organizationId = session()->get('current_organization');

        return MetaFlowService::fromOrganization($organizationId);
    }

    // List all Meta Flows for the current organization
    public function index(Request $request)
    {
        if (!CustomHelper::isModuleEnabled('Meta Flows')) {
            abort(404);
        }

        $data['title']   = __('Meta Flows');
        $data['rows']    = $this->service()->getRows($request);
        $data['filters'] = $request->all();

        return Inertia::render('MetaFlows::User/Index', $data);
    }

    // Show the editor for a single Meta Flow
    public function view(Request $request, string $uuid)
    {
        if (!CustomHelper::isModuleEnabled('Meta Flows')) {
            abort(404);
        }

        $flow = MetaFlow::where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->withCount(['responses' => fn($q) => $q->whereNotNull('response_json')])
            ->firstOrFail();

        $flowArray = $flow->toArray();
        $flowArray['updated_at_formatted'] = DateTimeHelper::formatDate(
            DateTimeHelper::convertToOrganizationTimezone($flow->updated_at)
        );

        $data['flow'] = $flowArray;

        return Inertia::render('MetaFlows::User/View', $data);
    }

    // Create a new Meta Flow on Meta's platform and redirect to the editor
    public function store(StoreMetaFlowRequest $request)
    {
        $flow = $this->service()->createFlow($request->validated());

        return redirect('/automation/meta-flows/' . $flow->uuid)->with(
            'status', [
                'type'    => 'success',
                'message' => __('Meta Flow created successfully!'),
            ]
        );
    }

    // Save updated Flow JSON to Meta and to the local database
    public function update(Request $request, string $uuid)
    {
        $request->validate([
            'asset_content' => 'required|string',
        ]);

        $flow = MetaFlow::where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->firstOrFail();

        $result = $this->service()->updateFlowAsset($flow, $request->asset_content);

        if (!$result['success']) {
            return response()->json(['message' => $result['message']], 422);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message'       => __('Flow JSON saved successfully.'),
                'asset_content' => $result['asset_content'] ?? $request->asset_content,
            ]);
        }

        return Redirect::back()->with(
            'status', [
                'type'    => 'success',
                'message' => __('Flow JSON saved successfully!'),
            ]
        );
    }

    // Publish the flow on Meta so it can be sent to users
    public function publish(string $uuid)
    {
        $flow = MetaFlow::where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->firstOrFail();

        try {
            $flow = $this->service()->publishFlow($flow);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => __('Flow published successfully!'),
            'status'  => $flow->status,
        ]);
    }

    // Deprecate the flow so it can no longer be sent to new users
    public function deprecate(string $uuid)
    {
        $flow = MetaFlow::where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->firstOrFail();

        try {
            $flow = $this->service()->deprecateFlow($flow);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => __('Flow deprecated successfully.'),
            'status'  => $flow->status,
        ]);
    }

    // Pull the latest state from Meta and refresh the local record
    public function sync(string $uuid)
    {
        $flow = MetaFlow::where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->firstOrFail();

        $flow = $this->service()->syncFlowFromMeta($flow);

        return response()->json([
            'message' => __('Flow synced from Meta.'),
            'flow'    => $flow,
        ]);
    }

    // Return a flat list of published flows for this organization — used by the chat flow picker
    public function list()
    {
        if (!CustomHelper::isModuleEnabled('Meta Flows')) {
            abort(404);
        }

        $orgId = session()->get('current_organization');

        $flows = MetaFlow::where('organization_id', $orgId)
            ->where('status', 'published')
            ->whereNotNull('meta_flow_id')
            ->whereNotNull('asset_content')
            ->orderBy('name')
            ->get(['uuid', 'name', 'categories', 'status', 'meta_flow_id', 'asset_content'])
            ->map(function ($flow) {
                $firstScreen = null;
                if ($flow->asset_content) {
                    $json = json_decode($flow->asset_content, true);
                    $firstScreen = $json['screens'][0]['id'] ?? null;
                }

                return [
                    'uuid'         => $flow->uuid,
                    'name'         => $flow->name,
                    'categories'   => $flow->categories,
                    'status'       => $flow->status,
                    'meta_flow_id' => $flow->meta_flow_id,
                    'first_screen' => $firstScreen,
                ];
            });

        return response()->json($flows);
    }

    // Pull all flows from Meta and import/update local records
    public function pull()
    {
        if (!CustomHelper::isModuleEnabled('Meta Flows')) {
            abort(404);
        }

        try {
            $result = $this->service()->pullFlowsFromMeta();

            return response()->json([
                'message'  => __("Pulled :imported new flow(s), updated :updated existing.", $result),
                'imported' => $result['imported'],
                'updated'  => $result['updated'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // List paginated responses for a specific flow
    public function responses(Request $request, string $uuid)
    {
        if (!CustomHelper::isModuleEnabled('Meta Flows')) {
            abort(404);
        }

        $orgId = session()->get('current_organization');

        $flow = MetaFlow::where('uuid', $uuid)
            ->where('organization_id', $orgId)
            ->firstOrFail();

        $responses = MetaFlowResponse::where('meta_flow_id', $flow->id)
            ->where('organization_id', $orgId)
            ->whereNotNull('response_json')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('MetaFlows::User/Responses', [
            'flow'      => $flow,
            'responses' => $responses,
        ]);
    }

    // Export all completed responses for a specific flow, independently of the current page.
    public function exportResponses(Request $request, string $uuid)
    {
        if (!CustomHelper::isModuleEnabled('Meta Flows')) {
            abort(404);
        }

        $orgId = session()->get('current_organization');

        $flow = MetaFlow::where('uuid', $uuid)
            ->where('organization_id', $orgId)
            ->firstOrFail();

        $query = MetaFlowResponse::where('meta_flow_id', $flow->id)
            ->where('organization_id', $orgId)
            ->whereNotNull('response_json')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        // The response fields are dynamic, so discover the complete header set before
        // opening the download stream. The query is then streamed a second time for rows.
        $fieldKeys = [];
        foreach ((clone $query)->cursor() as $response) {
            foreach (array_keys($this->parseResponseData($response->response_json)) as $key) {
                $fieldKeys[$key] = true;
            }
        }
        $fieldKeys = array_keys($fieldKeys);

        $filename = preg_replace('/[^A-Za-z0-9\-_]+/', '-', strtolower($flow->name ?: 'meta-flow'));
        $filename = trim($filename, '-') ?: 'meta-flow';
        $filename .= '-responses.csv';

        return response()->streamDownload(function () use ($query, $fieldKeys) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Submitted At', 'Contact', 'Flow Token', ...$fieldKeys]);

            foreach ((clone $query)->cursor() as $response) {
                $data = $this->parseResponseData($response->response_json);

                fputcsv($handle, [
                    $response->created_at?->toDateTimeString() ?? '',
                    $response->contact_id ?? '',
                    $response->flow_token ?? '',
                    ...array_map(
                        fn ($key) => $this->formatCsvValue($data[$key] ?? null),
                        $fieldKeys
                    ),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function parseResponseData(?string $raw): array
    {
        if (!$raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        $data = $decoded;

        if (is_array($decoded['response_payload'] ?? null) && array_key_exists('data', $decoded['response_payload'])) {
            $data = $decoded['response_payload']['data'];
        } elseif (array_key_exists('data', $decoded)) {
            $data = $decoded['data'];
        }

        return is_array($data) ? $data : [];
    }

    private function formatCsvValue(mixed $value): string
    {
        if ($value === null || is_scalar($value)) {
            return (string) ($value ?? '');
        }

        if (is_array($value)) {
            return implode(', ', array_map(function ($item) {
                if ($item === null || is_scalar($item)) {
                    return (string) ($item ?? '');
                }

                return json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            }, $value));
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    // Return the Google OAuth connection status for the current organization
    public function googleStatus(): JsonResponse
    {
        $orgId        = session()->get('current_organization');
        $organization = Organization::findOrFail($orgId);
        $metadata     = json_decode($organization->metadata, true) ?? [];
        $google       = $metadata['google_oauth'] ?? [];

        return response()->json([
            'connected'    => !empty($google['access_token']),
            'google_email' => $google['google_email'] ?? null,
        ]);
    }

    // Return the flow's parsed field definitions plus the built-in metadata fields
    public function getFlowFields(string $uuid): JsonResponse
    {
        $flow = MetaFlow::where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->firstOrFail();

        $metadataFields = [
            ['name' => '__timestamp',     'label' => 'Timestamp',     'type' => 'metadata', 'screen' => ''],
            ['name' => '__contact_phone', 'label' => 'Contact Phone', 'type' => 'metadata', 'screen' => ''],
            ['name' => '__contact_name',  'label' => 'Contact Name',  'type' => 'metadata', 'screen' => ''],
            ['name' => '__contact_email', 'label' => 'Contact Email', 'type' => 'metadata', 'screen' => ''],
            ['name' => '__flow_token',    'label' => 'Flow Token',    'type' => 'metadata', 'screen' => ''],
        ];

        return response()->json([
            'fields'          => $flow->extractFlowFields(),
            'metadata_fields' => $metadataFields,
        ]);
    }

    // Fetch the column headers from the linked Google Sheet.
    // Saves the URL and tab name on the flow before fetching so they are persisted.
    public function getSheetHeaders(Request $request, string $uuid): JsonResponse
    {
        $orgId = session()->get('current_organization');

        $flow = MetaFlow::where('uuid', $uuid)
            ->where('organization_id', $orgId)
            ->firstOrFail();

        $url = $request->query('google_sheet_url', $flow->google_sheet_url);
        $tab = $request->query('google_sheet_tab', $flow->google_sheet_tab ?? 'Sheet1');

        // Persist URL and tab so subsequent saves don't lose them
        $flow->update([
            'google_sheet_url' => $url,
            'google_sheet_tab' => $tab,
        ]);

        $spreadsheetId = GoogleSheetsService::extractSpreadsheetId($url ?? '');

        if (!$spreadsheetId) {
            return response()->json(['message' => 'Invalid Google Sheets URL.'], 422);
        }

        try {
            $service = GoogleSheetsService::fromOrganization($orgId);
            $headers = $service->getSheetHeaders($spreadsheetId, $tab);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to fetch sheet headers: ' . $e->getMessage()], 422);
        }

        $organization = \App\Models\Organization::findOrFail($orgId);
        $meta         = json_decode($organization->metadata, true) ?? [];
        $googleEmail  = $meta['google_oauth']['google_email'] ?? null;

        return response()->json([
            'headers'      => $headers,
            'google_email' => $googleEmail,
        ]);
    }

    // Save the Google Sheets configuration for a flow
    public function saveSheetConfig(Request $request, string $uuid): JsonResponse
    {
        $request->validate([
            'google_sheet_url'     => 'nullable|string|url',
            'google_sheet_tab'     => 'nullable|string|max:255',
            'google_sheet_mapping' => 'nullable|array',
            'google_sheet_enabled' => 'boolean',
        ]);

        $flow = MetaFlow::where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->firstOrFail();

        $mapping = collect($request->input('google_sheet_mapping', []))
            ->filter(fn($map) => is_array($map)
                && filled($map['flow_field'] ?? null)
                && filled($map['sheet_column'] ?? null)
            )
            ->map(fn($map) => [
                'flow_field'   => (string) $map['flow_field'],
                'sheet_column' => (string) $map['sheet_column'],
            ])
            ->values()
            ->all();

        $flow->update([
            'google_sheet_url'     => $request->google_sheet_url,
            'google_sheet_tab'     => $request->google_sheet_tab,
            'google_sheet_mapping' => $mapping,
            'google_sheet_enabled' => $request->boolean('google_sheet_enabled'),
        ]);

        return response()->json([
            'message'              => 'Google Sheets configuration saved.',
            'google_sheet_mapping' => $flow->fresh()->google_sheet_mapping ?? [],
        ]);
    }

    // Save post-submission settings (reply message, interactive reply, email notification)
    public function saveSubmissionSettings(Request $request, string $uuid): JsonResponse
    {
        $request->validate([
            'reply_message_enabled'             => 'sometimes|boolean',
            'reply_message_template'            => 'sometimes|nullable|string|max:5000',
            'interactive_reply_enabled'          => 'sometimes|boolean',
            'interactive_reply_config'           => 'sometimes|nullable|array',
            'interactive_reply_config.body'      => 'sometimes|nullable|string|max:5000',
            'interactive_reply_config.buttons'   => 'sometimes|array|max:3',
            'interactive_reply_config.buttons.*.id'    => 'sometimes|string|max:50',
            'interactive_reply_config.buttons.*.title'  => 'sometimes|string|max:20',
            'email_notification_enabled'        => 'sometimes|boolean',
            'email_notification_config'          => 'sometimes|nullable|array',
            'email_notification_config.recipients'         => 'sometimes|array',
            'email_notification_config.recipients.*'       => 'sometimes|email',
            'email_notification_config.subject_template'   => 'sometimes|nullable|string|max:500',
            'email_notification_config.body_template'      => 'sometimes|nullable|string|max:5000',
        ]);

        $flow = MetaFlow::where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->firstOrFail();

        $updates = $request->only([
            'reply_message_enabled',
            'reply_message_template',
            'interactive_reply_enabled',
            'interactive_reply_config',
            'email_notification_enabled',
            'email_notification_config',
        ]);

        $flow->update($updates);

        return response()->json(['message' => 'Submission settings saved.']);
    }

    // Delete the flow from Meta and soft-delete the local record
    public function destroy(string $uuid)
    {
        $flow = MetaFlow::where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->firstOrFail();

        try {
            $this->service()->deleteFlow($flow);
        } catch (\Throwable $e) {
            // Meta rejected the deletion — keep the local record intact and tell the user why
            return Redirect::back()->with(
                'status', [
                    'type'    => 'error',
                    'message' => $e->getMessage(),
                ]
            );
        }

        return Redirect::back()->with(
            'status', [
                'type'    => 'success',
                'message' => __('Meta Flow deleted successfully!'),
            ]
        );
    }
}
