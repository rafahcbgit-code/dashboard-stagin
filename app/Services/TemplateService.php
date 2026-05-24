<?php

namespace App\Services;

use App\Events\NewChatEvent;
use App\Http\Resources\TemplateResource;
use App\Models\Organization;
use App\Models\Template;
use App\Services\WhatsappService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use DB;
use Validator;

class TemplateService
{
    private const MAX_HEADER_IMAGE_BYTES = 5 * 1024 * 1024;
    private const MAX_HEADER_VIDEO_BYTES = 16 * 1024 * 1024;

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

            $validator = Validator::make($request->all(),[
                'name' => 'required',
                'category' => 'required',
                'language' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false,'message'=>'Some required fields have not been filled','errors'=>$validator->messages()->get('*')]);
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

            foreach ($cards as $index => $card) {
                $headerType = strtoupper((string) ($card['header_type'] ?? ''));
                $file = $files[$index] ?? null;

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

        if ($format === 'IMAGE' && $file instanceof UploadedFile && $file->getSize() > self::MAX_HEADER_IMAGE_BYTES) {
            return $this->templateMediaSizeError('Image', '5MB', 'header_image');
        }

        if ($format === 'VIDEO' && $file instanceof UploadedFile && $file->getSize() > self::MAX_HEADER_VIDEO_BYTES) {
            return $this->templateMediaSizeError('Video', '16MB', 'header_video');
        }

        return null;
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
}
