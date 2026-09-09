<?php

namespace App\Traits;

use App\Models\Campaign;
use App\Models\ChatMedia;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

trait TemplateTrait
{
    function buildTemplateRequest($campaignId, $contact, ?Campaign $campaign = null, ?Template $campaignTemplate = null)
    {
        $campaign = $campaign ?? Campaign::where('id', $campaignId)->first();
        $campaignTemplate = $campaignTemplate ?? Template::where('id', $campaign->template_id)->first();

        $metadata = json_decode($campaign->metadata, true);
        $this->applyTemplateDefaultHeaderMedia($metadata, $campaignTemplate);

        return $this->buildTemplate(
            $campaignTemplate->name,
            $campaignTemplate->language,
            json_decode(json_encode($metadata)),
            $contact
        );
    }

    function buildTemplate($templateName, $templateLanguage, $metadata, $contact)
    {
        $template = [
            'name' => $templateName,
            'language' => ['code' => $templateLanguage],
            'components' => [],
        ];

        if ($metadata->header && $metadata->header->parameters) {
            $headerComponent = $this->buildHeaderComponent($metadata, $contact);
            $template['components'][] = $headerComponent;
        }

        if ($metadata->body && property_exists($metadata->body, 'parameters') && !empty($metadata->body->parameters)) {
            $bodyComponent = $this->buildBodyComponent($metadata, $contact);
            $template['components'][] = $bodyComponent;
        }

        if ($metadata->buttons) {
            $buttonComponents = $this->buildButtonComponent($metadata, $contact);
            foreach ($buttonComponents as $buttonComponent) {
                $template['components'][] = $buttonComponent;
            }
        }

        $this->sanitizeTemplateTextParameters($template);

        $error = $this->validateResolvedTemplate($template);

        if ($error !== null) {
            Log::error('Invalid template payload', [
                'error' => $error,
                'template' => $template,
            ]);
            throw new \RuntimeException($error);
        }

        // Collect FLOW button stubs so the caller can pre-create MetaFlowResponse
        // records after a successful send. Keyed as '_flow_stubs' — this is stripped
        // by WhatsappService::sendTemplateMessage() before the request is sent to Meta.
        if ($metadata->buttons) {
            $flowStubs = [];
            foreach ($metadata->buttons as $key => $button) {
                if (($button->type ?? '') === 'FLOW') {
                    foreach ($template['components'] as $component) {
                        if (
                            ($component['type'] ?? '') === 'button' &&
                            ($component['sub_type'] ?? '') === 'flow' &&
                            (string) ($component['index'] ?? '') === (string) $key
                        ) {
                            $flowStubs[] = [
                                'meta_flow_id' => $button->flow_id ?? null,
                                'flow_token'   => $component['parameters'][0]['action']['flow_token'] ?? null,
                            ];
                            break;
                        }
                    }
                }
            }
            if (!empty($flowStubs)) {
                $template['_flow_stubs'] = $flowStubs;
                Log::info('MetaFlows: FLOW stubs collected for template send', [
                    'template_name' => $templateName,
                    'stubs'         => $flowStubs,
                ]);
            }
        }

        //\Log::info($template);
        return $template;
    }

    function buildHeaderComponent($metadata, $contact)
    {
        //dd($metadata->header);
        $headerComponent = [
            'type' => 'header',
            'parameters' => [],
        ];

        if ($metadata->header->parameters) {
            foreach ($metadata->header->parameters as $parameter) {
                $param = [];
                $paramType = strtolower($parameter->type);
                $param['type'] = $paramType;
                
                if ($paramType === 'image') {
                    $mediaId = $parameter->media_id ?? $parameter->id ?? null;
                    $mediaUrl = $parameter->value ?? $parameter->url ?? null;

                    if ($mediaId) {
                        $param['image']['id'] = (string) $mediaId;
                    } else {
                        $param['image']['link'] = is_string($mediaUrl) && filter_var($mediaUrl, FILTER_VALIDATE_URL)
                            ? $mediaUrl
                            : null;
                    }
                } else if ($paramType === 'video') {
                    $mediaId = $parameter->media_id ?? $parameter->id ?? null;
                    $mediaUrl = $parameter->value ?? $parameter->url ?? null;

                    if ($mediaId) {
                        $param['video']['id'] = (string) $mediaId;
                    } else {
                        $param['video']['link'] = is_string($mediaUrl) && filter_var($mediaUrl, FILTER_VALIDATE_URL)
                            ? $mediaUrl
                            : null;
                    }
                } else if ($paramType === 'document') {
                    $mediaId = $parameter->media_id ?? $parameter->id ?? null;
                    $mediaUrl = $parameter->value ?? $parameter->url ?? null;
                    $filename = $parameter->filename
                        ?? $parameter->name
                        ?? $parameter->document->filename
                        ?? $parameter->document->name
                        ?? null;

                    if (!$filename && is_string($mediaUrl)) {
                        $urlPath = parse_url($mediaUrl, PHP_URL_PATH) ?: $mediaUrl;
                        $filename = basename($urlPath);
                    }

                    if ($mediaId) {
                        $param['document']['id'] = (string) $mediaId;
                    } else {
                        $param['document']['link'] = is_string($mediaUrl) && filter_var($mediaUrl, FILTER_VALIDATE_URL)
                            ? $mediaUrl
                            : null;
                    }

                    if (!empty($filename)) {
                        $param['document']['filename'] = (string) $filename;
                    }
                } else if ($paramType === 'text') {
                    $value = $parameter->selection === 'static'
                        ? $parameter->value : $this->getParameters($contact, $parameter->value);

                    if ($value === null || trim((string)$value) === '') {
                        $value = 'N/A';
                    }
                    $param['text'] = (string) $value;
                }

                $headerComponent['parameters'][] = $param;
            }
        }

        return $headerComponent;
    }

    protected function applyTemplateDefaultHeaderMedia(array &$metadata, ?Template $template): void
    {
        $headerFormat = strtoupper((string) ($metadata['header']['format'] ?? ''));

        if (!in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            return;
        }

        $defaultMedia = $this->resolveTemplateDefaultHeaderMedia($template);

        if (!$defaultMedia || empty($defaultMedia['url'])) {
            return;
        }

        $parameters = $metadata['header']['parameters'] ?? [];
        $mediaParameterIndex = null;

        foreach ($parameters as $index => $parameter) {
            $type = strtoupper((string) ($parameter['type'] ?? ''));
            if (in_array($type, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
                $mediaParameterIndex = $index;
                break;
            }
        }

        $defaultParameter = [
            'type' => $headerFormat,
            'selection' => 'default',
            'value' => $defaultMedia['url'],
            'url' => $defaultMedia['url'],
        ];

        if (!empty($defaultMedia['name'])) {
            $defaultParameter['filename'] = $defaultMedia['name'];
            $defaultParameter['name'] = $defaultMedia['name'];
        }

        $defaultWasApplied = false;

        if ($mediaParameterIndex === null) {
            $metadata['header']['parameters'] = [$defaultParameter];
            $defaultWasApplied = true;
        } else {
            $current = $parameters[$mediaParameterIndex];
            $hasValue = !empty($current['value'] ?? null) || !empty($current['url'] ?? null);

            if (!$hasValue || ($current['selection'] ?? null) === 'default') {
                $parameters[$mediaParameterIndex] = array_merge($current, $defaultParameter);
                $metadata['header']['parameters'] = $parameters;
                $defaultWasApplied = true;
            }
        }

        if ($defaultWasApplied && !empty($defaultMedia['media_id'])) {
            $metadata['media'] = $defaultMedia['media_id'];
        }
    }

    protected function resolveTemplateDefaultHeaderMedia(?Template $template): ?array
    {
        if (!$template) {
            return null;
        }

        $metadata = json_decode($template->metadata ?? '{}', true);

        if (!is_array($metadata)) {
            return null;
        }

        $header = $this->findTemplateHeaderComponent($metadata);
        $format = strtoupper((string) ($header['format'] ?? ''));

        if (!in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            return null;
        }

        $storedMedia = $metadata['_default_header_media'] ?? $metadata['default_header_media'] ?? [];
        $mediaId = $storedMedia['media_id'] ?? $storedMedia['chat_media_id'] ?? null;
        $url = $storedMedia['url'] ?? $storedMedia['path'] ?? null;
        $name = $storedMedia['name'] ?? $storedMedia['filename'] ?? null;

        if (!$url && $mediaId) {
            $chatMedia = ChatMedia::find($mediaId);
            $url = $chatMedia?->path;
            $name = $name ?: $chatMedia?->name;
        }

        if (!$url) {
            $headerHandle = $header['example']['header_handle'][0] ?? null;
            if (is_string($headerHandle) && filter_var($headerHandle, FILTER_VALIDATE_URL)) {
                $url = $headerHandle;
            }
        }

        if (!$url) {
            return null;
        }

        return [
            'format' => $format,
            'url' => $url,
            'name' => $name,
            'media_id' => $mediaId,
        ];
    }

    private function findTemplateHeaderComponent(array $metadata): ?array
    {
        foreach (($metadata['components'] ?? []) as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === 'HEADER') {
                return $component;
            }
        }

        return null;
    }

    function buildBodyComponent($metadata, $contact)
    {
        $bodyComponent = [
            'type' => 'body',
            'parameters' => [],
        ];

        if ($metadata->body->parameters) {
            foreach ($metadata->body->parameters as $parameter) {
                $param = [];
                // Treat selection like dynamic if selection is missing but parameters exist
                $selection = $parameter->selection ?? ($parameter->type ?? 'dynamic');

                $value = ($selection === 'static')
                    ? $parameter->value
                    : $this->getParameters($contact, $parameter->value);

                if ($value === null || trim((string)$value) === '') {
                    $value = 'N/A';
                }

                $param['type'] = 'text';
                $param['text'] = (string) $value;
                $bodyComponent['parameters'][] = $param;
            }
        }

        return $bodyComponent;
    }

    function buildButtonComponent($metadata, $contact)
    {
        //dd($metadata->buttons);
        $buttons = $metadata->buttons;
        $buttonComponent = [];
        $buttonIndex = 0;

        foreach ($buttons as $key => $button) {
            if ($button->type === 'FLOW') {
                // FLOW buttons require an action parameter with a unique flow_token.
                // The flow_id / navigate_screen are baked into the template at creation time,
                // so only flow_token (and optional flow_action_data) are sent at send time.
                $buttonComponent[] = [
                    'type'       => 'button',
                    'sub_type'   => 'flow',
                    'index'      => (string) $key,
                    'parameters' => [
                        [
                            'type'   => 'action',
                            'action' => [
                                'flow_token'       => 'flow-' . uniqid(),
                                'flow_action_data' => new \stdClass(),
                            ],
                        ],
                    ],
                ];
                $buttonIndex++;
            } elseif (!empty($button->parameters) || $button->type === 'QUICK_REPLY') {
                $buttonComponent[] = [
                    'type' => 'button',
                    'sub_type' => strtolower($button->type),
                    'index' => $key,
                    'parameters' => [],
                ];

                if ($button->type === 'QUICK_REPLY' && empty($button->parameters)) {
                    $buttonComponent[$buttonIndex]['parameters'][] = [
                        'type' => 'payload',
                        'payload' => $button->text
                    ];
                } else {
                    foreach ($button->parameters as $parameter) {
                        $param = [];

                        if ($button->type === 'QUICK_REPLY') {
                            $type = $parameter->type ?? 'static';
                            $payload = $type === 'static'
                                ? $parameter->value
                                : $this->getParameters($contact, $parameter->value);

                            if ($payload === null || trim((string)$payload) === '') {
                                $payload = $button->text;
                            }

                            $param['type'] = 'payload';
                            $param['payload'] = (string) $payload;
                        } else if ($button->type === 'URL') {
                            $param['type'] = 'text';
                            $type = $parameter->type ?? 'static';
                            $rawValue = $type === 'static'
                                ? $parameter->value : $this->getParameters($contact, $parameter->value);

                            if ($rawValue === null || trim((string)$rawValue) === '') {
                                $rawValue = 'N/A';
                            }
                            $param['text'] = $this->normalizeUrlButtonParam($rawValue);
                        } else if ($button->type === 'COPY_CODE') {
                            $param['type'] = 'coupon_code';
                            $type = $parameter->type ?? 'static';
                            $value = $type === 'static'
                                ? $parameter->value : $this->getParameters($contact, $parameter->value);

                            if ($value === null || trim((string)$value) === '') {
                                $value = 'N/A';
                            }
                            $param['coupon_code'] = (string) $value;
                        }

                        $buttonComponent[$buttonIndex]['parameters'][] = $param;
                    }
                }

                $buttonIndex++;
            }
        }

        //  dd($buttonComponent);
        return $buttonComponent;
    }

    private function normalizeUrlButtonParam($value)
    {
        if ($value === null) {
            return 'https://example.com'; // safe fallback OR throw
        }

        $value = trim((string) $value);

        if ($value === '') {
            return 'https://example.com';
        }

        // DO NOT encode — Meta expects raw URL
        return $value;
    }

    function getParameters($contact, $parameter)
    {
        if (empty($parameter)) {
            Log::warning('Parameter is empty', ['contact_id' => $contact->id]);
            return null;
        }

        if ($parameter === 'first name') {
            return $contact->first_name;
        } else if ($parameter === 'last name') {
            return $contact->last_name;
        } else if ($parameter === 'name') {
            return $contact->first_name . ' ' . $contact->last_name;
        } else if ($parameter === 'email') {
            return $contact->email;
        } else if ($parameter === 'phone') {
            return $contact->phone;
        } else {
            // If the parameter exists in the 'metadata' column of the 'contacts' table
            if (empty($contact->metadata)) {
                Log::warning('Metadata is missing or empty', ['contact_id' => $contact->id]);
                return null;
            }

            $metadata = json_decode($contact->metadata, true);  // Convert the column to an array
            if ($metadata === null) {
                Log::error('Invalid metadata format', ['contact_id' => $contact->id, 'metadata' => $contact->metadata]);
                return null;
            }

            if (array_key_exists($parameter, $metadata)) {
                return $metadata[$parameter];
            }

            $normalizedParam = Str::slug(trim((string) $parameter));
            foreach ($metadata as $key => $value) {
                $normalizedKey = Str::slug(trim((string) $key));
                if ($normalizedKey === $normalizedParam) {
                    return $value;
                }
            }

            Log::warning('Parameter not found in metadata', ['parameter' => $parameter, 'contact_id' => $contact->id]);
            return null;
        }
    }

    private function validateResolvedTemplate(array $template): ?string
    {
        $components = $template['components'] ?? [];

        foreach ($components as $component) {
            $type = strtolower($component['type'] ?? '');

            if ($type === 'header') {
                foreach (($component['parameters'] ?? []) as $index => $param) {
                    if (($param['type'] ?? '') === 'text') {
                        $value = $param['text'] ?? null;
                        if ($value === null || trim((string) $value) === '') {
                            return __('Missing value for header variable :index.', [
                                'index' => $index + 1,
                            ]);
                        }
                    } elseif (in_array(($param['type'] ?? ''), ['image', 'video', 'document'], true)) {
                        $paramType = $param['type'];
                        $value = $param[$paramType]['link'] ?? $param[$paramType]['id'] ?? null;

                        if ($value === null || trim((string) $value) === '') {
                            return __('Missing media for header.');
                        }
                    }
                }
            }

            if ($type === 'body') {
                foreach (($component['parameters'] ?? []) as $index => $param) {
                    $value = $param['text'] ?? null;
                    if ($value === null || trim((string) $value) === '') {
                        return __('Missing value for body variable :index.', [
                            'index' => $index + 1,
                        ]);
                    }
                }
            }

            if ($type === 'button') {
                if (($component['sub_type'] ?? '') === 'quick_reply' && empty($component['parameters'])) {
                    // Quick reply can be empty if handled by sender
                    continue;
                }

                if (($component['sub_type'] ?? '') === 'flow') {
                    // FLOW buttons use action-type parameters — skip standard value validation
                    continue;
                }
                foreach (($component['parameters'] ?? []) as $index => $param) {
                    $paramType = $param['type'] ?? '';
                    $value = null;

                    if ($paramType === 'text') {
                        $value = $param['text'] ?? null;
                    } elseif ($paramType === 'payload') {
                        $value = $param['payload'] ?? null;
                    } elseif ($paramType === 'coupon_code') {
                        $value = $param['coupon_code'] ?? null;
                    }

                    if ($value === null || trim((string) $value) === '') {
                        $buttonIndex = isset($component['index']) ? ((int) $component['index'] + 1) : null;
                        return __('Missing value for button parameter (button :button, param :param).', [
                            'button' => $buttonIndex ?? '?',
                            'param' => $index + 1,
                        ]);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Meta rejects template text containing tabs/new lines or runs of spaces.
     * Normalize the generated payload only; stored contact/custom-field data is
     * intentionally left untouched.
     */
    private function sanitizeTemplateTextParameters(array &$template): void
    {
        $this->sanitizeTemplateTextValues($template);
    }

    /**
     * Carousel cards nest their button parameters below cards/components, so
     * text values must be normalized recursively rather than only at the top
     * level of a template payload.
     */
    private function sanitizeTemplateTextValues(array &$value): void
    {
        foreach ($value as $key => &$item) {
            if ($key === 'text' && is_scalar($item)) {
                $text = (string) $item;
                $text = preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', ' ', $text) ?? $text;
                $item = trim($text);
                continue;
            }

            if (is_array($item)) {
                $this->sanitizeTemplateTextValues($item);
            }
        }
        unset($item);
    }



    function buildCarouselTemplateRequest(int $templateId, object $contact, ?Template $template = null): array
    {
        \Log::info('buildCarouselTemplateRequest');

        $template = $template ?? Template::where('id', $templateId)->first();
        $metadata = json_decode($template->metadata, true);
        $carouselCards = $metadata['carousel_cards'] ?? [];

        $payload = [
            'name' => $template->name,
            'language' => ['code' => $template->language],
            'components' => [],
        ];

        // Add body component
        $payload['components'][] = [
            'type' => 'body',
        ];

        // Build carousel cards
        $metaCards = [];

        foreach ($carouselCards as $cardIndex => $card) {
            $cardComponents = [];

            // Add header if exists
            $headerType = strtolower($card['header_type'] ?? 'image');
            $headerHandle = null;
            $localPath = $card['local_path'] ?? null;
            $localUrl = $card['local_url'] ?? null;

            // A local carousel copy is optional. Deployments may not share
            // storage/app between servers, so never send a stale local URL
            // when its file is missing; fall back to Meta's template media.
            if ($localPath && \Storage::disk('local')->exists($localPath)) {
                $headerHandle = rtrim(config('app.url'), '/') . '/media/' . ltrim($localPath, '/');
            } elseif ($localUrl) {
                $parsedLocalUrl = parse_url($localUrl);
                $localUrlHost = strtolower((string) ($parsedLocalUrl['host'] ?? ''));
                $appHost = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
                $urlPath = rawurldecode($parsedLocalUrl['path'] ?? '');

                if ($localUrlHost && $localUrlHost !== $appHost) {
                    $headerHandle = $localUrl;
                } elseif (str_starts_with($urlPath, '/media/')) {
                    $relativePath = ltrim(substr($urlPath, strlen('/media/')), '/');
                    if (\Storage::disk('local')->exists($relativePath)) {
                        $headerHandle = $localUrl;
                    }
                }
            }

            $headerHandle = $headerHandle ?? $card['header_handle'] ?? null;

            if ($headerHandle) {
                $cardComponents[] = [
                    'type' => 'header',
                    'parameters' => [
                        [
                            'type' => $headerType,
                            $headerType => [
                                'link' => $headerHandle,
                            ],
                        ],
                    ],
                ];
            }

            // Add buttons INSIDE card with proper indexing
            $buttons = $card['buttons'] ?? [];
            $buttonIndex = 0;

            foreach ($buttons as $btn) {
                $btnType = strtoupper($btn['type'] ?? '');

                if ($btnType === 'QUICK_REPLY') {
                    $cardComponents[] = [
                        'type' => 'button',
                        'sub_type' => 'quick_reply',
                        'index' => $buttonIndex,
                        'parameters' => [
                            [
                                'type' => 'payload',
                                'payload' => $btn['text'] ?? '',
                            ],
                        ],
                    ];
                    $buttonIndex++;
                } elseif ($btnType === 'URL') {
                    $cardComponents[] = [
                        'type' => 'button',
                        'sub_type' => 'url',
                        'index' => $buttonIndex,
                        'parameters' => [
                            [
                                'type' => 'text',
                                'text' => $btn['url'] ?? '',
                            ],
                        ],
                    ];
                    $buttonIndex++;
                }
            }

            // ✅ CRITICAL: Add card_index
            $metaCards[] = [
                'card_index' => $cardIndex,
                'components' => $cardComponents,
            ];
        }

        // Add carousel component
        $payload['components'][] = [
            'type' => 'carousel',
            'cards' => $metaCards,
        ];

        \Log::info('Final Carousel Payload', ['payload' => $payload]);

        return $payload;
    }
}
