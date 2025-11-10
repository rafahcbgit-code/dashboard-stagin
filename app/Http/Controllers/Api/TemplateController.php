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
    public function index(Request $request){
        try {
            $organizationId = $request->organization_id;

            // Get all approved templates
            $templates = Template::where('organization_id', $organizationId)
                ->where('status', 'APPROVED')
                ->whereNull('deleted_at')
                ->orderBy('name', 'asc')
                ->get()
                ->map(function($template) {
                    try {
                        $metadata = @json_decode($template->metadata, true);
                        
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            \Log::warning("Template {$template->uuid} has corrupt metadata: " . json_last_error_msg());
                            
                            return [
                                'uuid' => $template->uuid,
                                'name' => $template->name,
                                'category' => $template->category,
                                'language' => $template->language,
                                'status' => $template->status,
                                'has_header' => false,
                                'header_format' => null,
                                'body_preview' => 'Template data unavailable',
                                'footer' => null,
                                'button_count' => 0,
                                'parameter_count' => 0,
                                'created_at' => $template->created_at,
                                'is_corrupt' => true
                            ];
                        }
                        
                        $components = $metadata['components'] ?? [];

                        // Extract header info
                        $headerInfo = $this->extractHeaderInfo($components);
                        
                        $bodyPreview = $this->extractBodyPreview($components);
                        $bodyPreview = $this->cleanUtf8($bodyPreview);
                        
                        $footer = $this->extractFooter($components);
                        $footer = $this->cleanUtf8($footer);
                        
                        // Count buttons
                        $buttonCount = $this->countButtons($components);
                        
                        // Count parameters
                        $parameterCount = $this->countParameters($components);

                        return [
                            'uuid' => $template->uuid,
                            'name' => $this->cleanUtf8($template->name),
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
                    } catch (\Exception $e) {
                        \Log::error("Error processing template {$template->uuid}: " . $e->getMessage());
                        
                        return [
                            'uuid' => $template->uuid,
                            'name' => $template->name,
                            'category' => $template->category,
                            'language' => $template->language,
                            'status' => $template->status,
                            'has_header' => false,
                            'header_format' => null,
                            'body_preview' => 'Error loading template',
                            'footer' => null,
                            'button_count' => 0,
                            'parameter_count' => 0,
                            'created_at' => $template->created_at,
                            'is_corrupt' => true
                        ];
                    }
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'templates' => $templates->values()
                ]
            ], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch templates',
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    /**
     * Get template details with full structure
     * GET /api/v1/templates/{uuid}
     */
    public function show(Request $request, $uuid){
        try {
            $organizationId = $request->organization_id;

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

            $metadata = @json_decode($template->metadata, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    'success' => false,
                    'message' => 'Template data is corrupt or unavailable',
                    'error' => 'JSON decode error: ' . json_last_error_msg()
                ], 500);
            }

            $components = $metadata['components'] ?? [];

            $templateStructure = [
                'uuid' => $template->uuid,
                'name' => $this->cleanUtf8($template->name), // Clean UTF-8
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

            // Use JSON_UNESCAPED_UNICODE
            return response()->json([
                'success' => true,
                'data' => [
                    'template' => $templateStructure
                ]
            ], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch template details',
                'error' => $e->getMessage(),
                'line' => $e->getLine()
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
                $text = $this->cleanUtf8($component['text'] ?? ''); //  Clean UTF-8
                return mb_strlen($text) > 100 ? mb_substr($text, 0, 100) . '...' : $text;
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
                return $this->cleanUtf8($component['text'] ?? null); // Clean UTF-8
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
                    $header['text'] = $this->cleanUtf8($component['text']);
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
                    'text' => $this->cleanUtf8($component['text'] ?? ''),
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
                    'text' => $this->cleanUtf8($component['text'] ?? null)
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
                        'text' => $this->cleanUtf8($button['text'] ?? null), // Clean UTF-8
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

    /**
     * Clean UTF-8 string to prevent encoding issues
     */
    private function cleanUtf8($string)
    {
        if ($string === null) {
            return null;
        }
        
        // Remove any invalid UTF-8 sequences
        $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
        
        // Remove any non-UTF-8 characters
        $string = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', '', $string);
        
        return $string;
    }
}