<?php

namespace App\Services;

use Carbon\Carbon;
use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use App\Models\ChatMedia;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\Setting;
use App\Models\Template;
use App\Traits\TemplateTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class CampaignService
{
    use TemplateTrait;

    public function store(object $request){
        $organizationId = session()->get('current_organization');

        $timezone = Setting::where('key', 'timezone')->value('value');
        $organization = Organization::find($organizationId);
        $organizationMetadata = json_decode($organization->metadata ?? '{}', true);
        $organizationMetadata = is_array($organizationMetadata) ? $organizationMetadata : [];
        $timezone = $organizationMetadata['timezone'] ?? $timezone;

        // Enforce messaging_limit_tier: selected group size must be <= allowed tier value
        $tierLimit = $this->resolveTierLimit($organizationMetadata);

        if ($request->contacts === 'all') {
            $selectedCount = Contact::where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->count();
        } else {
            $selectedGroup = ContactGroup::where('uuid', $request->contacts)
                ->where('organization_id', $organizationId)
                ->withCount(['contacts as contacts_count' => function ($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                }])
                ->first();

            if (!$selectedGroup) {
                throw ValidationException::withMessages([
                    'contacts' => __('Selected contact group is invalid or does not belong to your organization.'),
                ]);
            }

            $selectedCount = $selectedGroup->contacts_count;
        }

        if ($selectedCount > $tierLimit) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'contacts' => __(
                    'The selected group contains :groupCount contacts, which exceeds your messaging limit of :limit. Please choose a smaller group or update your WhatsApp tier.',
                    ['groupCount' => $selectedCount, 'limit' => $tierLimit]
                ),
            ]);
        }

        // Trial accounts can only send up to remaining campaign messages.
        $trialSubscription = Subscription::where('organization_id', $organizationId)
            ->where('status', 'trial')
            ->where('valid_until', '>', now())
            ->orderByDesc('id')
            ->first();

        if ($trialSubscription) {
            $campaignMessagesLimit = Campaign::campaignMessagesLimit();

            if ($campaignMessagesLimit !== -1) {
                $overallDelivered = Campaign::overallDeliveryCountForOrg($organizationId);
                $remainingMessages = max(0, $campaignMessagesLimit - $overallDelivered);

                if ($selectedCount > $remainingMessages) {
                    throw ValidationException::withMessages([
                        'contacts' => __('You can send campaign messages to only :remaining contacts.', [
                            'remaining' => $remainingMessages,
                        ]),
                    ]);
                }
            }
        }

        $template = Template::where('uuid', $request->template)->first();
        $contactGroup = ContactGroup::where('uuid', $request->contacts)->first();

        try {
            DB::transaction(function () use ($request, $organizationId, $template, $contactGroup, $timezone) {
                //Request metadata
                $mediaId = null;
                $defaultHeaderMedia = $this->resolveTemplateDefaultHeaderMedia($template);

                if(in_array($request->header['format'], ['IMAGE', 'DOCUMENT', 'VIDEO'])){
                    $header = $request->header;
                    
                    if ($request->header['parameters']) {
                        $metadata['header']['format'] = $header['format'];
                        $metadata['header']['parameters'] = [];
                
                        foreach ($request->header['parameters'] as $key => $parameter) {
                            if ($parameter['selection'] === 'upload' && empty($defaultHeaderMedia['url'])) {
                                //$path = $parameter['value']->store('public');
                                //$imageUrl = config('app.url') . '/media/' . $path;

                                $storage = Setting::where('key', 'storage_system')->first()->value;
                                $fileName = $parameter['value']->getClientOriginalName();
                                $fileContent = $parameter['value'];

                                if($storage === 'local'){
                                    $file = Storage::disk('local')->put('public', $fileContent);
                                    $mediaFilePath = $file;
                    
                                    $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
                                } else if($storage === 'aws') {
                                    $file = $parameter['value'];
                                    $uploadedFile = $file->store('uploads/media/sent/' . $organizationId, 's3');
                                    $mediaFilePath = Storage::disk('s3')->url($uploadedFile);
                    
                                    $mediaUrl = $mediaFilePath;
                                }

                                $contentType = $this->getContentTypeFromUrl($mediaUrl);
                                $mediaSize = $this->getMediaSizeInBytesFromUrl($mediaUrl);

                                //save media
                                $chatMedia = new ChatMedia;
                                $chatMedia->name = $fileName;
                                $chatMedia->path = $mediaUrl;
                                $chatMedia->type = $contentType;
                                $chatMedia->size = $mediaSize;
                                $chatMedia->created_at = now();
                                $chatMedia->save();

                                $mediaId = $chatMedia->id;
                            } else {
                                $mediaUrl = $defaultHeaderMedia['url']
                                    ?? $parameter['value']
                                    ?? $parameter['url']
                                    ?? null;
                                $mediaId = $defaultHeaderMedia['media_id'] ?? $mediaId;
                            }
                
                            $metadata['header']['parameters'][] = [
                                'type' => $parameter['type'],
                                'selection' => $defaultHeaderMedia ? 'default' : $parameter['selection'],
                                'value' => $mediaUrl,
                                'url' => $mediaUrl,
                            ];
                        }
                    }
                } else {
                    $metadata['header'] = $request->header;
                }

                if (!isset($metadata['header'])) {
                    $metadata['header'] = $request->header;
                }

                $metadata['body'] = $request->body;
                $metadata['footer'] = $request->footer;
                $metadata['buttons'] = $request->buttons;
                $metadata['media'] = $mediaId;
                $this->applyTemplateDefaultHeaderMedia($metadata, $template);

                // Convert $request->time from organization's timezone to UTC
                $scheduledAt = $request->skip_schedule ? Carbon::now('UTC') : Carbon::parse($request->time, $timezone)->setTimezone('UTC');

                //Create campaign
                $campaign = new Campaign;
                $campaign['organization_id'] = $organizationId;
                $campaign['name'] = $request->name;
                $campaign['template_id'] = $template->id;
                $campaign['contact_group_id'] = $request->contacts === 'all' ? 0 : $contactGroup->id;
                $campaign['metadata'] = json_encode($metadata);
                $campaign['created_by'] = auth()->user()->id;
                $campaign['status'] = 'scheduled';
                $campaign['scheduled_at'] = $scheduledAt;
                $campaign->save();
            });
        } catch (\Exception $e) {
            // Handle the exception here if needed.
            // The transaction has already been rolled back automatically.
            Log::error('Failed to store campaign', [
                'error_message' => $e->getMessage(),
                'organization_id' => $organizationId,
                'template' => $request->template,
                'contacts' => $request->contacts,
                'user_id' => auth()->user()->id,
                'stack_trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function resolveTierLimit(array $metadata): int
    {
        $tierValue = $this->extractTierValueFromMetadata($metadata);
        $limit = $this->parseTierLimitValue($tierValue);

        return $limit ?? 250;
    }

    private function extractTierValueFromMetadata(array $metadata): ?string
    {
        $candidates = [];

        if (isset($metadata['whatsapp']) && is_array($metadata['whatsapp'])) {
            $candidates[] = $metadata['whatsapp']['whatsapp_business_manager_messaging_limit'] ?? null;
            //$candidates[] = $metadata['whatsapp']['messaging_limit_tier'] ?? null;
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return (string) $candidate;
            }
        }

        return null;
    }

    private function parseTierLimitValue(?string $tier): ?int
    {
        if (!$tier) {
            return null;
        }

        $value = strtoupper(trim($tier));

        if ($value === 'TIER_UNLIMITED' || $value === 'UNLIMITED') {
            return PHP_INT_MAX;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (preg_match('/TIER_(\d+(?:\.\d+)?)([KM]?)/i', $value, $matches)) {
            $number = (float) $matches[1];
            $suffix = strtoupper($matches[2] ?? '');

            switch ($suffix) {
                case 'K':
                    return (int) ($number * 1000);
                case 'M':
                    return (int) ($number * 1000000);
                default:
                    return (int) $number;
            }
        }

        return null;
    }

    private function getMediaInfo($path)
    {
        $fullPath = storage_path('app/public/' . $path);

        return [
            'name' => pathinfo($fullPath, PATHINFO_FILENAME),
            'type' => File::extension($fullPath),
            'size' => Storage::size($path), // Size in bytes
        ];
    }

    public function sendCampaign(){
        //Laravel jobs implementation
        SendCampaignJob::dispatch();
    }

    public function destroy($uuid)
    {
        Campaign::where('uuid', $uuid)->update([
            'deleted_by' => auth()->user()->id,
            'deleted_at' => now()
        ]);
    }

    private function getContentTypeFromUrl($url) {
        try {
            // Make a HEAD request to fetch headers only
            $response = Http::head($url);
    
            // Check if the Content-Type header is present
            if ($response->hasHeader('Content-Type')) {
                return $response->header('Content-Type');
            }
    
            return null;
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error('Error fetching headers: ' . $e->getMessage());
            return null;
        }
    }

    private function getMediaSizeInBytesFromUrl($url) {
        $url = ltrim($url, '/');
        $imageContent = file_get_contents($url);
    
        if ($imageContent !== false) {
            return strlen($imageContent);
        }
    
        return null;
    }
}
