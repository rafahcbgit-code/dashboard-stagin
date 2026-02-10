<?php

namespace App\Traits;

use App\Models\Campaign;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

trait TemplateTrait
{
    function buildTemplateRequest($campaignId, $contact)
    {
        $campaign = Campaign::where('id', $campaignId)->first();
        $campaignTemplate = Template::where('id', $campaign->template_id)->first();

        $metadata = json_decode($campaign->metadata);

        return $this->buildTemplate($campaignTemplate->name, $campaignTemplate->language, $metadata, $contact);
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

        if ($metadata->body && property_exists($metadata->body, 'parameters')) {
            $bodyComponent = $this->buildBodyComponent($metadata, $contact);
            $template['components'][] = $bodyComponent;
        }

        if ($metadata->buttons) {
            $buttonComponents = $this->buildButtonComponent($metadata, $contact);
            foreach ($buttonComponents as $buttonComponent) {
                $template['components'][] = $buttonComponent;
            }
        }

        $error = $this->validateResolvedTemplate($template);

        if ($error !== null) {
            Log::error('Invalid template payload', [
                'error' => $error,
                'template' => $template,
            ]);
            throw new \RuntimeException($error);
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
                $param['type'] = strtolower($parameter->type);
                if ($parameter->type === 'IMAGE') {
                    $param['image']['link'] = $parameter->value;
                } else if ($parameter->type === 'VIDEO') {
                    $param['video']['link'] = $parameter->value;
                } else if ($parameter->type === 'DOCUMENT') {
                    $param['document']['link'] = $parameter->value;
                } else if ($parameter->type === 'text' || $parameter->type === 'custom_field') {
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

    function buildBodyComponent($metadata, $contact)
    {
        $bodyComponent = [
            'type' => 'body',
            'parameters' => [],
        ];

        if ($metadata->body->parameters) {
            foreach ($metadata->body->parameters as $parameter) {
                // Treat selection like custom_field if set, or backtrack to dynamic logic if selection is missing but parameters exist
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
            if (!empty($button->parameters) || $button->type === 'QUICK_REPLY') {
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



    function buildCarouselTemplateRequest(int $templateId, object $contact): array
    {
        \Log::info('buildCarouselTemplateRequest');

        $template = Template::where('id', $templateId)->first();
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
            $headerHandle = $card['header_handle'] ?? null;

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
