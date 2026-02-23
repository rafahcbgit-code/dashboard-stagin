<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Models\SallaWebhookDelivery;
use App\Models\SallaWebhookEventSetting;
use App\Models\Template;
use App\Services\Salla\Webhooks\Schemas\SallaOrderCreatedFieldSchema;
use App\Services\Salla\Webhooks\WhatsAppTemplateVariableExtractor;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SallaAutomationController extends BaseController
{
    public function index(
        SallaOrderCreatedFieldSchema $schema,
        WhatsAppTemplateVariableExtractor $variableExtractor
    ) {
        $organizationId = (int) session()->get('current_organization');

        $setting = SallaWebhookEventSetting::with('mappings')
            ->where('organization_id', $organizationId)
            ->where('event', 'order.created')
            ->first();

        $selectedTemplate = null;
        if ($setting?->template_id) {
            $selectedTemplate = Template::where('id', $setting->template_id)
                ->where('organization_id', $organizationId)
                ->where('status', 'APPROVED')
                ->whereNull('deleted_at')
                ->first();
        }

        $templates = Template::where('organization_id', $organizationId)
            ->where('status', 'APPROVED')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])
            ->values();

        $templateVariables = $selectedTemplate ? $variableExtractor->extract($selectedTemplate) : [];

        $lastDelivery = SallaWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'order.created')
            ->latest('id')
            ->first();

        $webhookFields = collect($schema->fields())
            ->map(function (array $field) use ($lastDelivery) {
                $field['sample'] = $lastDelivery ? data_get($lastDelivery->payload ?? [], $field['path']) : null;
                return $field;
            })
            ->values()
            ->all();

        $mappings = [];
        if ($setting) {
            foreach ($setting->mappings as $mapping) {
                $mappings[$mapping->template_variable] = $mapping->payload_path;
            }
        }

        return Inertia::render('User/Settings/SallaAutomations', [
            'title' => __('Salla Automations'),
            'templates' => $templates,
            'setting' => [
                'enabled' => (bool) ($setting?->enabled ?? false),
                'template_id' => $setting?->template_id,
                'recipient_phone_payload_path' => $setting?->recipient_phone_payload_path ?? 'data.customer.mobile_code+data.customer.mobile',
            ],
            'templateVariables' => $templateVariables,
            'webhookFields' => $webhookFields,
            'mappings' => $mappings,
        ]);
    }

    public function templateVariables(int $templateId, WhatsAppTemplateVariableExtractor $extractor)
    {
        $organizationId = (int) session()->get('current_organization');

        $template = Template::where('id', $templateId)
            ->where('organization_id', $organizationId)
            ->where('status', 'APPROVED')
            ->whereNull('deleted_at')
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => [
                'variables' => $extractor->extract($template),
            ],
        ]);
    }

    public function webhookFields(SallaOrderCreatedFieldSchema $schema)
    {
        $organizationId = (int) session()->get('current_organization');

        $lastDelivery = SallaWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'order.created')
            ->latest('id')
            ->first();

        $fields = collect($schema->fields())
            ->map(function (array $field) use ($lastDelivery) {
                $field['sample'] = $lastDelivery ? data_get($lastDelivery->payload ?? [], $field['path']) : null;
                return $field;
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'fields' => $fields,
            ],
        ]);
    }

    public function updateOrderCreated(Request $request, WhatsAppTemplateVariableExtractor $extractor)
    {
        $organizationId = (int) session()->get('current_organization');

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'template_id' => ['nullable', 'integer'],
            'recipient_phone_payload_path' => ['nullable', 'string', 'max:512'],
            'mappings' => ['array'],
            'mappings.*.template_variable' => ['required', 'string', 'max:191'],
            'mappings.*.payload_path' => ['required', 'string', 'max:512'],
            'mappings.*.transform' => ['nullable', 'string', 'max:64'],
            'mappings.*.default_value' => ['nullable', 'string', 'max:255'],
        ]);

        $templateId = $validated['template_id'] ?? null;
        $template = null;
        if ($templateId) {
            $template = Template::where('id', $templateId)
                ->where('organization_id', $organizationId)
                ->where('status', 'APPROVED')
                ->whereNull('deleted_at')
                ->firstOrFail();
        }

        $requiredKeys = [];
        if ($template) {
            $requiredKeys = array_values(array_unique(array_map(
                static fn (array $v) => $v['key'],
                $extractor->extract($template)
            )));
        }

        $mappings = $validated['mappings'] ?? [];
        $mappingKeySet = [];
        foreach ($mappings as $mapping) {
            $mappingKeySet[$mapping['template_variable']] = true;
        }

        foreach ($requiredKeys as $key) {
            if (!isset($mappingKeySet[$key])) {
                return response()->json([
                    'success' => false,
                    'message' => __('Missing mapping for :key', ['key' => $key]),
                ], 422);
            }
        }

        $setting = SallaWebhookEventSetting::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'event' => 'order.created',
            ],
            [
                'enabled' => (bool) $validated['enabled'],
                'template_id' => $templateId,
                'recipient_phone_payload_path' => $validated['recipient_phone_payload_path'] ?? null,
            ]
        );

        $setting->mappings()->delete();

        foreach ($mappings as $mapping) {
            $setting->mappings()->create([
                'template_variable' => $mapping['template_variable'],
                'payload_path' => $mapping['payload_path'],
                'transform' => $mapping['transform'] ?? null,
                'default_value' => $mapping['default_value'] ?? null,
            ]);
        }

        return response()->json([
            'success' => true,
        ]);
    }
}
