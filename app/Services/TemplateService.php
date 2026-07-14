<?php

namespace App\Services;

use App\Http\Resources\TemplateResource;
use App\Models\Organization;
use App\Models\Template;
use App\Services\WhatsappService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function __construct($organizationId)
    {
        $this->organizationId = $organizationId;
        $this->initializeWhatsappService();
    }

    private function initializeWhatsappService()
    {
        $config = Organization::where('id', $this->organizationId)->first()->metadata;
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
            $rows = Template::where('organization_id', $this->organizationId)->where('deleted_at', null)
                ->get()
                ->map(function ($row) {
                    return [
                        'value' => $row->id,
                        'label' => $row->name,
                    ];
                });

            return response()->json([$rows]);
        }

        return Inertia::render('User/Templates/Index', [
            'title' => __('templates'),
            'allowCreate' => true,
            'rows' => TemplateResource::collection(
                Template::where('organization_id', $this->organizationId)->where('deleted_at', null)->latest()->paginate(10)
            ),
        ]);
    }

    private function getTemplateDetailResponse(Request $request, $uuid)
    {
        if ($request->expectsJson()) {
            $row = Template::where('uuid', $uuid)->where('deleted_at', null)->first();
            return response()->json($row);
        }

        $data['languages'] = config('languages');
        $data['template'] = Template::where('uuid', $uuid)->first();
        $data['title'] = 'Edit Template';
        return Inertia::render('User/Templates/Edit', $data);
    }

    public function createTemplate(Request $request)
    {
        if ($request->isMethod('get')){
            $data['languages'] = config('languages');
            $data['settings'] = Organization::where('id', $this->organizationId)->first();
            
            return Inertia::render('User/Templates/Add', $data);
        } else if ($request->isMethod('post')){
            if ($response = $this->abortIfDemo('create')) {
                return $response;
            }

            $this->hydrateCopiedTemplateMedia($request);

            $validator = Validator::make($request->all(),[
                'name' => 'required',
                'category' => 'required',
                'language' => 'required',
            ]);

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

                return $carouselResponse;
            }

            return $this->whatsappService->createTemplate($request);
        }
    }

    public function updateTemplate(Request $request, $uuid)
    {
        if ($response = $this->abortIfDemo('update')) {
            return $response;
        }

        $validator = Validator::make($request->all(),[
            'name' => 'required',
            'category' => 'required',
            'language' => 'required',
        ]);

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
        $template = Template::where('uuid', $uuid)->first();
        if ($template) {
            $metadata = json_decode($template->metadata, true);
            if (($metadata['template_type'] ?? null) === 'carousel') {
                return $this->whatsappService->updateCarouselTemplate($request, $uuid);
            }
        }

        return $this->whatsappService->updateTemplate($request, $uuid);
    }

    public function deleteTemplate($uuid)
    {
        if ($response = $this->abortIfDemo('delete')) {
            return $response;
        }

        $query = $this->whatsappService->deleteTemplate($uuid);

        if($query->success === true){
            return response()->json([
                'success' => true,
                'message'=> __('Template deleted successfully')
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message'=> __('something went wrong. Refresh the page and try again')
            ]);
        }
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
