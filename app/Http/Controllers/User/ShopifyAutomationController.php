<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Models\ShopifyWebhookDelivery;
use App\Models\ShopifyWebhookEventSetting;
use App\Models\Template;
use App\Services\Shopify\Webhooks\Schemas\ShopifyCheckoutCreatedFieldSchema;
use App\Services\Shopify\Webhooks\Schemas\ShopifyOrderCreatedFieldSchema;
use App\Services\Shopify\Webhooks\Schemas\ShopifyOrderFulfilledFieldSchema;
use App\Services\Salla\Webhooks\WhatsAppTemplateVariableExtractor;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ShopifyAutomationController extends BaseController
{
    public function index(
        ShopifyOrderCreatedFieldSchema $schema,
        ShopifyOrderFulfilledFieldSchema $fulfilledSchema,
        ShopifyCheckoutCreatedFieldSchema $checkoutSchema,
        WhatsAppTemplateVariableExtractor $extractor
    ) {
        $organizationId = (int) session()->get('current_organization');

        $setting = ShopifyWebhookEventSetting::with('mappings')
            ->where('organization_id', $organizationId)
            ->where('event', 'orders/create')
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

        $lastDelivery = ShopifyWebhookDelivery::where('organization_id', $organizationId)
            ->where('topic', 'orders/create')
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

        $checkoutSetting = ShopifyWebhookEventSetting::with('mappings')
            ->where('organization_id', $organizationId)
            ->where('event', 'checkouts/create')
            ->first();

        $checkoutSelectedTemplate = null;
        if ($checkoutSetting?->template_id) {
            $checkoutSelectedTemplate = Template::where('id', $checkoutSetting->template_id)
                ->where('organization_id', $organizationId)
                ->where('status', 'APPROVED')
                ->whereNull('deleted_at')
                ->first();
        }

        $checkoutTemplateVariables = $checkoutSelectedTemplate ? $extractor->extract($checkoutSelectedTemplate) : [];

        $lastCheckoutDelivery = ShopifyWebhookDelivery::where('organization_id', $organizationId)
            ->where('topic', 'checkouts/create')
            ->latest('id')
            ->first();

        $checkoutWebhookFields = collect($checkoutSchema->fields())
            ->map(function (array $field) use ($lastCheckoutDelivery) {
                $payload = $this->normalizeStoredPayload($lastCheckoutDelivery?->payload ?? []);
                $field['sample'] = $lastCheckoutDelivery ? data_get($payload, $field['path']) : null;
                return $field;
            })
            ->values()
            ->all();

        $checkoutMappings = [];
        if ($checkoutSetting) {
            foreach ($checkoutSetting->mappings as $mapping) {
                $checkoutMappings[$mapping->template_variable] = $mapping->payload_path;
            }
        }

        $fulfilledSetting = ShopifyWebhookEventSetting::with('mappings')
            ->where('organization_id', $organizationId)
            ->where('event', 'orders/fulfilled')
            ->first();

        $fulfilledSelectedTemplate = null;
        if ($fulfilledSetting?->template_id) {
            $fulfilledSelectedTemplate = Template::where('id', $fulfilledSetting->template_id)
                ->where('organization_id', $organizationId)
                ->where('status', 'APPROVED')
                ->whereNull('deleted_at')
                ->first();
        }

        $fulfilledTemplateVariables = $fulfilledSelectedTemplate ? $extractor->extract($fulfilledSelectedTemplate) : [];

        $lastFulfilledDelivery = ShopifyWebhookDelivery::where('organization_id', $organizationId)
            ->where('topic', 'orders/fulfilled')
            ->latest('id')
            ->first();

        $fulfilledWebhookFields = collect($fulfilledSchema->fields())
            ->map(function (array $field) use ($lastFulfilledDelivery) {
                $payload = $this->normalizeStoredPayload($lastFulfilledDelivery?->payload ?? []);
                $field['sample'] = $lastFulfilledDelivery ? data_get($payload, $field['path']) : null;
                return $field;
            })
            ->values()
            ->all();

        $fulfilledMappings = [];
        if ($fulfilledSetting) {
            foreach ($fulfilledSetting->mappings as $mapping) {
                $fulfilledMappings[$mapping->template_variable] = $mapping->payload_path;
            }
        }

        $fulfilledNotificationSetting = ShopifyWebhookEventSetting::with('mappings')
            ->where('organization_id', $organizationId)
            ->where('event', 'orders/fulfilled.notification')
            ->first();

        $fulfilledNotificationSelectedTemplate = null;
        if ($fulfilledNotificationSetting?->template_id) {
            $fulfilledNotificationSelectedTemplate = Template::where('id', $fulfilledNotificationSetting->template_id)
                ->where('organization_id', $organizationId)
                ->where('status', 'APPROVED')
                ->whereNull('deleted_at')
                ->first();
        }

        $fulfilledNotificationTemplateVariables = $fulfilledNotificationSelectedTemplate ? $extractor->extract($fulfilledNotificationSelectedTemplate) : [];

        $fulfilledNotificationMappings = [];
        if ($fulfilledNotificationSetting) {
            foreach ($fulfilledNotificationSetting->mappings as $mapping) {
                $fulfilledNotificationMappings[$mapping->template_variable] = $mapping->payload_path;
            }
        }

        $fulfilledNotificationWebhookFields = collect($fulfilledSchema->fields())
            ->map(function (array $field) use ($lastFulfilledDelivery) {
                $payload = $this->normalizeStoredPayload($lastFulfilledDelivery?->payload ?? []);
                $field['sample'] = $lastFulfilledDelivery ? data_get($payload, $field['path']) : null;
                return $field;
            })
            ->values()
            ->all();

        return Inertia::render('User/Settings/ShopifyAutomations', [
            'title' => __('Shopify Automations'),
            'templates' => $templates,
            'setting' => [
                'enabled' => (bool) ($setting?->enabled ?? false),
                'template_id' => $setting?->template_id,
                'recipient_phone_payload_path' => $setting?->recipient_phone_payload_path ?? 'customer.phone',
            ],
            'templateVariables' => $templateVariables,
            'webhookFields' => $webhookFields,
            'mappings' => $mappings,
            'checkoutSetting' => [
                'enabled' => (bool) ($checkoutSetting?->enabled ?? false),
                'template_id' => $checkoutSetting?->template_id,
                'recipient_phone_payload_path' => $checkoutSetting?->recipient_phone_payload_path ?? 'phone',
                'delay_hours' => (int) ($checkoutSetting?->delay_hours ?? 0),
            ],
            'checkoutTemplateVariables' => $checkoutTemplateVariables,
            'checkoutWebhookFields' => $checkoutWebhookFields,
            'checkoutMappings' => $checkoutMappings,
            'fulfilledSetting' => [
                'enabled' => (bool) ($fulfilledSetting?->enabled ?? false),
                'template_id' => $fulfilledSetting?->template_id,
                'recipient_phone_payload_path' => $fulfilledSetting?->recipient_phone_payload_path ?? 'customer.phone',
            ],
            'fulfilledTemplateVariables' => $fulfilledTemplateVariables,
            'fulfilledWebhookFields' => $fulfilledWebhookFields,
            'fulfilledMappings' => $fulfilledMappings,
            'fulfilledNotificationSetting' => [
                'enabled' => (bool) ($fulfilledNotificationSetting?->enabled ?? false),
                'template_id' => $fulfilledNotificationSetting?->template_id,
                'recipient_phone_payload_path' => $fulfilledNotificationSetting?->recipient_phone_payload_path ?? 'customer.phone',
            ],
            'fulfilledNotificationTemplateVariables' => $fulfilledNotificationTemplateVariables,
            'fulfilledNotificationWebhookFields' => $fulfilledNotificationWebhookFields,
            'fulfilledNotificationMappings' => $fulfilledNotificationMappings,
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

    public function webhookFields(ShopifyOrderCreatedFieldSchema $schema)
    {
        $organizationId = (int) session()->get('current_organization');

        $lastDelivery = ShopifyWebhookDelivery::where('organization_id', $organizationId)
            ->where('topic', 'orders/create')
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

    public function webhookFieldsCheckoutsCreated(ShopifyCheckoutCreatedFieldSchema $schema)
    {
        $organizationId = (int) session()->get('current_organization');

        $lastDelivery = ShopifyWebhookDelivery::where('organization_id', $organizationId)
            ->where('topic', 'checkouts/create')
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

    public function webhookFieldsOrderFulfilled(ShopifyOrderFulfilledFieldSchema $schema)
    {
        $organizationId = (int) session()->get('current_organization');

        $lastDelivery = ShopifyWebhookDelivery::where('organization_id', $organizationId)
            ->where('topic', 'orders/fulfilled')
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

    public function webhookFieldsOrderFulfilledNotification(ShopifyOrderFulfilledFieldSchema $schema)
    {
        $organizationId = (int) session()->get('current_organization');

        $lastDelivery = ShopifyWebhookDelivery::where('organization_id', $organizationId)
            ->where('topic', 'orders/fulfilled')
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

        $setting = ShopifyWebhookEventSetting::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'event' => 'orders/create',
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

    public function updateCheckoutsCreated(Request $request, WhatsAppTemplateVariableExtractor $extractor)
    {
        $organizationId = (int) session()->get('current_organization');

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'template_id' => ['nullable', 'integer'],
            'recipient_phone_payload_path' => ['nullable', 'string', 'max:512'],
            'delay_hours' => ['nullable', 'integer', 'min:0'],
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

        $setting = ShopifyWebhookEventSetting::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'event' => 'checkouts/create',
            ],
            [
                'enabled' => (bool) $validated['enabled'],
                'template_id' => $templateId,
                'recipient_phone_payload_path' => $validated['recipient_phone_payload_path'] ?? null,
                'delay_hours' => $validated['delay_hours'] ?? 0,
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

    public function updateOrderFulfilled(Request $request, WhatsAppTemplateVariableExtractor $extractor)
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

        $setting = ShopifyWebhookEventSetting::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'event' => 'orders/fulfilled',
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

    public function updateOrderFulfilledNotification(Request $request, WhatsAppTemplateVariableExtractor $extractor)
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

        $setting = ShopifyWebhookEventSetting::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'event' => 'orders/fulfilled.notification',
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

    private function normalizeStoredPayload(array $payload): array
    {
        if (isset($payload['payload']) && is_array($payload['payload'])) {
            return $payload['payload'];
        }

        return $payload;
    }
}
