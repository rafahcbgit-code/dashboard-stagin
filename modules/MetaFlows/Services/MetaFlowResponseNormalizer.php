<?php

namespace Modules\MetaFlows\Services;

use App\Models\Contact;
use Illuminate\Support\Facades\Log;
use Modules\MetaFlows\Models\MetaFlow;
use Modules\MetaFlows\Models\MetaFlowResponse;

class MetaFlowResponseNormalizer
{
    /**
     * Build a field_name => value map from a MetaFlow response, including metadata fields.
     */
    public static function normalize(MetaFlowResponse $response, MetaFlow $flow, ?Contact $contact = null): array
    {
        $parsedJson = json_decode($response->response_json, true) ?? [];
        $values = static::normalizeFromPayload($parsedJson, $flow);

        $values['__timestamp']     = now()->toIso8601String();
        $values['__contact_phone'] = $contact?->phone ?? '';
        $values['__contact_name']  = trim(($contact?->first_name ?? '') . ' ' . ($contact?->last_name ?? ''));
        $values['__contact_email'] = $contact?->email ?? '';
        $values['__flow_token']    = $response->flow_token ?? '';
        $values['flow_name']      = $flow->name ?? '';

        return $values;
    }

    /**
     * Replace {{variable}} placeholders in a template string with actual values.
     * Unmatched placeholders are left as-is so users can debug their templates.
     */
    public static function substituteTemplate(string $template, array $values): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($matches) use ($values) {
            $key = $matches[1];
            return $values[$key] ?? $matches[0];
        }, $template);
    }

    /**
     * Normalize the raw Meta response JSON into a field_name => value map.
     * Meta returns keys from the Footer on-click-action.payload, but we reference
     * component name attributes. This method builds the reverse mapping via
     * Footer payload expressions (${form.field_name}).
     */
    private static function normalizeFromPayload(array $parsedJson, MetaFlow $flow): array
    {
        $values = $parsedJson;

        if (!$flow->asset_content) {
            return $values;
        }

        try {
            $json    = json_decode($flow->asset_content, true);
            $screens = $json['screens'] ?? [];

            foreach ($screens as $screen) {
                $children = $screen['layout']['children'] ?? [];

                foreach ($children as $child) {
                    $items = ($child['type'] === 'Form') ? ($child['children'] ?? []) : [$child];

                    foreach ($items as $item) {
                        if (($item['type'] ?? '') !== 'Footer') {
                            continue;
                        }

                        $payload = $item['on-click-action']['payload'] ?? [];

                        foreach ($payload as $payloadKey => $expression) {
                            if (preg_match('/\$\{form\.(.+?)\}/', (string) $expression, $matches)) {
                                $fieldName = $matches[1];
                                $resolved  = $parsedJson[$payloadKey] ?? null;

                                if ($resolved !== null) {
                                    $values[$fieldName] = $resolved;
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('MetaFlowResponseNormalizer: failed to parse asset_content', [
                'error' => $e->getMessage(),
            ]);
        }

        return $values;
    }
}