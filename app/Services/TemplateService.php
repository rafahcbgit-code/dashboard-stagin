<?php

namespace App\Services;

use App\Http\Resources\TemplateResource;
use App\Models\Organization;
use App\Models\Template;
use App\Services\WhatsappService;
use App\Services\Templates\TemplateAccessService;
use App\Services\Templates\TemplateConnectionService;
use App\Services\Templates\TemplatePolicyService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Validator;

class TemplateService
{
    private const MAX_HEADER_IMAGE_BYTES = 5 * 1024 * 1024;
    private const MAX_HEADER_VIDEO_BYTES = 16 * 1024 * 1024;
    private const MIN_CAROUSEL_CARDS = 2;
    private const MAX_CAROUSEL_CARDS = 10;
    private const MAX_BUTTON_TEXT_CHARS = 40;
    private const MAX_COPY_CODE_CHARS = 20;

    private $whatsappService;
    private $organizationId;
    private Organization $organization;
    private TemplateAccessService $access;
    private TemplateConnectionService $connection;
    private TemplatePolicyService $policy;

    public function __construct($organizationId)
    {
        $this->organizationId = (int) $organizationId;
        $this->organization = Organization::findOrFail($this->organizationId);
        $this->access = app(TemplateAccessService::class);
        $this->connection = app(TemplateConnectionService::class);
        $this->policy = app(TemplatePolicyService::class);
        $this->initializeWhatsappService();
    }

    private function initializeWhatsappService()
    {
        $config = $this->organization->metadata;
        $config = $config ? json_decode($config, true) : [];

        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = config('graph.api_version');
        $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        $this->whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $this->organizationId);
    }

    public function getTemplates(Request $request, $uuid = null, $searchTerm = null)
    {
        $response = [];

        if ($uuid === null) {
            $response = $this->getTemplatesListResponse($request);
        } elseif ($uuid === 'sync') {
            $response = $this->whatsappService->syncTemplates();
        } else {
            $response = $this->getTemplateDetailResponse($request, $uuid);
        }

        return $response;
    }

    private function getTemplatesListResponse(Request $request)
    {
        if ($request->expectsJson()) {
            $rows = Template::query()
                ->where('organization_id', $this->organizationId)
                ->whereNull('deleted_at')
                ->where('status', 'APPROVED')
                ->orderBy('name')
                ->limit(250)
                ->get(['id', 'name'])
                ->map(function ($row) {
                    return [
                        'value' => $row->id,
                        'label' => $row->name,
                    ];
                });

            return response()->json([$rows]);
        }

        $search = trim((string) $request->query('search', ''));
        $category = strtoupper(trim((string) $request->query('category', '')));
        $status = strtoupper(trim((string) $request->query('status', '')));
        $needsAction = $request->boolean('needs_action');
        $allowedCategories = ['MARKETING', 'UTILITY', 'AUTHENTICATION'];
        $allowedStatuses = ['PENDING', 'APPROVED', 'REJECTED', 'PAUSED', 'DISABLED', 'IN_APPEAL'];

        $query = Template::query()
            ->where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', $search.'%'))
            ->when(in_array($category, $allowedCategories, true), fn ($query) => $query->where('category', $category))
            ->when(in_array($status, $allowedStatuses, true), fn ($query) => $query->where('status', $status))
            ->when($needsAction, fn ($query) => $query->where(function ($query) {
                $query->whereIn('status', ['REJECTED', 'PAUSED', 'DISABLED'])
                    ->orWhere(function ($query) {
                        $query->where('category', 'MARKETING')->where('has_unsubscribe_action', false);
                    });
            }));

        $stats = Template::query()
            ->where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(status = 'APPROVED') as approved")
            ->selectRaw("SUM(status = 'PENDING') as pending")
            ->selectRaw("SUM(status IN ('REJECTED','PAUSED','DISABLED')) as needs_action")
            ->first();

        return Inertia::render('User/Templates/Index', [
            'title' => __('templates'),
            'capabilities' => $this->access->capabilities(auth()->user(), $this->organizationId),
            'connection' => $this->connection->forOrganization($this->organization),
            'rules' => $this->policy->rulesFor($this->organization),
            'filters' => compact('search', 'category', 'status', 'needsAction'),
            'stats' => [
                'total' => (int) ($stats->total ?? 0),
                'approved' => (int) ($stats->approved ?? 0),
                'pending' => (int) ($stats->pending ?? 0),
                'needs_action' => (int) ($stats->needs_action ?? 0),
            ],
            'rows' => TemplateResource::collection($query
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->paginate(12)
                ->withQueryString()),
        ]);
    }

    private function getTemplateDetailResponse(Request $request, $uuid)
    {
        if ($request->expectsJson()) {
            $row = Template::where('uuid', $uuid)
                ->where('organization_id', $this->organizationId)
                ->whereNull('deleted_at')
                ->firstOrFail();

            if ($row && $row->metadata) {
                $metadata = json_decode($row->metadata, true);
                if (is_array($metadata)) {
                    $metadata = $this->removeUnavailableCarouselLocalUrls($metadata);
                    $row->metadata = json_encode(
                        $metadata,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
                }
            }

            return response()->json($row);
        }

        $data['languages'] = config('languages');
        $this->access->authorize('update', auth()->user(), $this->organizationId);
        $data['template'] = Template::where('uuid', $uuid)
            ->where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->firstOrFail();
        $data['settings'] = $this->organization;
        $data['connection'] = $this->connection->forOrganization($this->organization);
        $data['rules'] = $this->policy->rulesFor($this->organization);
        $data['capabilities'] = $this->access->capabilities(auth()->user(), $this->organizationId);
        $data['title'] = 'Edit Template';
        return Inertia::render('User/Templates/Edit', $data);
    }

    /**
     * Local carousel files are not guaranteed to be present after a deploy
     * or when storage is not shared between environments. Do not expose a
     * dead local URL to the campaign preview; the frontend can then use the
     * durable Meta media handle instead.
     */
    private function removeUnavailableCarouselLocalUrls(array $metadata): array
    {
        foreach ($metadata['carousel_cards'] ?? [] as $index => $card) {
            $localUrl = $card['local_url'] ?? null;
            if (!$localUrl || !is_string($localUrl)) {
                continue;
            }

            $urlPath = rawurldecode(parse_url($localUrl, PHP_URL_PATH) ?? '');
            if (!str_starts_with($urlPath, '/media/')) {
                continue;
            }

            $relativePath = ltrim(substr($urlPath, strlen('/media/')), '/');
            if (!Storage::disk('local')->exists($relativePath)) {
                $metadata['carousel_cards'][$index]['local_url'] = null;
            }
        }

        return $metadata;
    }

    public function createTemplate(Request $request)
    {
        if ($request->isMethod('get')){
            $this->access->authorize('create', auth()->user(), $this->organizationId);
            $data['languages'] = config('languages');
            $data['settings'] = $this->organization;
            $data['connection'] = $this->connection->forOrganization($this->organization);
            $data['rules'] = $this->policy->rulesFor($this->organization);
            $data['capabilities'] = $this->access->capabilities(auth()->user(), $this->organizationId);
            
            return Inertia::render('User/Templates/Add', $data);
        } else if ($request->isMethod('post')){
            $this->access->authorize('create', auth()->user(), $this->organizationId);
            $this->connection->assertConnected($this->organization);
            if ($response = $this->abortIfDemo('create')) {
                return $response;
            }

            $this->hydrateCopiedTemplateMedia($request);
            $this->policy->prepareAndValidate($request, $this->organization);

            $validator = Validator::make($request->all(), array_merge([
                'name' => 'required',
                'category' => 'required',
                'language' => 'required',
            ], $this->authenticationTemplateValidationRules($request)));

            if ($validator->fails()) {
                return response()->json(['success' => false,'message'=>'Some required fields have not been filled','errors'=>$validator->messages()->get('*')]);
            }

            if ($response = $this->validateTemplateButtons($request)) {
                return $response;
            }

            if ($response = $this->validateTemplateMediaUploads($request)) {
                return $response;
            }

            // If the frontend sends template_type = "carousel", route to
            // the dedicated carousel creation method on WhatsappService.
            // Everything else (UTILITY, AUTHENTICATION, standard MARKETING)
            // continues through the original createTemplate method.
            //Log::info("Request", $request->all());
            if ($request->get('template_type') === 'carousel') {
                Log::info('Creating carousel template request', [
                    'user_id' => auth()->id(),
                    'organization_id' => $this->organizationId,
                    'name' => $request->get('name'),
                    'category' => $request->get('category'),
                    'language' => $request->get('language'),
                    'cards' => count($request->input('cards', [])),
                    'files' => count($request->file('card_files', [])),
                ]);

                $carouselResponse = $this->whatsappService->createCarouselTemplate($request);

                if (isset($carouselResponse->success) && $carouselResponse->success === false) {
                    Log::error('Carousel template creation failed', [
                        'user_id' => auth()->id(),
                        'organization_id' => $this->organizationId,
                        'errors' => $carouselResponse->data ?? null,
                        'message' => $carouselResponse->message ?? null,
                    ]);
                }

                $this->markUnsubscribeCompliance($request, $carouselResponse);

                return $carouselResponse;
            }

            $response = $this->whatsappService->createTemplate($request);
            $this->markUnsubscribeCompliance($request, $response);

            return $response;
        }
    }

    public function updateTemplate(Request $request, $uuid)
    {
        $this->access->authorize('update', auth()->user(), $this->organizationId);
        $this->connection->assertConnected($this->organization);
        if ($response = $this->abortIfDemo('update')) {
            return $response;
        }

        $this->policy->prepareAndValidate($request, $this->organization);

        $validator = Validator::make($request->all(), array_merge([
            'name' => 'required',
            'category' => 'required',
            'language' => 'required',
        ], $this->authenticationTemplateValidationRules($request)));

        if ($validator->fails()) {
            return response()->json(['success' => false,'message'=>'Some required fields have not been filled','errors'=>$validator->messages()->get('*')]);
        }

        if ($response = $this->validateTemplateButtons($request)) {
            return $response;
        }

        if ($response = $this->validateTemplateMediaUploads($request)) {
            return $response;
        }

        // Check if the existing template is a carousel by reading its
        // metadata. If so, route to the carousel-specific update method
        // which only allows text changes (no media re-upload).
        $template = Template::where('uuid', $uuid)
            ->where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->firstOrFail();
        $previousStatus = (string) $template->status;
        if ($template) {
            $metadata = json_decode($template->metadata, true);
            if (($metadata['template_type'] ?? null) === 'carousel') {
                $response = $this->whatsappService->updateCarouselTemplate($request, $uuid);
                $this->markUnsubscribeCompliance($request, $response, $template, $previousStatus);

                return $response;
            }
        }

        $response = $this->whatsappService->updateTemplate($request, $uuid);
        $this->markUnsubscribeCompliance($request, $response, $template, $previousStatus);

        return $response;
    }

    public function deleteTemplate($uuid)
    {
        $this->access->authorize('delete', auth()->user(), $this->organizationId);
        if ($response = $this->abortIfDemo('delete')) {
            return $response;
        }

        $query = $this->whatsappService->deleteTemplate($uuid);

        if(($query->success ?? false) === true){
            return response()->json([
                'success' => true,
                'message'=> __('Template deleted successfully')
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => $query->message
                    ?? data_get($query, 'data.error.error_user_msg')
                    ?? data_get($query, 'data.error.message')
                    ?? __('Something went wrong. Refresh the page and try again'),
            ]);
        }
    }

    private function markUnsubscribeCompliance(Request $request, object $response, ?Template $template = null, ?string $previousStatus = null): void
    {
        if (($response->success ?? false) !== true) {
            return;
        }

        $template ??= Template::query()
            ->where('organization_id', $this->organizationId)
            ->where('meta_id', data_get($response, 'data.id'))
            ->first();

        if (! $template) {
            return;
        }

        $unsubscribeKeywords = strtoupper((string) $request->input('category')) === 'MARKETING'
            ? $this->policy->unsubscribeKeywordsFromRequest($request)
            : [];
        $hasAction = strtoupper((string) $request->input('category')) === 'MARKETING'
            && $this->policy->hasUnsubscribeAction($request);
        $previousUnsubscribeMetadata = data_get($template->internal_metadata, 'unsubscribe_action', []);

        $template->has_unsubscribe_action = $hasAction;
        $template->internal_metadata = array_merge($template->internal_metadata ?? [], [
            'unsubscribe_action' => [
                'enabled' => $hasAction,
                'keyword_snapshot' => $unsubscribeKeywords[0]
                    ?? ($previousUnsubscribeMetadata['keyword_snapshot'] ?? $this->policy->primaryUnsubscribeKeyword($this->organization)),
                'version' => 1,
            ],
        ]);
        $template->save();

        if ($unsubscribeKeywords !== []) {
            $this->rememberUnsubscribeButtonWords($unsubscribeKeywords);
        }

        $template->refresh();
        app(\App\Services\Templates\TemplateStatusHistoryService::class)->record(
            $template,
            $previousStatus,
            (string) $template->status,
            (string) $template->category,
            (string) $template->category,
            null,
            $previousStatus === null ? 'submission' : 'resubmission'
        );
    }

    /** Keep custom button replies recognized even after campaign words change. */
    private function rememberUnsubscribeButtonWords(array $keywords): void
    {
        DB::transaction(function () use ($keywords): void {
            $organization = Organization::query()->lockForUpdate()->find($this->organizationId);
            if (! $organization) {
                return;
            }

            $metadata = json_decode($organization->metadata ?: '{}', true) ?: [];
            $campaignSettings = $metadata['campaign_settings'] ?? [];
            $existing = is_array($campaignSettings['unsubscribe_button_triggers'] ?? null)
                ? $campaignSettings['unsubscribe_button_triggers']
                : [];
            $known = [];
            foreach (array_merge($existing, $keywords) as $word) {
                $word = trim((string) $word);
                if ($word === '') {
                    continue;
                }
                $normalized = mb_strtolower($word);
                $known[$normalized] = $word;
            }

            $metadata['campaign_settings'] = array_merge($campaignSettings, [
                'unsubscribe_button_triggers' => array_values($known),
            ]);
            $organization->metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $organization->save();
        });
    }

    protected function abortIfDemo($type)
    {
        if (app()->environment('demo') && $this->organizationId == 1) {
            if($type == 'delete'){
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to do this in this demo account. To test template features please create your own account.'
                ]);
            } else {
                $responseObject = new \stdClass();
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->message = 'You are not allowed to do this in this demo account. To test template features please create your own account.';

                return response()->json($responseObject);
            }
        }

        return null;
    }

    /**
     * Authentication templates have a stricter payload than standard templates.
     * Validate their nested button before WhatsappService builds Meta's request so
     * malformed client payloads become a useful 422 response instead of a 500.
     */
    private function authenticationTemplateValidationRules(Request $request): array
    {
        if ($request->input('category') !== 'AUTHENTICATION') {
            return [];
        }

        return [
            'authentication_button' => ['required', 'array'],
            'authentication_button.type' => ['required', 'in:OTP'],
            'authentication_button.otp_type' => ['required', 'in:copy_code,one_tap,zero_tap'],
            'authentication_button.text' => ['required', 'string', 'max:20'],
            'authentication_button.autofill_text' => ['required_unless:authentication_button.otp_type,copy_code', 'nullable', 'string', 'max:20'],
            'authentication_button.supported_apps' => ['required_unless:authentication_button.otp_type,copy_code', 'array', 'max:5'],
            'authentication_button.supported_apps.*.package_name' => ['required_unless:authentication_button.otp_type,copy_code', 'string'],
            'authentication_button.supported_apps.*.signature_hash' => ['required_unless:authentication_button.otp_type,copy_code', 'string'],
            'authentication_button.zero_tap_terms_accepted' => ['required_if:authentication_button.otp_type,zero_tap', 'accepted'],
        ];
    }

    private function validateTemplateMediaUploads(Request $request)
    {
        if ($request->get('template_type') === 'carousel') {
            $cards = $request->input('cards', []);
            $files = $request->file('card_files', []);
            $cardCount = is_array($cards) ? count($cards) : 0;

            if ($cardCount < self::MIN_CAROUSEL_CARDS || $cardCount > self::MAX_CAROUSEL_CARDS) {
                return $this->carouselCardCountError();
            }

            foreach ($cards as $index => $card) {
                $headerType = strtoupper((string) ($card['header_type'] ?? ''));
                $file = $files[$index] ?? null;

                if (in_array($headerType, ['IMAGE', 'VIDEO'], true) && !$file instanceof UploadedFile) {
                    return $this->templateMediaRequiredError("cards.{$index}.media");
                }

                if ($headerType === 'IMAGE' && $file instanceof UploadedFile && $file->getSize() > self::MAX_HEADER_IMAGE_BYTES) {
                    return $this->templateMediaSizeError('Image', '5MB', 'header_image');
                }

                if ($headerType === 'VIDEO' && $file instanceof UploadedFile && $file->getSize() > self::MAX_HEADER_VIDEO_BYTES) {
                    return $this->templateMediaSizeError('Video', '16MB', 'header_video');
                }
            }

            return null;
        }

        $header = $request->input('header', []);
        $format = strtoupper((string) ($header['format'] ?? ''));
        $file = data_get($request->allFiles(), 'header.example');

        if (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) && !$file instanceof UploadedFile) {
            return $this->templateMediaRequiredError('header.example');
        }

        if ($format === 'IMAGE' && $file instanceof UploadedFile && $file->getSize() > self::MAX_HEADER_IMAGE_BYTES) {
            return $this->templateMediaSizeError('Image', '5MB', 'header_image');
        }

        if ($format === 'VIDEO' && $file instanceof UploadedFile && $file->getSize() > self::MAX_HEADER_VIDEO_BYTES) {
            return $this->templateMediaSizeError('Video', '16MB', 'header_video');
        }

        return null;
    }

    private function hydrateCopiedTemplateMedia(Request $request): void
    {
        $sourceUuid = $request->input('source_template_uuid');
        if (!$sourceUuid) {
            return;
        }

        $source = Template::where('uuid', $sourceUuid)
            ->where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->first();

        if (!$source) {
            return;
        }

        $metadata = json_decode($source->metadata ?: '{}', true) ?: [];
        // Read Symfony's file bag directly. Calling allFiles() here would cache
        // the pre-hydration files in Laravel, causing the injected copies to be
        // invisible to the later upload code.
        $files = $request->files->all();

        if ($request->input('template_type') === 'carousel') {
            $sourceCards = $metadata['carousel_cards'] ?? [];
            $uploadedCards = $files['card_files'] ?? [];

            foreach ($request->input('cards', []) as $index => $card) {
                if (isset($uploadedCards[$index]) || filter_var($card['discard_media'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }

                $sourceCard = $sourceCards[$index] ?? [];
                $uploadedCards[$index] = $this->copiedMediaFile(
                    $sourceCard['local_path'] ?? null,
                    $sourceCard['local_url'] ?? null,
                    "carousel_card_{$index}"
                );

                if (!$uploadedCards[$index] && !empty($sourceCard['header_handle'])) {
                    $uploadedCards[$index] = $this->copiedMediaFile(
                        null,
                        $sourceCard['header_handle'],
                        "carousel_card_{$index}"
                    );
                }

                if (!$uploadedCards[$index]) {
                    throw ValidationException::withMessages([
                        "cards.{$index}.media" => __('The original template media could not be copied.'),
                    ]);
                }
            }

            $files['card_files'] = array_filter($uploadedCards);
        } elseif (!$request->boolean('discard_header_media') && !data_get($files, 'header.example')) {
            $media = $metadata['_default_header_media'] ?? $metadata['default_header_media'] ?? [];
            $header = collect($metadata['components'] ?? [])->first(
                fn ($component) => strtoupper($component['type'] ?? '') === 'HEADER'
            ) ?? [];
            $headerHandle = data_get($header, 'example.header_handle.0');
            $uploadedFile = $this->copiedMediaFile(null, $media['url'] ?? null, $media['name'] ?? 'template_header');

            if (!$uploadedFile && $headerHandle) {
                $uploadedFile = $this->copiedMediaFile(null, $headerHandle, $media['name'] ?? 'template_header');
            }

            if (!$uploadedFile) {
                throw ValidationException::withMessages([
                    'header.example' => __('The original template media could not be copied.'),
                ]);
            }

            data_set($files, 'header.example', $uploadedFile);
        }

        $request->files->replace($files);
    }

    private function copiedMediaFile(?string $localPath, ?string $url, string $fallbackName): ?UploadedFile
    {
        $path = null;
        $name = $fallbackName;

        if ($localPath && Storage::disk('local')->exists($localPath)) {
            $path = Storage::disk('local')->path($localPath);
            $name = basename($localPath);
        } elseif ($url) {
            $parsed = parse_url($url);
            $appHost = parse_url(config('app.url'), PHP_URL_HOST);
            $host = strtolower($parsed['host'] ?? '');
            $urlPath = rawurldecode($parsed['path'] ?? '');

            if ($host === strtolower((string) $appHost) && str_starts_with($urlPath, '/media/')) {
                $relativePath = ltrim(substr($urlPath, strlen('/media/')), '/');
                if (Storage::disk('local')->exists($relativePath)) {
                    $path = Storage::disk('local')->path($relativePath);
                    $name = basename($relativePath);
                }
            } elseif ($this->isAllowedTemplateMediaHost($host)) {
                $response = Http::timeout(30)->withOptions(['allow_redirects' => false])->get($url);
                if ($response->successful() && strlen($response->body()) <= self::MAX_HEADER_VIDEO_BYTES) {
                    $extension = pathinfo($urlPath, PATHINFO_EXTENSION);
                    $temporaryPath = tempnam(storage_path('app'), 'template_copy_');
                    file_put_contents($temporaryPath, $response->body());
                    $path = $temporaryPath;
                    $name = $fallbackName . ($extension ? ".{$extension}" : '');
                    app()->terminating(fn () => @unlink($temporaryPath));
                }
            }
        }

        return $path && is_file($path)
            ? new UploadedFile($path, $name, mime_content_type($path) ?: null, null, true)
            : null;
    }

    private function isAllowedTemplateMediaHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        foreach (['.whatsapp.net', '.facebook.com', '.fbcdn.net', '.amazonaws.com'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function validateTemplateButtons(Request $request)
    {
        $buttonGroups = [];

        if (is_array($request->input('buttons'))) {
            $buttonGroups['buttons'] = $request->input('buttons');
        }

        if (is_array($request->input('cards'))) {
            foreach ($request->input('cards') as $cardIndex => $card) {
                if (isset($card['buttons']) && is_array($card['buttons'])) {
                    $buttonGroups["cards.$cardIndex.buttons"] = $card['buttons'];
                }
            }
        }

        if (is_array($request->input('carousel_cards'))) {
            foreach ($request->input('carousel_cards') as $cardIndex => $card) {
                if (isset($card['buttons']) && is_array($card['buttons'])) {
                    $buttonGroups["carousel_cards.$cardIndex.buttons"] = $card['buttons'];
                }
            }
        }

        foreach ($buttonGroups as $groupPath => $buttons) {
            foreach ($buttons as $index => $button) {
                $type = strtoupper((string) ($button['type'] ?? ''));

                if ($type === 'COPY_CODE') {
                    $copyCode = $this->buttonValueToString($button['example'] ?? '');
                    if ($this->characterLength($copyCode) > self::MAX_COPY_CODE_CHARS) {
                        return $this->templateButtonLimitError(
                            "$groupPath.$index.example",
                            'Copy offer code must not exceed ' . self::MAX_COPY_CODE_CHARS . ' characters.'
                        );
                    }

                    continue;
                }

                $text = $this->buttonValueToString($button['text'] ?? '');
                if ($this->characterLength($text) > self::MAX_BUTTON_TEXT_CHARS) {
                    return $this->templateButtonLimitError(
                        "$groupPath.$index.text",
                        'Button text must not exceed ' . self::MAX_BUTTON_TEXT_CHARS . ' characters.'
                    );
                }
            }
        }

        return null;
    }

    private function templateButtonLimitError(string $field, string $message)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => [
                $field => [$message],
            ],
        ]);
    }

    private function characterLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function buttonValueToString($value): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return trim((string) $value);
    }

    private function templateMediaSizeError(string $mediaLabel, string $limit, string $field)
    {
        $message = __("{$mediaLabel} size must not exceed {$limit}.");

        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => [
                'error' => [
                    'message' => $message,
                ],
            ],
            'errors' => [
                $field => [$message],
            ],
        ]);
    }

    private function templateMediaRequiredError(string $field)
    {
        $message = __('A media file is required for the template header.');

        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => [
                'error' => [
                    'message' => $message,
                ],
            ],
            'errors' => [
                $field => [$message],
            ],
        ]);
    }

    private function carouselCardCountError()
    {
        $message = __('Carousel templates must contain between :min and :max cards.', [
            'min' => self::MIN_CAROUSEL_CARDS,
            'max' => self::MAX_CAROUSEL_CARDS,
        ]);

        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => [
                'error' => [
                    'message' => $message,
                ],
            ],
            'errors' => [
                'cards' => [$message],
            ],
        ], 422);
    }
}
