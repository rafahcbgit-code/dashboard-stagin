<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Template;
use App\Models\ZidWebhookDelivery;
use App\Models\ZidWebhookEventSetting;
use App\Services\Salla\Webhooks\WhatsAppTemplateVariableExtractor;
use App\Services\Zid\Webhooks\Schemas\ZidAbandonedCartFieldSchema;
use App\Services\Zid\Webhooks\Schemas\ZidCustomerLoginFieldSchema;
use App\Services\Zid\Webhooks\Schemas\ZidOrderCreatedFieldSchema;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ZidAutomationController extends BaseController
{
    public function index(
        WhatsAppTemplateVariableExtractor $variableExtractor,
        ZidCustomerLoginFieldSchema $customerSchema,
        ZidOrderCreatedFieldSchema $orderSchema,
        ZidAbandonedCartFieldSchema $abandonedSchema
    ) {
        $organizationId = (int) session()->get('current_organization');

        $setting = ZidWebhookEventSetting::with('mappings')
            ->where('organization_id', $organizationId)
            ->where('event', 'customer.login')
            ->first();

        $templates = Template::where('organization_id', $organizationId)
            ->where('status', 'APPROVED')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])
            ->values();

        $selectedTemplate = null;
        if ($setting?->template_id) {
            $selectedTemplate = Template::where('id', $setting->template_id)
                ->where('organization_id', $organizationId)
                ->where('status', 'APPROVED')
                ->whereNull('deleted_at')
                ->first();
        }

        $templateVariables = $selectedTemplate ? $variableExtractor->extract($selectedTemplate) : [];

        $lastDelivery = ZidWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'customer.login')
            ->latest('id')
            ->first();

        $webhookFields = collect($customerSchema->fields())
            ->map(function (array $field) use ($lastDelivery) {
                $payload = $this->normalizeStoredPayload($lastDelivery?->payload ?? []);
                $field['sample'] = $lastDelivery ? data_get($payload, $field['path']) : null;
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

        $orderSetting = ZidWebhookEventSetting::with('mappings')
            ->where('organization_id', $organizationId)
            ->where('event', 'order.create')
            ->first();

        $orderSelectedTemplate = null;
        if ($orderSetting?->template_id) {
            $orderSelectedTemplate = Template::where('id', $orderSetting->template_id)
                ->where('organization_id', $organizationId)
                ->where('status', 'APPROVED')
                ->whereNull('deleted_at')
                ->first();
        }

        $orderTemplateVariables = $orderSelectedTemplate ? $variableExtractor->extract($orderSelectedTemplate) : [];

        $lastOrderDelivery = ZidWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'order.create')
            ->latest('id')
            ->first();

        $orderWebhookFields = collect($orderSchema->fields())
            ->map(function (array $field) use ($lastOrderDelivery) {
                $payload = $this->normalizeStoredPayload($lastOrderDelivery?->payload ?? []);
                $field['sample'] = $lastOrderDelivery ? data_get($payload, $field['path']) : null;
                return $field;
            })
            ->values()
            ->all();

        $orderMappings = [];
        if ($orderSetting) {
            foreach ($orderSetting->mappings as $mapping) {
                $orderMappings[$mapping->template_variable] = $mapping->payload_path;
            }
        }

        $abandonedSetting = ZidWebhookEventSetting::with('mappings')
            ->where('organization_id', $organizationId)
            ->where('event', 'abandoned_cart.created')
            ->first();

        $abandonedSelectedTemplate = null;
        if ($abandonedSetting?->template_id) {
            $abandonedSelectedTemplate = Template::where('id', $abandonedSetting->template_id)
                ->where('organization_id', $organizationId)
                ->where('status', 'APPROVED')
                ->whereNull('deleted_at')
                ->first();
        }

        $abandonedTemplateVariables = $abandonedSelectedTemplate ? $variableExtractor->extract($abandonedSelectedTemplate) : [];

        $lastAbandonedDelivery = ZidWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'abandoned_cart.created')
            ->latest('id')
            ->first();

        $abandonedWebhookFields = collect($abandonedSchema->fields())
            ->map(function (array $field) use ($lastAbandonedDelivery) {
                $payload = $this->normalizeStoredPayload($lastAbandonedDelivery?->payload ?? []);
                $field['sample'] = $lastAbandonedDelivery ? data_get($payload, $field['path']) : null;
                return $field;
            })
            ->values()
            ->all();

        $abandonedMappings = [];
        if ($abandonedSetting) {
            foreach ($abandonedSetting->mappings as $mapping) {
                $abandonedMappings[$mapping->template_variable] = $mapping->payload_path;
            }
        }

        return Inertia::render('User/Settings/ZidAutomations', [
            'title' => __('Zid Automations'),
            'templates' => $templates,
            'customerLoginSetting' => [
                'enabled' => (bool) ($setting?->enabled ?? false),
                'template_id' => $setting?->template_id,
                'recipient_phone_payload_path' => $setting?->recipient_phone_payload_path ?? 'telephone',
            ],
            'customerLoginTemplateVariables' => $templateVariables,
            'customerLoginWebhookFields' => $webhookFields,
            'customerLoginMappings' => $mappings,
            'orderCreatedSetting' => [
                'enabled' => (bool) ($orderSetting?->enabled ?? false),
                'template_id' => $orderSetting?->template_id,
                'recipient_phone_payload_path' => $orderSetting?->recipient_phone_payload_path ?? 'customer.mobile',
            ],
            'orderCreatedTemplateVariables' => $orderTemplateVariables,
            'orderCreatedWebhookFields' => $orderWebhookFields,
            'orderCreatedMappings' => $orderMappings,
            'abandonedSetting' => [
                'enabled' => (bool) ($abandonedSetting?->enabled ?? false),
                'template_id' => $abandonedSetting?->template_id,
                'recipient_phone_payload_path' => $abandonedSetting?->recipient_phone_payload_path ?? 'customer_mobile',
                'send_delay_hours' => $abandonedSetting?->send_delay_hours,
            ],
            'abandonedTemplateVariables' => $abandonedTemplateVariables,
            'abandonedWebhookFields' => $abandonedWebhookFields,
            'abandonedMappings' => $abandonedMappings,
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

    public function webhookFieldsCustomerLogin(ZidCustomerLoginFieldSchema $schema)
    {
        $organizationId = (int) session()->get('current_organization');

        $lastDelivery = ZidWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'customer.login')
            ->latest('id')
            ->first();

        $fields = collect($schema->fields())
            ->map(function (array $field) use ($lastDelivery) {
                $payload = $this->normalizeStoredPayload($lastDelivery?->payload ?? []);
                $field['sample'] = $lastDelivery ? data_get($payload, $field['path']) : null;
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

    public function webhookFieldsOrderCreated(ZidOrderCreatedFieldSchema $schema)
    {
        $organizationId = (int) session()->get('current_organization');

        $lastDelivery = ZidWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'order.create')
            ->latest('id')
            ->first();

        $fields = collect($schema->fields())
            ->map(function (array $field) use ($lastDelivery) {
                $payload = $this->normalizeStoredPayload($lastDelivery?->payload ?? []);
                $field['sample'] = $lastDelivery ? data_get($payload, $field['path']) : null;
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

    public function webhookFieldsAbandonedCart(ZidAbandonedCartFieldSchema $schema)
    {
        $organizationId = (int) session()->get('current_organization');

        $lastDelivery = ZidWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'abandoned_cart.created')
            ->latest('id')
            ->first();

        $fields = collect($schema->fields())
            ->map(function (array $field) use ($lastDelivery) {
                $payload = $this->normalizeStoredPayload($lastDelivery?->payload ?? []);
                $field['sample'] = $lastDelivery ? data_get($payload, $field['path']) : null;
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

    public function updateCustomerLogin(Request $request, WhatsAppTemplateVariableExtractor $extractor)
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

        $setting = ZidWebhookEventSetting::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'event' => 'customer.login',
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

        $setting = ZidWebhookEventSetting::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'event' => 'order.create',
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

    public function updateAbandonedCart(Request $request, WhatsAppTemplateVariableExtractor $extractor)
    {
        $organizationId = (int) session()->get('current_organization');

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'template_id' => ['nullable', 'integer'],
            'recipient_phone_payload_path' => ['nullable', 'string', 'max:512'],
            'send_delay_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
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

        $setting = ZidWebhookEventSetting::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'event' => 'abandoned_cart.created',
            ],
            [
                'enabled' => (bool) $validated['enabled'],
                'template_id' => $templateId,
                'recipient_phone_payload_path' => $validated['recipient_phone_payload_path'] ?? null,
                'send_delay_hours' => $validated['send_delay_hours'] ?? null,
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

    private function normalizeStoredPayload(array $payload): array
    {
        if (isset($payload['payload']) && is_array($payload['payload'])) {
            return $payload['payload'];
        }

        return $payload;
    }
}
