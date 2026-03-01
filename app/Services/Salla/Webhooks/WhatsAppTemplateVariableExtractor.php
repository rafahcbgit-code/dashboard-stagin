<?php

namespace App\Services\Salla\Webhooks;

use App\Models\Template;

class WhatsAppTemplateVariableExtractor
{
    /**
     * Extract variable slots from a WhatsApp template definition.
     *
     * Returned keys are stable identifiers suitable for mapping storage:
     * - header.{n}
     * - body.{n}
     * - button.url.{buttonIndex}.{n}
     * - button.copy_code.{buttonIndex}.{n}
     *
     * @return array<int, array{key:string, component:string, index:int, placeholder:string}>
     */
    public function extract(Template $template): array
    {
        $metadata = json_decode((string) $template->metadata, true);
        if (!is_array($metadata)) {
            return [];
        }

        $components = $metadata['components'] ?? [];
        if (!is_array($components)) {
            return [];
        }

        $variables = [];

        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }

            $type = strtoupper((string) ($component['type'] ?? ''));

            if ($type === 'HEADER' && isset($component['text']) && is_string($component['text'])) {
                foreach ($this->extractPlaceholderIndexes($component['text']) as $index) {
                    $variables[] = [
                        'key' => 'header.' . $index,
                        'component' => 'header',
                        'index' => $index,
                        'placeholder' => '{{' . $index . '}}',
                    ];
                }
            }

            if ($type === 'BODY' && isset($component['text']) && is_string($component['text'])) {
                foreach ($this->extractPlaceholderIndexes($component['text']) as $index) {
                    $variables[] = [
                        'key' => 'body.' . $index,
                        'component' => 'body',
                        'index' => $index,
                        'placeholder' => '{{' . $index . '}}',
                    ];
                }
            }

            if ($type === 'BUTTONS') {
                $buttons = $component['buttons'] ?? [];
                if (!is_array($buttons)) {
                    continue;
                }

                foreach ($buttons as $buttonIndex => $button) {
                    if (!is_array($button)) {
                        continue;
                    }

                    $buttonType = strtoupper((string) ($button['type'] ?? ''));

                    if ($buttonType === 'URL') {
                        $url = $button['url'] ?? null;
                        if (is_string($url)) {
                            foreach ($this->extractPlaceholderIndexes($url) as $index) {
                                $variables[] = [
                                    'key' => 'button.url.' . $buttonIndex . '.' . $index,
                                    'component' => 'button',
                                    'index' => $index,
                                    'placeholder' => '{{' . $index . '}}',
                                ];
                            }
                        }
                    }

                    if ($buttonType === 'COPY_CODE') {
                        // Copy code buttons require a coupon_code parameter when sending.
                        $variables[] = [
                            'key' => 'button.copy_code.' . $buttonIndex . '.1',
                            'component' => 'button',
                            'index' => 1,
                            'placeholder' => '{{1}}',
                        ];
                    }
                }
            }
        }

        return $variables;
    }

    /**
     * @return array<int, int>
     */
    private function extractPlaceholderIndexes(string $text): array
    {
        preg_match_all('/\{\{(\d+)\}\}/', $text, $matches);

        $indexes = array_map('intval', $matches[1] ?? []);
        $indexes = array_values(array_unique($indexes));
        sort($indexes);

        return $indexes;
    }
}
