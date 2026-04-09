<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Template;
use App\Models\ZidWebhookDelivery;
use App\Models\ZidWebhookEventSetting;
use App\Models\ZidWebhookEventStatusRule;
use App\Services\Salla\Webhooks\WhatsAppTemplateVariableExtractor;
use App\Services\Zid\Webhooks\Schemas\ZidAbandonedCartFieldSchema;
use App\Services\Zid\Webhooks\Schemas\ZidCustomerLoginFieldSchema;
use App\Services\Zid\Webhooks\Schemas\ZidOrderCreatedFieldSchema;
use App\Services\Zid\Webhooks\Schemas\ZidOrderStatusUpdatedFieldSchema;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ZidAutomationController extends BaseController
{
    public function index(
        WhatsAppTemplateVariableExtractor $variableExtractor,
        ZidCustomerLoginFieldSchema $customerSchema,
        ZidOrderCreatedFieldSchema $orderSchema,
        ZidOrderStatusUpdatedFieldSchema $statusSchema,
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

        $statusSetting = ZidWebhookEventSetting::with(['statusRules.mappings'])
            ->where('organization_id', $organizationId)
            ->where('event', 'order.status.update')
            ->first();

        $statusRules = [];
        if ($statusSetting) {
            foreach ($statusSetting->statusRules as $rule) {
                $ruleMappings = [];
                foreach ($rule->mappings as $mapping) {
                    $ruleMappings[$mapping->template_variable] = $mapping->payload_path;
                }

                $statusRules[] = [
                    'id' => $rule->id,
                    'status_slug' => $rule->status_slug,
                    'status_name' => $rule->status_name,
                    'enabled' => (bool) $rule->enabled,
                    'template_id' => $rule->template_id,
                    'mappings' => $ruleMappings,
                ];
            }
        }

        $lastStatusDelivery = ZidWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'order.status.update')
            ->latest('id')
            ->first();

        $statusWebhookFields = collect($statusSchema->fields())
            ->map(function (array $field) use ($lastStatusDelivery) {
                $payload = $this->normalizeStoredPayload($lastStatusDelivery?->payload ?? []);
                $field['sample'] = $lastStatusDelivery ? data_get($payload, $field['path']) : null;
                return $field;
            })
            ->values()
            ->all();

        $statusOptions = $this->recentStatusOptions($organizationId);

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
            'statusSetting' => [
                'enabled' => (bool) ($statusSetting?->enabled ?? false),
                'recipient_phone_payload_path' => $statusSetting?->recipient_phone_payload_path ?? 'customer.mobile',
            ],
            'statusWebhookFields' => $statusWebhookFields,
            'statusRules' => $statusRules,
            'statusOptions' => $statusOptions,
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

    public function webhookFieldsOrderStatusUpdated(ZidOrderStatusUpdatedFieldSchema $schema)
    {
        $organizationId = (int) session()->get('current_organization');

        $lastDelivery = ZidWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'order.status.update')
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

    public function updateOrderStatusUpdated(Request $request, WhatsAppTemplateVariableExtractor $extractor)
    {
        $organizationId = (int) session()->get('current_organization');

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'recipient_phone_payload_path' => ['nullable', 'string', 'max:512'],
            'rules' => ['array'],
            'rules.*.id' => ['nullable', 'integer'],
            'rules.*.status_slug' => ['required', 'string', 'max:191'],
            'rules.*.status_name' => ['nullable', 'string', 'max:191'],
            'rules.*.enabled' => ['required', 'boolean'],
            'rules.*.template_id' => ['nullable', 'integer'],
            'rules.*.mappings' => ['array'],
            'rules.*.mappings.*.template_variable' => ['required', 'string', 'max:191'],
            'rules.*.mappings.*.payload_path' => ['required', 'string', 'max:512'],
            'rules.*.mappings.*.transform' => ['nullable', 'string', 'max:64'],
            'rules.*.mappings.*.default_value' => ['nullable', 'string', 'max:255'],
        ]);

        $rules = $validated['rules'] ?? [];

        $slugSet = [];
        foreach ($rules as $rule) {
            $statusSlug = strtolower(trim((string) ($rule['status_slug'] ?? '')));
            if ($statusSlug === '') {
                return response()->json([
                    'success' => false,
                    'message' => __('Status slug is required.'),
                ], 422);
            }
            if (isset($slugSet[$statusSlug])) {
                return response()->json([
                    'success' => false,
                    'message' => __('Duplicate status slug: :slug', ['slug' => $statusSlug]),
                ], 422);
            }
            $slugSet[$statusSlug] = true;
        }

        $setting = ZidWebhookEventSetting::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'event' => 'order.status.update',
            ],
            [
                'enabled' => (bool) $validated['enabled'],
                'recipient_phone_payload_path' => $validated['recipient_phone_payload_path'] ?? null,
            ]
        );

        $keepSlugs = [];

        foreach ($rules as $ruleData) {
            $statusSlug = strtolower(trim((string) $ruleData['status_slug']));
            if ($statusSlug === '') {
                return response()->json([
                    'success' => false,
                    'message' => __('Status slug is required.'),
                ], 422);
            }

            $statusName = $ruleData['status_name'] ?? null;
            $statusName = is_string($statusName) ? trim($statusName) : null;

            $templateId = $ruleData['template_id'] ?? null;
            $templateId = is_numeric($templateId) ? (int) $templateId : null;

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

            $mappings = $ruleData['mappings'] ?? [];
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

            $ruleModel = ZidWebhookEventStatusRule::updateOrCreate(
                [
                    'zid_webhook_event_setting_id' => $setting->id,
                    'status_slug' => $statusSlug,
                ],
                [
                    'status_name' => $statusName,
                    'enabled' => (bool) ($ruleData['enabled'] ?? true),
                    'template_id' => $templateId ?: null,
                ]
            );

            $keepSlugs[] = $statusSlug;

            $ruleModel->mappings()->delete();

            foreach ($mappings as $mapping) {
                $ruleModel->mappings()->create([
                    'template_variable' => $mapping['template_variable'],
                    'payload_path' => $mapping['payload_path'],
                    'transform' => $mapping['transform'] ?? null,
                    'default_value' => $mapping['default_value'] ?? null,
                ]);
            }
        }

        $setting->statusRules()
            ->when(!empty($keepSlugs), fn ($q) => $q->whereNotIn('status_slug', $keepSlugs), fn ($q) => $q)
            ->delete();

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

    private function recentStatusOptions(int $organizationId): array
    {
        $deliveries = ZidWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'order.status.update')
            ->latest('id')
            ->limit(50)
            ->get(['payload']);

        $options = [];

        foreach ($deliveries as $delivery) {
            $payload = $this->normalizeStoredPayload($delivery->payload ?? []);

            $slug = data_get($payload, 'order_status.code')
                ?? data_get($payload, 'order_status.slug')
                ?? data_get($payload, 'order.status.code')
                ?? data_get($payload, 'order.status.slug');

            $name = data_get($payload, 'order_status.name')
                ?? data_get($payload, 'order.status.name');

            if (!is_scalar($slug)) {
                continue;
            }

            $slug = strtolower(trim((string) $slug));
            if ($slug === '') {
                continue;
            }

            if (!isset($options[$slug])) {
                $options[$slug] = [
                    'slug' => $slug,
                    'name' => is_scalar($name) ? (string) $name : null,
                ];
            }
        }

        $values = array_values($options);
        if (empty($values)) {
            return $this->defaultOrderStatusOptions();
        }
        return $values;
    }

    private function defaultOrderStatusOptions(): array
    {
        return [
            ['slug' => 'new', 'name' => __('New')],
            ['slug' => 'preparing', 'name' => __('Preparing')],
            ['slug' => 'indelivery', 'name' => __('In Delivery')],
            ['slug' => 'delivered', 'name' => __('Delivered')],
            ['slug' => 'cancelled', 'name' => __('Cancelled')],
            ['slug' => 'canceledReversal', 'name' => __('Canceled Reversal')],
            ['slug' => 'denied', 'name' => __('Denied')],
            ['slug' => 'reverse_in_progress', 'name' => __('Return In Progress')],
            ['slug' => 'ready', 'name' => __('Ready')],
            ['slug' => 'processed', 'name' => __('Processed')],
            ['slug' => 'expired', 'name' => __('Expired')],
            ['slug' => 'chargeback', 'name' => __('Chargeback')],
            ['slug' => 'reversed', 'name' => __('Reversed')],
            ['slug' => 'refunded', 'name' => __('Refunded')],
            ['slug' => 'failed', 'name' => __('Failed')],
            ['slug' => 'voided', 'name' => __('Voided')],
        ];
    }
}
