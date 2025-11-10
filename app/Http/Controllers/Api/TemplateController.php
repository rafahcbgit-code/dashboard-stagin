<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Template;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    /**
     * Get all approved templates for organization
     * GET /api/v1/templates
     */
    public function index(Request $request)
    {
        try {
            $organizationId = $request->organization_id;

            // Get all approved templates
            $templates = Template::where('organization_id', $organizationId)
                ->where('status', 'APPROVED')
                ->whereNull('deleted_at')
                ->orderBy('name', 'asc')
                ->get()
                ->map(function($template) {
                    $metadata = json_decode($template->metadata, true);
                    $components = $metadata['components'] ?? [];

                    // Extract header info
                    $headerInfo = $this->extractHeaderInfo($components);
                    
                    // Extract body preview
                    $bodyPreview = $this->extractBodyPreview($components);
                    
                    // Extract footer
                    $footer = $this->extractFooter($components);
                    
                    // Count buttons
                    $buttonCount = $this->countButtons($components);
                    
                    // Count parameters
                    $parameterCount = $this->countParameters($components);

                    return [
                        'uuid' => $template->uuid,
                        'name' => $template->name,
                        'category' => $template->category,
                        'language' => $template->language,
                        'status' => $template->status,
                        'has_header' => $headerInfo['has_header'],
                        'header_format' => $headerInfo['format'],
                        'body_preview' => $bodyPreview,
                        'footer' => $footer,
                        'button_count' => $buttonCount,
                        'parameter_count' => $parameterCount,
                        'created_at' => $template->created_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'templates' => $templates
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch templates',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get template details with full structure
     * GET /api/v1/templates/{uuid}
     */
    public function show(Request $request, $uuid)
    {
        try {
            $organizationId = $request->organization_id;

            // Find template
            $template = Template::where('uuid', $uuid)
                ->where('organization_id', $organizationId)
                ->where('status', 'APPROVED')
                ->whereNull('deleted_at')
                ->first();

            if (!$template) {
                return response()->json([
                    'success' => false,
                    'message' => 'Template not found or not approved'
                ], 404);
            }

            $metadata = json_decode($template->metadata, true);
            $components = $metadata['components'] ?? [];

            // Build detailed component structure
            $templateStructure = [
                'uuid' => $template->uuid,
                'name' => $template->name,
                'category' => $template->category,
                'language' => $template->language,
                'status' => $template->status,
                'components' => [
                    'header' => $this->buildHeaderComponent($components),
                    'body' => $this->buildBodyComponent($components),
                    'footer' => $this->buildFooterComponent($components),
                    'buttons' => $this->buildButtonsComponent($components),
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'template' => $templateStructure
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch template details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Extract header information
     */
    private function extractHeaderInfo($components)
    {
        foreach ($components as $component) {
            if ($component['type'] === 'HEADER') {
                return [
                    'has_header' => true,
                    'format' => $component['format'] ?? 'TEXT'
                ];
            }
        }

        return [
            'has_header' => false,
            'format' => null
        ];
    }

    /**
     * Extract body preview (first 100 chars)
     */
    private function extractBodyPreview($components)
    {
        foreach ($components as $component) {
            if ($component['type'] === 'BODY') {
                $text = $component['text'] ?? '';
                return strlen($text) > 100 ? substr($text, 0, 100) . '...' : $text;
            }
        }

        return null;
    }

    /**
     * Extract footer text
     */
    private function extractFooter($components)
    {
        foreach ($components as $component) {
            if ($component['type'] === 'FOOTER') {
                return $component['text'] ?? null;
            }
        }

        return null;
    }

    /**
     * Count buttons
     */
    private function countButtons($components)
    {
        foreach ($components as $component) {
            if ($component['type'] === 'BUTTONS') {
                return count($component['buttons'] ?? []);
            }
        }

        return 0;
    }

    /**
     * Count total parameters in header and body
     */
    private function countParameters($components)
    {
        $count = 0;

        foreach ($components as $component) {
            if ($component['type'] === 'HEADER' && isset($component['text'])) {
                $count += preg_match_all('/\{\{(\d+)\}\}/', $component['text']);
            }
            
            if ($component['type'] === 'BODY' && isset($component['text'])) {
                $count += preg_match_all('/\{\{(\d+)\}\}/', $component['text']);
            }
        }

        return $count;
    }

    /**
     * Build detailed header component
     */
    private function buildHeaderComponent($components)
    {
        foreach ($components as $component) {
            if ($component['type'] === 'HEADER') {
                $header = [
                    'format' => $component['format'] ?? 'TEXT',
                ];

                if (isset($component['text'])) {
                    $header['text'] = $component['text'];
                    $header['parameters'] = $this->extractParameters($component['text'], $component['example'] ?? null);
                }

                if (isset($component['example'])) {
                    $header['example'] = $component['example'];
                }

                return $header;
            }
        }

        return null;
    }

    /**
     * Build detailed body component
     */
    private function buildBodyComponent($components)
    {
        foreach ($components as $component) {
            if ($component['type'] === 'BODY') {
                return [
                    'text' => $component['text'] ?? '',
                    'parameters' => $this->extractParameters($component['text'] ?? '', $component['example'] ?? null),
                ];
            }
        }

        return null;
    }

    /**
     * Build footer component
     */
    private function buildFooterComponent($components)
    {
        foreach ($components as $component) {
            if ($component['type'] === 'FOOTER') {
                return [
                    'text' => $component['text'] ?? null
                ];
            }
        }

        return null;
    }

    /**
     * Build buttons component
     */
    private function buildButtonsComponent($components)
    {
        foreach ($components as $component) {
            if ($component['type'] === 'BUTTONS') {
                return array_map(function($button) {
                    $buttonData = [
                        'type' => $button['type'],
                        'text' => $button['text'] ?? null,
                    ];

                    if (isset($button['url'])) {
                        $buttonData['url'] = $button['url'];
                    }

                    if (isset($button['phone_number'])) {
                        $buttonData['phone_number'] = $button['phone_number'];
                    }

                    return $buttonData;
                }, $component['buttons'] ?? []);
            }
        }

        return [];
    }

    /**
     * Extract parameters from text
     */
    private function extractParameters($text, $examples = null)
    {
        $parameters = [];
        
        // Find all {{1}}, {{2}}, etc.
        preg_match_all('/\{\{(\d+)\}\}/', $text, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $index => $number) {
                $parameters[] = [
                    'index' => (int)$number,
                    'placeholder' => '{{' . $number . '}}',
                    'example' => is_array($examples) && isset($examples[$index]) ? $examples[$index] : null
                ];
            }
        }

        return $parameters;
    }
}