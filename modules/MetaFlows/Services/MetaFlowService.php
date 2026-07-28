<?php

namespace Modules\MetaFlows\Services;

use App\Jobs\PopulateGoogleSheetJob;
use App\Jobs\SendMetaFlowReplyMessageJob;
use App\Jobs\SendMetaFlowInteractiveReplyJob;
use App\Jobs\SendMetaFlowEmailNotificationJob;
use App\Models\Organization;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\MetaFlows\Models\MetaFlow;
use Modules\MetaFlows\Models\MetaFlowResponse;
use Modules\MetaFlows\Resources\MetaFlowResource;

class MetaFlowService
{
    /** Flow message version sent to Meta — bump here when the API spec changes */
    const FLOW_MESSAGE_VERSION = '3';

    private string $accessToken;
    private string $apiVersion;
    private string $wabaId;
    private string $phoneNumberId;
    private int $organizationId;

    // Meta Graph API base URL
    private string $baseUrl = 'https://graph.facebook.com';

    public function __construct(string $accessToken, string $apiVersion, string $wabaId, string $phoneNumberId, int $organizationId)
    {
        $this->accessToken    = $accessToken;
        $this->apiVersion     = $apiVersion;
        $this->wabaId         = $wabaId;
        $this->phoneNumberId  = $phoneNumberId;
        $this->organizationId = $organizationId;
    }

    // Build credentials array from organization metadata and return a ready instance
    public static function fromOrganization(int $organizationId): self
    {
        $organization = Organization::findOrFail($organizationId);
        $config = json_decode($organization->metadata, true) ?? [];
        $whatsapp = $config['whatsapp'] ?? [];

        return new self(
            $whatsapp['access_token'] ?? '',
            config('graph.api_version'),
            $whatsapp['waba_id'] ?? '',
            $whatsapp['phone_number_id'] ?? '',
            $organizationId
        );
    }

    // Return a paginated list of flows for the organization, optionally filtered by name
    public function getRows(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $searchTerm = $request->query('search');
        $model = new MetaFlow();

        return MetaFlowResource::collection(
            $model->listAll($this->organizationId, $searchTerm)
        );
    }

    // Create a new flow on Meta's platform and save it locally
    public function createFlow(array $data): MetaFlow
    {
        $payload = [
            'name'       => $data['name'],
            'categories' => $data['categories'] ?? ['OTHER'],
        ];

        if (!empty($data['endpoint_uri'])) {
            $payload['endpoint_uri'] = $data['endpoint_uri'];
        }

        $url      = "{$this->baseUrl}/{$this->apiVersion}/{$this->wabaId}/flows";
        $response = $this->makeRequest('POST', $url, $payload);

        // Save locally regardless of the Meta result so we always have a record
        $flow = MetaFlow::create([
            'organization_id' => $this->organizationId,
            'meta_flow_id'    => $response->data->id ?? null,
            'name'            => $data['name'],
            'categories'      => $data['categories'] ?? ['OTHER'],
            'endpoint_uri'    => $data['endpoint_uri'] ?? null,
            'status'          => 'draft',
        ]);

        Log::info('MetaFlows: flow created', [
            'local_id'     => $flow->id,
            'meta_flow_id' => $flow->meta_flow_id,
        ]);

        // Upload the template Flow JSON immediately after creation so the flow
        // is ready to edit (asset_content is populated on the local record).
        if (!empty($data['template_json']) && $flow->meta_flow_id) {
            $result = $this->updateFlowAsset($flow, $data['template_json']);
            if (!($result['success'] ?? false)) {
                Log::warning('MetaFlows: template asset upload failed on creation', [
                    'local_id'     => $flow->id,
                    'meta_flow_id' => $flow->meta_flow_id,
                    'message'      => $result['message'] ?? 'unknown',
                ]);
            }
        }

        return $flow;
    }

    // Upload or replace the Flow JSON asset on Meta and update the local record
    public function updateFlowAsset(MetaFlow $flow, string $jsonContent): array
    {
        if (!$flow->meta_flow_id) {
            return ['success' => false, 'message' => 'This flow has no Meta flow ID. Create it on Meta first.'];
        }

        $jsonContent = $this->ensureCompleteActionPayload($jsonContent);

        $url = "{$this->baseUrl}/{$this->apiVersion}/{$flow->meta_flow_id}/assets";

        Log::info('MetaFlows: uploading flow asset — request', [
            'meta_flow_id' => $flow->meta_flow_id,
            'url'          => $url,
            'json_length'  => strlen($jsonContent),
            'json_content' => $jsonContent,
        ]);

        // Meta expects the Flow JSON as a multipart file upload
        $response = $this->makeMultipartRequest($url, $jsonContent, 'flow.json');

        Log::info('MetaFlows: uploading flow asset — response', [
            'meta_flow_id'      => $flow->meta_flow_id,
            'success'           => $response->success,
            'message'           => $response->message ?? null,
            'data'              => json_decode(json_encode($response->data ?? null), true),
        ]);

        $validationErrors = $this->extractAssetValidationErrors($response);

        // The assets endpoint can return HTTP 200 and success=true while the
        // uploaded Flow JSON still contains validation errors. Such an asset
        // cannot be published and must not be treated as a successful save.
        if (!isset($response->success) || !$response->success || !empty($validationErrors)) {
            $message = !empty($validationErrors)
                ? $this->formatAssetValidationErrors($validationErrors)
                : ($response->message ?? 'Failed to update flow asset on Meta.');

            Log::warning('MetaFlows: asset upload failed', [
                'meta_flow_id'      => $flow->meta_flow_id,
                'message'           => $message,
                'validation_errors' => $validationErrors,
            ]);

            return ['success' => false, 'message' => $message];
        }

        // Persist the updated JSON locally
        $flow->update(['asset_content' => $jsonContent]);

        Log::info('MetaFlows: flow asset updated', ['meta_flow_id' => $flow->meta_flow_id]);

        return ['success' => true, 'asset_content' => $jsonContent];
    }

    private function ensureCompleteActionPayload(string $jsonContent): string
    {
        $flowJson = json_decode($jsonContent, true);

        if (!is_array($flowJson)) {
            return $jsonContent;
        }

        $inputTypes = ['TextInput', 'TextArea', 'Dropdown', 'CheckboxGroup', 'RadioButtonsGroup', 'DatePicker'];
        $changed = $this->ensureRequiredIdentifiers($flowJson, $inputTypes);
        $flowDataFields = $this->extractNavigatePayloadFields($flowJson);
        $changed = $this->ensureNavigateTargetDataModels($flowJson) || $changed;

        foreach ($flowJson['screens'] ?? [] as $screenIndex => $screen) {
            foreach ($screen['layout']['children'] ?? [] as $childIndex => $child) {
                $formPath = "screens.{$screenIndex}.layout.children.{$childIndex}";
                $changed = $this->ensureCompleteActionPayloadForForm($flowJson, $formPath, $inputTypes, $flowDataFields) || $changed;
            }
        }

        if (!$changed) {
            return $jsonContent;
        }

        Log::info('MetaFlows: Flow JSON normalized before asset upload');

        return json_encode($flowJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Fill only blank identifiers required by Meta's Flow JSON schema.
     *
     * Arabic and other non-Latin labels previously produced an empty value in
     * the browser's ASCII-only slugifier. Index-based fallbacks are stable,
     * schema-safe, and leave every existing non-blank identifier untouched.
     */
    private function ensureRequiredIdentifiers(array &$flowJson, array $inputTypes): bool
    {
        $changed = false;
        $fieldSequence = 0;

        if (!is_array($flowJson['screens'] ?? null)) {
            return false;
        }

        foreach ($flowJson['screens'] as $screenIndex => &$screen) {
            if (!is_array($screen['layout']['children'] ?? null)) {
                continue;
            }

            foreach ($screen['layout']['children'] as $layoutIndex => &$layoutChild) {
                if (($layoutChild['type'] ?? null) !== 'Form') {
                    continue;
                }

                if (trim((string) ($layoutChild['name'] ?? '')) === '') {
                    $layoutChild['name'] = 'screen_' . ($screenIndex + 1) . '_form_' . ($layoutIndex + 1);
                    $changed = true;
                }

                if (!is_array($layoutChild['children'] ?? null)) {
                    continue;
                }

                $usedFieldNames = array_values(array_filter(array_map(
                    static fn ($component): string => is_array($component)
                        ? trim((string) ($component['name'] ?? ''))
                        : '',
                    $layoutChild['children']
                )));

                foreach ($layoutChild['children'] as &$component) {
                    if (!in_array($component['type'] ?? '', $inputTypes, true)) {
                        continue;
                    }

                    $fieldSequence++;
                    $name = trim((string) ($component['name'] ?? ''));

                    if ($name === '') {
                        $component['name'] = $this->nextAvailableIdentifier('field_' . $fieldSequence, $usedFieldNames);
                        $changed = true;
                    }

                    $usedFieldNames[] = $component['name'];

                    if (!in_array($component['type'] ?? '', ['Dropdown', 'CheckboxGroup', 'RadioButtonsGroup'], true)) {
                        continue;
                    }

                    if (!is_array($component['data-source'] ?? null)) {
                        continue;
                    }

                    $usedOptionIds = array_values(array_filter(array_map(
                        static fn ($option): string => is_array($option)
                            ? trim((string) ($option['id'] ?? ''))
                            : '',
                        $component['data-source']
                    )));

                    foreach ($component['data-source'] as $optionIndex => &$option) {
                        $optionId = trim((string) ($option['id'] ?? ''));

                        if ($optionId === '') {
                            $option['id'] = $this->nextAvailableIdentifier('option_' . ($optionIndex + 1), $usedOptionIds);
                            $changed = true;
                        }

                        $usedOptionIds[] = $option['id'];
                    }
                    unset($option);
                }
                unset($component);
            }
            unset($layoutChild);
        }
        unset($screen);

        return $changed;
    }

    private function nextAvailableIdentifier(string $base, array $used): string
    {
        $candidate = $base;
        $suffix = 2;

        while (in_array($candidate, $used, true)) {
            $candidate = $base . '_' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function extractAssetValidationErrors(\stdClass $response): array
    {
        $errors = $response->data->validation_errors ?? [];

        return is_array($errors) ? $errors : [];
    }

    private function formatAssetValidationErrors(array $errors): string
    {
        $messages = [];

        foreach ($errors as $error) {
            $message = trim((string) ($error->message ?? 'Invalid Flow JSON.'));
            if ($message !== '' && !in_array($message, $messages, true)) {
                $messages[] = $message;
            }
        }

        return 'Meta rejected the Flow JSON: ' . implode(' ', $messages);
    }

    private function ensureCompleteActionPayloadForForm(array &$flowJson, string $formPath, array $inputTypes, array $flowDataFields): bool
    {
        $form = data_get($flowJson, $formPath);

        if (($form['type'] ?? null) !== 'Form' || !is_array($form['children'] ?? null)) {
            return false;
        }

        $formFields = [];

        foreach ($form['children'] as $child) {
            if (in_array($child['type'] ?? '', $inputTypes, true) && !empty($child['name'])) {
                $formFields[] = $child['name'];
            }
        }

        if (empty($formFields)) {
            return false;
        }

        $changed = false;

        foreach ($form['children'] as $index => $child) {
            if (
                ($child['type'] ?? null) !== 'Footer'
                || (($child['on-click-action']['name'] ?? null) !== 'complete')
            ) {
                continue;
            }

            $payload = $child['on-click-action']['payload'] ?? [];
            $payload = $this->normalizePayloadObject($payload);

            foreach ($formFields as $fieldName) {
                if (!array_key_exists($fieldName, $payload)) {
                    $payload[$fieldName] = '${form.' . $fieldName . '}';
                    $changed = true;
                }
            }

            foreach ($flowDataFields as $fieldName) {
                if (!array_key_exists($fieldName, $payload)) {
                    $payload[$fieldName] = '${data.' . $fieldName . '}';
                    $changed = true;
                }
            }

            if ($payload !== ($child['on-click-action']['payload'] ?? [])) {
                data_set($flowJson, "{$formPath}.children.{$index}.on-click-action.payload", $payload);
                $changed = true;
            }
        }

        return $changed;
    }

    private function extractNavigatePayloadFields(array $flowJson): array
    {
        $fields = [];

        foreach ($flowJson['screens'] ?? [] as $screen) {
            foreach ($screen['layout']['children'] ?? [] as $child) {
                if (($child['type'] ?? null) !== 'Form' || !is_array($child['children'] ?? null)) {
                    continue;
                }

                foreach ($child['children'] as $item) {
                    if (($item['type'] ?? null) !== 'Footer') {
                        continue;
                    }

                    if (($item['on-click-action']['name'] ?? null) !== 'navigate') {
                        continue;
                    }

                    $payload = $this->normalizePayloadObject($item['on-click-action']['payload'] ?? []);

                    foreach (array_keys($payload) as $fieldName) {
                        $fields[$fieldName] = true;
                    }
                }
            }
        }

        return array_keys($fields);
    }

    private function ensureNavigateTargetDataModels(array &$flowJson): bool
    {
        $changed = false;
        $screenIndexesById = [];

        foreach ($flowJson['screens'] ?? [] as $index => $screen) {
            if (!empty($screen['id'])) {
                $screenIndexesById[$screen['id']] = $index;
            }
        }

        foreach ($flowJson['screens'] ?? [] as $screen) {
            foreach ($screen['layout']['children'] ?? [] as $child) {
                if (($child['type'] ?? null) !== 'Form' || !is_array($child['children'] ?? null)) {
                    continue;
                }

                foreach ($child['children'] as $item) {
                    if (
                        ($item['type'] ?? null) !== 'Footer'
                        || (($item['on-click-action']['name'] ?? null) !== 'navigate')
                    ) {
                        continue;
                    }

                    $targetScreen = $item['on-click-action']['next']['name'] ?? null;
                    $targetIndex = $targetScreen !== null ? ($screenIndexesById[$targetScreen] ?? null) : null;

                    if ($targetIndex === null) {
                        continue;
                    }

                    $payload = $this->normalizePayloadObject($item['on-click-action']['payload'] ?? []);

                    foreach (array_keys($payload) as $fieldName) {
                        $dataPath = "screens.{$targetIndex}.data.{$fieldName}";

                        if (data_get($flowJson, $dataPath) === null) {
                            data_set($flowJson, $dataPath, [
                                'type'        => 'string',
                                '__example__' => 'example',
                            ]);
                            $changed = true;
                        }
                    }
                }
            }
        }

        return $changed;
    }

    private function normalizePayloadObject(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        return array_filter(
            $payload,
            fn($key) => is_string($key),
            ARRAY_FILTER_USE_KEY
        );
    }

    // Fetch fresh data from Meta and update the local record (status, preview_url, asset)
    public function syncFlowFromMeta(MetaFlow $flow): MetaFlow
    {
        if (!$flow->meta_flow_id) {
            return $flow;
        }

        // Fetch flow metadata (includes status and preview_url)
        $url      = "{$this->baseUrl}/{$this->apiVersion}/{$flow->meta_flow_id}";
        $fields   = '?fields=id,name,status,categories,preview,endpoint_uri';
        $response = $this->makeRequest('GET', $url . $fields);

        if (isset($response->data)) {
            $updates = [
                'status'      => strtolower($response->data->status ?? $flow->status),
                'preview_url' => $response->data->preview->preview_url ?? $flow->preview_url,
                'categories'  => (array) ($response->data->categories ?? $flow->categories),
            ];

            $flow->update($updates);
        }

        // Fetch the asset content (the Flow JSON)
        $assetUrl      = "{$this->baseUrl}/{$this->apiVersion}/{$flow->meta_flow_id}/assets";
        $assetResponse = $this->makeRequest('GET', $assetUrl);

        if (isset($assetResponse->data->data) && is_array($assetResponse->data->data)) {
            foreach ($assetResponse->data->data as $asset) {
                if (($asset->asset_type ?? '') === 'FLOW_JSON') {
                    // The asset download URL needs an authenticated fetch
                    $jsonContent = $this->fetchAssetContent($asset->download_url ?? '');
                    if ($jsonContent) {
                        $flow->update(['asset_content' => $jsonContent]);
                    }
                    break;
                }
            }
        }

        Log::info('MetaFlows: flow synced from Meta', ['meta_flow_id' => $flow->meta_flow_id]);

        return $flow->fresh();
    }

    // Publish the flow on Meta and update its local status.
    // Throws if Meta rejects the request (e.g. invalid flow JSON, missing screens).
    public function publishFlow(MetaFlow $flow): MetaFlow
    {
        if (!$flow->meta_flow_id) {
            return $flow;
        }

        $url = "{$this->baseUrl}/{$this->apiVersion}/{$flow->meta_flow_id}/publish";

        Log::info('MetaFlows: publishing flow — request', [
            'meta_flow_id' => $flow->meta_flow_id,
            'url'          => $url,
        ]);

        $response = $this->makeRequest('POST', $url);

        Log::info('MetaFlows: publishing flow — response', [
            'meta_flow_id' => $flow->meta_flow_id,
            'success'      => $response->success,
            'message'      => $response->message ?? null,
            'data'         => json_decode(json_encode($response->data ?? null), true),
        ]);

        if (!$response->success) {
            throw new \RuntimeException($this->formatMetaErrorMessage($response, 'Failed to publish flow on Meta.'));
        }

        $flow->update(['status' => 'published']);

        Log::info('MetaFlows: flow published successfully', ['meta_flow_id' => $flow->meta_flow_id]);

        return $flow->fresh();
    }

    // Deprecate the flow on Meta and update its local status.
    // Throws if Meta rejects the request — the local record is NOT updated in that case.
    public function deprecateFlow(MetaFlow $flow): MetaFlow
    {
        if (!$flow->meta_flow_id) {
            return $flow;
        }

        $url      = "{$this->baseUrl}/{$this->apiVersion}/{$flow->meta_flow_id}/deprecate";
        $response = $this->makeRequest('POST', $url);

        if (!$response->success) {
            throw new \RuntimeException($response->message ?? 'Failed to deprecate flow on Meta.');
        }

        $flow->update(['status' => 'deprecated']);

        Log::info('MetaFlows: flow deprecated', ['meta_flow_id' => $flow->meta_flow_id]);

        return $flow->fresh();
    }

    // Fetch all flows from Meta for this WABA and import/update local records
    public function pullFlowsFromMeta(): array
    {
        $url    = "{$this->baseUrl}/{$this->apiVersion}/{$this->wabaId}/flows";
        $fields = '?fields=id,name,status,categories,preview,endpoint_uri';

        $response = $this->makeRequest('GET', $url . $fields);

        if (!$response->success || !isset($response->data->data)) {
            throw new \RuntimeException($response->message ?? 'Failed to fetch flows from Meta.');
        }

        $imported = 0;
        $updated  = 0;

        foreach ($response->data->data as $metaFlow) {
            $existing = MetaFlow::where('organization_id', $this->organizationId)
                ->where('meta_flow_id', $metaFlow->id)
                ->first();

            $attributes = [
                'name'        => $metaFlow->name,
                'categories'  => (array) ($metaFlow->categories ?? []),
                'status'      => strtolower($metaFlow->status ?? 'draft'),
                'preview_url' => $metaFlow->preview->preview_url ?? null,
            ];

            if ($existing) {
                $existing->update($attributes);
                $updated++;
            } else {
                MetaFlow::create(array_merge($attributes, [
                    'organization_id' => $this->organizationId,
                    'meta_flow_id'    => $metaFlow->id,
                ]));
                $imported++;
            }
        }

        Log::info('MetaFlows: pulled flows from Meta', [
            'organization_id' => $this->organizationId,
            'imported'        => $imported,
            'updated'         => $updated,
        ]);

        return ['imported' => $imported, 'updated' => $updated];
    }

    // Delete the flow on Meta and soft-delete the local record.
    // Throws a RuntimeException if Meta rejects the deletion (e.g. flow is still published).
    public function deleteFlow(MetaFlow $flow): bool
    {
        if ($flow->meta_flow_id) {
            $url = "{$this->baseUrl}/{$this->apiVersion}/{$flow->meta_flow_id}";

            Log::info('MetaFlows: deleting flow — request', [
                'local_id'     => $flow->id,
                'meta_flow_id' => $flow->meta_flow_id,
                'status'       => $flow->status,
                'url'          => $url,
            ]);

            $response = $this->makeRequest('DELETE', $url);

            Log::info('MetaFlows: deleting flow — response', [
                'local_id'     => $flow->id,
                'meta_flow_id' => $flow->meta_flow_id,
                'success'      => $response->success,
                'message'      => $response->message ?? null,
                'data'         => json_decode(json_encode($response->data ?? null), true),
            ]);

            // Only proceed with local deletion if Meta confirmed the delete
            if (!$response->success) {
                throw new \RuntimeException($response->message ?? 'Failed to delete flow on Meta.');
            }
        }

        $flow->delete();

        Log::info('MetaFlows: flow deleted locally', ['local_id' => $flow->id]);

        return true;
    }

    // Send a Meta Flow to a contact as an interactive WhatsApp message
    public function sendFlowToContact(MetaFlow $flow, \App\Models\Contact $contact, array $options = []): array
    {
        if (!$flow->meta_flow_id) {
            return ['success' => false, 'message' => 'Flow has no Meta flow ID.'];
        }

        // Resolve the first screen ID from the flow's stored JSON so we never hardcode it.
        // Meta rejects the request if the screen name doesn't match what exists in the flow.
        $firstScreen = $this->resolveFirstScreenId($flow, $options['screen'] ?? null);

        // Generate a unique token to match the response back to this send event
        $flowToken = Str::uuid()->toString();

        $url = "{$this->baseUrl}/{$this->apiVersion}/{$this->phoneNumberId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $contact->phone,
            'type'              => 'interactive',
            'interactive'       => [
                'type' => 'flow',
                'body' => ['text' => $options['body'] ?? 'Please complete the form.'],
                'action' => [
                    'name'       => 'flow',
                    'parameters' => [
                        'flow_message_version' => self::FLOW_MESSAGE_VERSION,
                        'flow_token'           => $flowToken,
                        'flow_id'              => $flow->meta_flow_id,
                        'flow_cta'             => $options['cta'] ?? 'Open',
                        'flow_action'          => 'navigate',
                        // flow_action_payload.data must be a JSON object {} — never an array [].
                        // Omit the data key entirely when empty; Meta treats it as optional.
                        'flow_action_payload'  => array_merge(
                            ['screen' => $firstScreen],
                            !empty($options['screen_data']) ? ['data' => $options['screen_data']] : []
                        ),
                    ],
                ],
            ],
        ];

        if (!empty($options['header'])) {
            $payload['interactive']['header'] = ['type' => 'text', 'text' => $options['header']];
        }

        if (!empty($options['footer'])) {
            $payload['interactive']['footer'] = ['text' => $options['footer']];
        }

        // Log the full request payload before sending so failures are easy to diagnose
        Log::info('MetaFlows: sending flow to contact', [
            'url'          => $url,
            'meta_flow_id' => $flow->meta_flow_id,
            'contact_id'   => $contact->id,
            'flow_token'   => $flowToken,
            'payload'      => $payload,
        ]);

        $response = $this->makeRequest('POST', $url, $payload);

        if (!($response->success ?? false)) {
            Log::error('MetaFlows: failed to send flow to contact', [
                'meta_flow_id'    => $flow->meta_flow_id,
                'contact_id'      => $contact->id,
                'flow_token'      => $flowToken,
                'error_message'   => $response->message ?? 'Unknown error',
                'response_body'   => json_decode(json_encode($response->data ?? null), true),
            ]);

            return [
                'success'    => false,
                'message'    => $response->message ?? 'Failed to send flow via Meta API.',
                'flow_token' => $flowToken,
                'data'       => $response->data ?? null,
            ];
        }

        Log::info('MetaFlows: flow sent successfully', [
            'meta_flow_id' => $flow->meta_flow_id,
            'contact_id'   => $contact->id,
            'flow_token'   => $flowToken,
            'wam_id'       => $response->data->messages[0]->id ?? null,
        ]);

        // Pre-create a stub response record so the webhook handler can find it
        // by token when the user submits the flow (response_json filled on webhook)
        MetaFlowResponse::create([
            'organization_id' => $this->organizationId,
            'meta_flow_id'    => $flow->id,
            'contact_id'      => $contact->id,
            'flow_token'      => $flowToken,
            'response_json'   => null,
        ]);

        return [
            'success'    => true,
            'flow_token' => $flowToken,
            'data'       => $response->data ?? null,
        ];
    }

    // Process an nfm_reply webhook and update the pre-created response record
    public function handleFlowResponse(array $message, int $contactId): ?MetaFlowResponse
    {
        $interactive = $message['interactive'] ?? [];

        if (($interactive['type'] ?? '') !== 'nfm_reply') {
            return null;
        }

        $nfmReply     = $interactive['nfm_reply'] ?? [];
        $responseJson = $nfmReply['response_json'] ?? null;
        $flowToken    = null;

        // The flow token is embedded in the response JSON
        if ($responseJson) {
            $parsed    = json_decode($responseJson, true);
            $flowToken = $parsed['flow_token'] ?? null;
        }

        if (!$flowToken) {
            Log::warning('MetaFlows: nfm_reply received without a flow_token — cannot store response', [
                'organization_id' => $this->organizationId,
                'contact_id'      => $contactId,
            ]);

            return null;
        }

        // Find the stub record created when the flow was sent (response_json is null there)
        $flowResponse = MetaFlowResponse::where('flow_token', $flowToken)
            ->where('organization_id', $this->organizationId)
            ->first();

        if (!$flowResponse) {
            Log::warning('MetaFlows: nfm_reply arrived with unknown token — no pre-created record found', [
                'organization_id' => $this->organizationId,
                'flow_token'      => $flowToken,
                'contact_id'      => $contactId,
            ]);

            return null;
        }

        // Fill in the submitted data on the existing stub record
        $flowResponse->update([
            'contact_id'    => $contactId,
            'response_json' => $responseJson,
        ]);

        Log::info('MetaFlows: nfm_reply response stored', [
            'response_id'  => $flowResponse->id,
            'meta_flow_id' => $flowResponse->meta_flow_id,
            'contact_id'   => $contactId,
        ]);

        // Dispatch Google Sheets population job if the flow has it enabled.
        // Wrapped in try/catch so a misconfiguration never blocks the webhook response.
        try {
            $flow = MetaFlow::find($flowResponse->meta_flow_id);

            if ($flow && $flow->google_sheet_enabled && $flow->google_sheet_url) {
                PopulateGoogleSheetJob::dispatch($flowResponse->id, $this->organizationId);
            }
        } catch (\Throwable $e) {
            Log::warning('MetaFlows: failed to dispatch PopulateGoogleSheetJob', [
                'response_id' => $flowResponse->id,
                'error'       => $e->getMessage(),
            ]);
        }

        // Reload flow to pick up any fresh post-submission settings
        $flow = $flow ?? MetaFlow::find($flowResponse->meta_flow_id);

        // Dispatch reply message job if enabled
        try {
            if ($flow && $flow->reply_message_enabled && $flow->reply_message_template) {
                SendMetaFlowReplyMessageJob::dispatch($flowResponse->id, $this->organizationId);
            }
        } catch (\Throwable $e) {
            Log::warning('MetaFlows: failed to dispatch SendMetaFlowReplyMessageJob', [
                'response_id' => $flowResponse->id,
                'error'       => $e->getMessage(),
            ]);
        }

        // Dispatch interactive reply job if enabled
        try {
            if ($flow && $flow->interactive_reply_enabled && $flow->interactive_reply_config) {
                SendMetaFlowInteractiveReplyJob::dispatch($flowResponse->id, $this->organizationId);
            }
        } catch (\Throwable $e) {
            Log::warning('MetaFlows: failed to dispatch SendMetaFlowInteractiveReplyJob', [
                'response_id' => $flowResponse->id,
                'error'       => $e->getMessage(),
            ]);
        }

        // Dispatch email notification job if enabled
        try {
            if ($flow && $flow->email_notification_enabled && $flow->email_notification_config) {
                SendMetaFlowEmailNotificationJob::dispatch($flowResponse->id, $this->organizationId);
            }
        } catch (\Throwable $e) {
            Log::warning('MetaFlows: failed to dispatch SendMetaFlowEmailNotificationJob', [
                'response_id' => $flowResponse->id,
                'error'       => $e->getMessage(),
            ]);
        }

        return $flowResponse->fresh();
    }

    // Determine the first screen ID to navigate to when sending a flow.
    // If the caller supplied an explicit screen name use that, otherwise read
    // the first screen id from the flow's stored asset JSON. This prevents the
    // "#131009 Specified screen X is not allowed as first screen" error that
    // occurs when the screen name doesn't match what Meta has on file.
    private function resolveFirstScreenId(MetaFlow $flow, ?string $override): string
    {
        if ($override) {
            return $override;
        }

        if ($flow->asset_content) {
            $json = json_decode($flow->asset_content, true);
            $firstId = $json['screens'][0]['id'] ?? null;

            if ($firstId) {
                Log::info('MetaFlows: resolved first screen from asset_content', [
                    'meta_flow_id' => $flow->meta_flow_id,
                    'screen'       => $firstId,
                ]);

                return $firstId;
            }
        }

        // Nothing in local storage — fall back to a common default and log a warning
        Log::warning('MetaFlows: could not resolve first screen from asset_content, falling back to INTRO', [
            'meta_flow_id' => $flow->meta_flow_id,
        ]);

        return 'INTRO';
    }

    // Send a JSON or standard HTTP request to the Meta Graph API.
    // ConnectException (network timeout / unreachable) is retried up to 3 times
    // with linear back-off (1 s, 2 s) before the error is propagated.
    private function makeRequest(string $method, string $url, array $data = []): \stdClass
    {
        $client      = new Client();
        $maxAttempts = 3;
        $lastConnectException = null;

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type'  => 'application/json',
            ],
        ];

        if (in_array($method, ['POST', 'PUT', 'DELETE']) && !empty($data)) {
            $options['json'] = $data;
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $client->request($method, $url, $options);

                $responseObject          = new \stdClass();
                $responseObject->success = true;
                $responseObject->data    = json_decode($response->getBody()->getContents());

                return $responseObject;
            } catch (ConnectException $e) {
                $lastConnectException = $e;

                Log::warning('MetaFlows: ConnectException on attempt ' . $attempt, [
                    'url'     => $url,
                    'attempt' => $attempt,
                    'error'   => $e->getMessage(),
                ]);

                if ($attempt < $maxAttempts) {
                    sleep($attempt); // 1 s after attempt 1, 2 s after attempt 2
                    continue;
                }
            } catch (GuzzleException $e) {
                $body = $e->getResponse()?->getBody()?->getContents();

                $responseObject          = new \stdClass();
                $responseObject->success = false;
                $responseObject->data    = json_decode($body ?? '{}');
                $responseObject->message = $responseObject->data->error->message ?? $e->getMessage();

                return $responseObject;
            }
        }

        // All ConnectException retries exhausted
        $responseObject                       = new \stdClass();
        $responseObject->success              = false;
        $responseObject->data                 = new \stdClass();
        $responseObject->data->error          = new \stdClass();
        $responseObject->data->error->message = $lastConnectException->getMessage();
        $responseObject->message              = $lastConnectException->getMessage();

        return $responseObject;
    }

    // Upload the Flow JSON to Meta as a multipart file (required by the assets endpoint)
    private function makeMultipartRequest(string $url, string $jsonContent, string $filename): \stdClass
    {
        $client = new Client();
        $responseObject = new \stdClass();

        try {
            $response = $client->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                ],
                'multipart' => [
                    [
                        'name'     => 'file',
                        'contents' => $jsonContent,
                        'filename' => $filename,
                        'headers'  => ['Content-Type' => 'application/json'],
                    ],
                    [
                        'name'     => 'name',
                        'contents' => $filename,
                    ],
                    [
                        'name'     => 'asset_type',
                        'contents' => 'FLOW_JSON',
                    ],
                ],
            ]);

            $responseObject->success = true;
            $responseObject->data    = json_decode($response->getBody()->getContents());
        } catch (GuzzleException $e) {
            $body = $e->getResponse()?->getBody()?->getContents();
            $responseObject->success = false;
            $responseObject->data    = json_decode($body ?? '{}');
            $responseObject->message = $responseObject->data->error->message ?? $e->getMessage();
        }

        return $responseObject;
    }

    private function formatMetaErrorMessage(\stdClass $response, string $fallback): string
    {
        $error = $response->data->error ?? null;
        $message = $response->message ?? $fallback;

        $code = $error->code ?? null;
        $subcode = $error->error_subcode ?? null;
        $userMessage = $error->error_user_msg ?? null;
        $traceId = $error->fbtrace_id ?? null;

        $isIntegrityBlock = ((int) $code === 139000)
            || str_contains(strtolower((string) $message), 'blocked by integrity');

        if (!$isIntegrityBlock) {
            return $message;
        }

        $parts = [
            'Meta rejected publishing this Flow because integrity requirements are not met.',
            'Check that the Meta Business Portfolio is verified, the WhatsApp display name is approved, the WABA/phone number are not restricted, the app has the required WhatsApp permissions, and Flow setup requirements such as webhooks/public key are complete.',
        ];

        if ($userMessage) {
            $parts[] = 'Meta details: ' . $userMessage;
        }

        if ($code || $subcode) {
            $parts[] = 'Meta error: ' . trim(($code ? '#' . $code : '') . ($subcode ? ' / subcode ' . $subcode : ''));
        }

        if ($traceId) {
            $parts[] = 'fbtrace_id: ' . $traceId;
        }

        return implode(' ', $parts);
    }

    // Fetch the raw content of a flow asset by its download URL (authenticated)
    private function fetchAssetContent(string $downloadUrl): ?string
    {
        if (!$downloadUrl) {
            return null;
        }

        try {
            $client   = new Client();
            $response = $client->request('GET', $downloadUrl, [
                'headers' => ['Authorization' => 'Bearer ' . $this->accessToken],
            ]);

            return $response->getBody()->getContents();
        } catch (\Throwable $e) {
            Log::warning('MetaFlows: failed to fetch asset content', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
