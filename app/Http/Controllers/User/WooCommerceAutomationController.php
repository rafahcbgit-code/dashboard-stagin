<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Template;
use App\Models\WooCommerceWebhookDelivery;
use App\Models\WooCommerceWebhookEventSetting;
use App\Services\Salla\Webhooks\WhatsAppTemplateVariableExtractor;
use App\Services\WooCommerce\Webhooks\Schemas\WooCommerceAddToCartFieldSchema;
use App\Services\WooCommerce\Webhooks\Schemas\WooCommerceOrderCreatedFieldSchema;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WooCommerceAutomationController extends BaseController
{
    private const ORDER_UPDATED_STATUSES = [
        'pending',
        'processing',
        'on-hold',
        'completed',
        'cancelled',
        'refunded',
        'failed',
    ];

    public function index(
        WooCommerceOrderCreatedFieldSchema $schema,
        WooCommerceAddToCartFieldSchema $addToCartSchema,
        WhatsAppTemplateVariableExtractor $extractor
    ) {
        $organizationId = (int) session()->get('current_organization');

        $setting = WooCommerceWebhookEventSetting::with('mappings')
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

        $templateVariables = $selectedTemplate ? $extractor->extract($selectedTemplate) : [];

        $lastDelivery = WooCommerceWebhookDelivery::where('organization_id', $organizationId)
            ->where('topic', 'order.created')
            ->latest('id')
            ->first();

        $webhookFields = collect($schema->fields())
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

        $statusConfigs = $this->buildOrderUpdatedStatusConfigs($organizationId);
        $addToCartSetting = WooCommerceWebhookEventSetting::with('mappings')
            ->where('organization_id', $organizationId)
            ->where('event', 'action.woocommerce_add_to_cart')
            ->first();

        $addToCartSelectedTemplate = null;
        if ($addToCartSetting?->template_id) {
            $addToCartSelectedTemplate = Template::where('id', $addToCartSetting->template_id)
                ->where('organization_id', $organizationId)
                ->where('status', 'APPROVED')
                ->whereNull('deleted_at')
                ->first();
        }

        $addToCartTemplateVariables = $addToCartSelectedTemplate ? $extractor->extract($addToCartSelectedTemplate) : [];

        $lastAddToCartDelivery = WooCommerceWebhookDelivery::where('organization_id', $organizationId)
            ->where('topic', 'action.woocommerce_add_to_cart')
            ->latest('id')
            ->first();

        $addToCartWebhookFields = collect($addToCartSchema->fields())
            ->map(function (array $field) use ($lastAddToCartDelivery) {
                $payload = $this->normalizeStoredPayload($lastAddToCartDelivery?->payload ?? []);
                $field['sample'] = $lastAddToCartDelivery ? data_get($payload, $field['path']) : null;
                return $field;
            })
            ->values()
            ->all();

        $addToCartMappings = [];
        if ($addToCartSetting) {
            foreach ($addToCartSetting->mappings as $mapping) {
                $addToCartMappings[$mapping->template_variable] = $mapping->payload_path;
            }
        }

        return Inertia::render('User/Settings/WooCommerceAutomations', [
            'title' => __('WooCommerce Automations'),
            'templates' => $templates,
            'setting' => [
                'enabled' => (bool) ($setting?->enabled ?? false),
                'template_id' => $setting?->template_id,
                'recipient_phone_payload_path' => $setting?->recipient_phone_payload_path ?? 'billing.phone',
            ],
            'templateVariables' => $templateVariables,
            'webhookFields' => $webhookFields,
            'mappings' => $mappings,
            'orderUpdatedStatuses' => self::ORDER_UPDATED_STATUSES,
            'orderUpdatedStatusConfigs' => $statusConfigs,
            'addToCartSetting' => [
                'enabled' => (bool) ($addToCartSetting?->enabled ?? false),
                'template_id' => $addToCartSetting?->template_id,
                'recipient_phone_payload_path' => $addToCartSetting?->recipient_phone_payload_path ?? 'chatberry_add_to_cart.customer.billing.phone',
                'delay_hours' => (int) ($addToCartSetting?->delay_hours ?? 0),
            ],
            'addToCartTemplateVariables' => $addToCartTemplateVariables,
            'addToCartWebhookFields' => $addToCartWebhookFields,
            'addToCartMappings' => $addToCartMappings,
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

    public function webhookFields(WooCommerceOrderCreatedFieldSchema $schema)
    {
        $organizationId = (int) session()->get('current_organization');

        $lastDelivery = WooCommerceWebhookDelivery::where('organization_id', $organizationId)
            ->where('topic', 'order.created')
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

    public function webhookFieldsOrderUpdated(string $status, WooCommerceOrderCreatedFieldSchema $schema)
    {
        if (!in_array($status, self::ORDER_UPDATED_STATUSES, true)) {
            abort(404);
        }

        $organizationId = (int) session()->get('current_organization');

        $lastDelivery = WooCommerceWebhookDelivery::where('organization_id', $organizationId)
            ->where('topic', 'order.updated')
            ->whereJsonContains('payload->status', $status)
            ->latest('id')
            ->first();

        if (!$lastDelivery) {
            $lastDelivery = WooCommerceWebhookDelivery::where('organization_id', $organizationId)
                ->where('topic', 'action.woocommerce_order_status_changed')
                ->whereJsonContains('payload->status', $status)
                ->latest('id')
                ->first();
        }

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

    public function webhookFieldsAddToCart(WooCommerceAddToCartFieldSchema $schema)
    {
        $organizationId = (int) session()->get('current_organization');

        $lastDelivery = WooCommerceWebhookDelivery::where('organization_id', $organizationId)
            ->where('topic', 'action.woocommerce_add_to_cart')
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

    public function orderUpdatedStatusConfig(
        string $status,
        WooCommerceOrderCreatedFieldSchema $schema,
        WhatsAppTemplateVariableExtractor $extractor
    ) {
        if (!in_array($status, self::ORDER_UPDATED_STATUSES, true)) {
            abort(404);
        }

        $organizationId = (int) session()->get('current_organization');
        $eventKey = $this->orderUpdatedEventKey($status);

        $setting = WooCommerceWebhookEventSetting::with('mappings')
            ->where('organization_id', $organizationId)
            ->where('event', $eventKey)
            ->first();

        $selectedTemplate = null;
        if ($setting?->template_id) {
            $selectedTemplate = Template::where('id', $setting->template_id)
                ->where('organization_id', $organizationId)
                ->where('status', 'APPROVED')
                ->whereNull('deleted_at')
                ->first();
        }

        $templateVariables = $selectedTemplate ? $extractor->extract($selectedTemplate) : [];

        $lastDelivery = WooCommerceWebhookDelivery::where('organization_id', $organizationId)
            ->where('topic', 'order.updated')
            ->whereJsonContains('payload->status', $status)
            ->latest('id')
            ->first();

        if (!$lastDelivery) {
            $lastDelivery = WooCommerceWebhookDelivery::where('organization_id', $organizationId)
                ->where('topic', 'action.woocommerce_order_status_changed')
                ->whereJsonContains('payload->status', $status)
                ->latest('id')
                ->first();
        }

        $fields = collect($schema->fields())
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

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $status,
                'setting' => [
                    'enabled' => (bool) ($setting?->enabled ?? false),
                    'template_id' => $setting?->template_id,
                    'recipient_phone_payload_path' => $setting?->recipient_phone_payload_path ?? 'billing.phone',
                ],
                'templateVariables' => $templateVariables,
                'webhookFields' => $fields,
                'mappings' => $mappings,
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

        $setting = WooCommerceWebhookEventSetting::updateOrCreate(
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

    public function updateOrderUpdated(string $status, Request $request, WhatsAppTemplateVariableExtractor $extractor)
    {
        if (!in_array($status, self::ORDER_UPDATED_STATUSES, true)) {
            abort(404);
        }

        $organizationId = (int) session()->get('current_organization');

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'template_id' => ['nullable', 'integer'],
            'recipient_phone_payload_path' => ['nullable', 'string', 'max:512'],
            'quick_rule' => ['nullable', 'boolean'],
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

        $quickRule = (bool) ($validated['quick_rule'] ?? false);

        $setting = WooCommerceWebhookEventSetting::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'event' => $this->orderUpdatedEventKey($status),
            ],
            [
                'enabled' => (bool) $validated['enabled'],
                'template_id' => $templateId,
                'recipient_phone_payload_path' => $validated['recipient_phone_payload_path'] ?? null,
            ]
        );

        if ($quickRule) {
            return response()->json([
                'success' => true,
            ]);
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

    public function updateAddToCart(Request $request, WhatsAppTemplateVariableExtractor $extractor)
    {
        $organizationId = (int) session()->get('current_organization');

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'template_id' => ['nullable', 'integer'],
            'recipient_phone_payload_path' => ['nullable', 'string', 'max:512'],
            'delay_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
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

        $setting = WooCommerceWebhookEventSetting::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'event' => 'action.woocommerce_add_to_cart',
            ],
            [
                'enabled' => (bool) $validated['enabled'],
                'template_id' => $templateId,
                'recipient_phone_payload_path' => $validated['recipient_phone_payload_path'] ?? null,
                'delay_hours' => (int) ($validated['delay_hours'] ?? 0),
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

    private function orderUpdatedEventKey(string $status): string
    {
        return 'order.updated.status.' . $status;
    }

    private function buildOrderUpdatedStatusConfigs(int $organizationId): array
    {
        $eventKeys = array_map(fn ($status) => $this->orderUpdatedEventKey($status), self::ORDER_UPDATED_STATUSES);

        $settings = WooCommerceWebhookEventSetting::where('organization_id', $organizationId)
            ->whereIn('event', $eventKeys)
            ->get()
            ->keyBy('event');

        $result = [];
        foreach (self::ORDER_UPDATED_STATUSES as $status) {
            $setting = $settings->get($this->orderUpdatedEventKey($status));
            $result[$status] = [
                'enabled' => (bool) ($setting?->enabled ?? false),
                'template_id' => $setting?->template_id,
                'recipient_phone_payload_path' => $setting?->recipient_phone_payload_path ?? 'billing.phone',
            ];
        }

        return $result;
    }
}
