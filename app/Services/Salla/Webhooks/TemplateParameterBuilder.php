<?php

namespace App\Services\Salla\Webhooks;

use App\Models\Template;
use App\Services\PhoneService;
use App\Services\Salla\Webhooks\Exceptions\SallaWebhookNonRetryableException;
use Illuminate\Support\Collection;

class TemplateParameterBuilder
{
    public function __construct(private readonly WhatsAppTemplateVariableExtractor $extractor)
    {
    }

    /**
     * Build ChatService-compatible header/body/buttons parameter metadata.
     *
     * @param  iterable<int, mixed>  $mappings  Eloquent mapping models (event mappings or status-rule mappings).
     * @return array{0: array|null, 1: array, 2: array}
     */
    public function build(Template $template, iterable $mappings, array $payload): array
    {
        $variables = $this->extractor->extract($template);
        $mappingByKey = $this->indexMappings($mappings);

        $headerParams = [];
        $bodyParams = [];
        $buttonParamValues = [];

        foreach ($variables as $var) {
            $key = $var['key'];
            $component = $var['component'];
            $mapping = $mappingByKey[$key] ?? null;

            if (!$mapping) {
                throw new SallaWebhookNonRetryableException('missing_mapping:' . $key);
            }

            $value = $this->resolveMappedValue($mapping, $payload);

            if ($component === 'header') {
                $headerParams[] = [
                    'type' => 'text',
                    'selection' => 'static',
                    'value' => $value,
                ];
            } elseif ($component === 'body') {
                $bodyParams[] = [
                    'type' => 'text',
                    'selection' => 'static',
                    'value' => $value,
                ];
            } elseif ($component === 'button') {
                $this->captureButtonParam($buttonParamValues, $key, $value);
            }
        }

        // Only include header if it actually has variables to fill.
        $headerMeta = empty($headerParams) ? null : [
            'format' => 'TEXT',
            'parameters' => $headerParams,
        ];

        $bodyMeta = [
            'text' => '',
            'parameters' => $bodyParams,
        ];

        $buttonsMeta = $this->buildButtonsMeta($template, $buttonParamValues);

        return [$headerMeta, $bodyMeta, $buttonsMeta];
    }

    private function captureButtonParam(array &$buttonParamValues, string $key, string $value): void
    {
        // Supported keys:
        // - button.url.{buttonIndex}.{n}
        // - button.copy_code.{buttonIndex}.{n}
        $parts = explode('.', $key);
        if (count($parts) < 4) {
            return;
        }

        if ($parts[0] !== 'button') {
            return;
        }

        $type = $parts[1] ?? null;
        $buttonIndex = isset($parts[2]) ? (int) $parts[2] : null;
        $paramIndex = isset($parts[3]) ? (int) $parts[3] : null;

        if ($buttonIndex === null || $paramIndex === null) {
            return;
        }

        if (!in_array($type, ['url', 'copy_code'], true)) {
            return;
        }

        if ($type === 'url') {
            $value = $this->normalizeUrlButtonValue($value);
        }

        $buttonParamValues[$type][$buttonIndex][$paramIndex] = $value;
    }

    private function normalizeUrlButtonValue(string $value): string
    {
        $value = trim($value);

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $path = parse_url($value, PHP_URL_PATH);
            if (is_string($path)) {
                $path = trim($path, '/');
                if ($path !== '') {
                    $base = basename($path);
                    if (is_string($base) && $base !== '') {
                        return $base;
                    }
                }
            }
        }

        return $value;
    }

    private function buildButtonsMeta(Template $template, array $buttonParamValues): array
    {
        $metadata = json_decode((string) $template->metadata, true);
        if (!is_array($metadata)) {
            return [];
        }

        $components = $metadata['components'] ?? [];
        if (!is_array($components)) {
            return [];
        }

        $buttonsMeta = [];

        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }

            if (strtoupper((string) ($component['type'] ?? '')) !== 'BUTTONS') {
                continue;
            }

            $buttons = $component['buttons'] ?? [];
            if (!is_array($buttons)) {
                continue;
            }

            foreach ($buttons as $index => $button) {
                if (!is_array($button)) {
                    continue;
                }

                $type = strtoupper((string) ($button['type'] ?? ''));
                $text = $button['text'] ?? null;
                $text = is_string($text) ? $text : null;

                if ($type === 'URL') {
                    $paramMap = $buttonParamValues['url'][$index] ?? [];
                    if (empty($paramMap)) {
                        continue;
                    }

                    ksort($paramMap);

                    $params = [];
                    foreach ($paramMap as $value) {
                        $params[] = [
                            'type' => 'static',
                            'value' => $value,
                        ];
                    }

                    $buttonsMeta[$index] = [
                        'type' => 'URL',
                        'text' => $text,
                        'parameters' => $params,
                    ];
                }

                if ($type === 'COPY_CODE') {
                    $paramMap = $buttonParamValues['copy_code'][$index] ?? [];
                    if (empty($paramMap)) {
                        continue;
                    }

                    ksort($paramMap);

                    $params = [];
                    foreach ($paramMap as $value) {
                        $params[] = [
                            'type' => 'static',
                            'value' => $value,
                        ];
                    }

                    $buttonsMeta[$index] = [
                        'type' => 'COPY_CODE',
                        'text' => $text,
                        'parameters' => $params,
                    ];
                }
            }
        }

        return $buttonsMeta;
    }

    /**
     * Index mappings by `template_variable`.
     *
     * Supports different mapping models as long as they expose:
     * - template_variable
     * - payload_path
     * - transform (nullable)
     * - default_value (nullable)
     *
     * @param  iterable<int, mixed>  $mappings
     * @return array<string, mixed>
     */
    private function indexMappings(iterable $mappings): array
    {
        if ($mappings instanceof Collection) {
            return $mappings->keyBy('template_variable')->all();
        }

        $indexed = [];
        foreach ($mappings as $mapping) {
            $key = data_get($mapping, 'template_variable');
            if (is_string($key) && $key !== '') {
                $indexed[$key] = $mapping;
            }
        }

        return $indexed;
    }

    private function resolveMappedValue(mixed $mapping, array $payload): string
    {
        $payloadPath = data_get($mapping, 'payload_path');
        $payloadPath = is_string($payloadPath) ? $payloadPath : null;

        $value = $payloadPath ? data_get($payload, $payloadPath) : null;

        if ($value === null || (is_string($value) && trim($value) === '')) {
            $value = data_get($mapping, 'default_value');
        }

        $rawTransform = data_get($mapping, 'transform');
        $transform = is_scalar($rawTransform) ? strtolower(trim((string) $rawTransform)) : null;

        return $this->applyTransform($value, $transform);
    }

    private function applyTransform(mixed $value, ?string $transform): string
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $string = $value === null ? '' : (string) $value;

        if (!$transform) {
            return $string;
        }

        return match ($transform) {
            'trim' => trim($string),
            'lower' => mb_strtolower($string),
            'upper' => mb_strtoupper($string),
            'number' => is_numeric($string) ? (string) (0 + $string) : $string,
            'money' => is_numeric($string) ? number_format((float) $string, 2, '.', '') : $string,
            'phone_e164' => PhoneService::getE164Format($string) ?? $string,
            default => $string,
        };
    }
}
