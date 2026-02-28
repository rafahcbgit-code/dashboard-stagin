<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Models\SallaWebhookDelivery;
use App\Models\SallaWebhookEventSetting;
use App\Models\SallaWebhookEventStatusRule;
use App\Models\Template;
use App\Services\Salla\Webhooks\Schemas\SallaAbandonedCartFieldSchema;
use App\Services\Salla\Webhooks\Schemas\SallaCustomerLoginFieldSchema;
use App\Services\Salla\Webhooks\Schemas\SallaCustomerOtpFieldSchema;
use App\Services\Salla\Webhooks\Schemas\SallaOrderCreatedFieldSchema;
use App\Services\Salla\Webhooks\Schemas\SallaOrderStatusUpdatedFieldSchema;
use App\Services\Salla\Webhooks\WhatsAppTemplateVariableExtractor;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SallaAutomationController extends BaseController
{
    public function index(
        SallaOrderCreatedFieldSchema $schema,
        SallaOrderStatusUpdatedFieldSchema $statusUpdatedSchema,
        SallaAbandonedCartFieldSchema $abandonedCartSchema,
        SallaCustomerLoginFieldSchema $customerLoginSchema,
        SallaCustomerOtpFieldSchema $customerOtpSchema,
        WhatsAppTemplateVariableExtractor $variableExtractor
    ) {
        $organizationId = (int) session()->get('current_organization');

        $setting = SallaWebhookEventSetting::with('mappings')
            ->where('organization_id', $organizationId)
            ->where('event', 'order.created')
            ->first();

        $codSetting = SallaWebhookEventSetting::with('mappings')
            ->where('organization_id', $organizationId)
            ->where('event', 'order.created.cod')
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

        $codSelectedTemplate = null;
        if ($codSetting?->template_id) {
            $codSelectedTemplate = Template::where('id', $codSetting->template_id)
                ->where('organization_id', $organizationId)
                ->where('status', 'APPROVED')
                ->whereNull('deleted_at')
                ->first();
        }

        $codTemplateVariables = $codSelectedTemplate ? $variableExtractor->extract($codSelectedTemplate) : [];

        $lastDelivery = SallaWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'order.created')
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

        $codMappings = [];
        if ($codSetting) {
            foreach ($codSetting->mappings as $mapping) {
                $codMappings[$mapping->template_variable] = $mapping->payload_path;
            }
        }

        $statusUpdatedSetting = SallaWebhookEventSetting::with(['statusRules.mappings'])
            ->where('organization_id', $organizationId)
            ->where('event', 'order.status.updated')
            ->first();

        $lastStatusUpdatedDelivery = SallaWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'order.status.updated')
            ->latest('id')
            ->first();

        $statusUpdatedWebhookFields = collect($statusUpdatedSchema->fields())
            ->map(function (array $field) use ($lastStatusUpdatedDelivery) {
                $payload = $this->normalizeStoredPayload($lastStatusUpdatedDelivery?->payload ?? []);
                $field['sample'] = $lastStatusUpdatedDelivery ? data_get($payload, $field['path']) : null;
                return $field;
            })
            ->values()
            ->all();

        $statusRules = [];
        if ($statusUpdatedSetting) {
            foreach ($statusUpdatedSetting->statusRules as $rule) {
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

        $statusOptions = $this->recentStatusOptions($organizationId);

        $abandonedSetting = SallaWebhookEventSetting::with('mappings')
            ->where('organization_id', $organizationId)
            ->where('event', 'abandoned.cart')
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

        $lastAbandonedDelivery = SallaWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'abandoned.cart')
            ->latest('id')
            ->first();

        $abandonedWebhookFields = collect($abandonedCartSchema->fields())
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

        $customerLoginSetting = SallaWebhookEventSetting::with('mappings')
            ->where('organization_id', $organizationId)
            ->where('event', 'customer.login')
            ->first();

        $customerLoginSelectedTemplate = null;
        if ($customerLoginSetting?->template_id) {
            $customerLoginSelectedTemplate = Template::where('id', $customerLoginSetting->template_id)
                ->where('organization_id', $organizationId)
                ->where('status', 'APPROVED')
                ->whereNull('deleted_at')
                ->first();
        }

        $customerLoginTemplateVariables = $customerLoginSelectedTemplate ? $variableExtractor->extract($customerLoginSelectedTemplate) : [];

        $lastCustomerLoginDelivery = SallaWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'customer.login')
            ->latest('id')
            ->first();

        $customerLoginWebhookFields = collect($customerLoginSchema->fields())
            ->map(function (array $field) use ($lastCustomerLoginDelivery) {
                $payload = $this->normalizeStoredPayload($lastCustomerLoginDelivery?->payload ?? []);
                $field['sample'] = $lastCustomerLoginDelivery ? data_get($payload, $field['path']) : null;
                return $field;
            })
            ->values()
            ->all();

        $customerLoginMappings = [];
        if ($customerLoginSetting) {
            foreach ($customerLoginSetting->mappings as $mapping) {
                $customerLoginMappings[$mapping->template_variable] = $mapping->payload_path;
            }
        }

        $customerOtpSetting = SallaWebhookEventSetting::with('mappings')
            ->where('organization_id', $organizationId)
            ->where('event', 'customer.otp.request')
            ->first();

        $customerOtpSelectedTemplate = null;
        if ($customerOtpSetting?->template_id) {
            $customerOtpSelectedTemplate = Template::where('id', $customerOtpSetting->template_id)
                ->where('organization_id', $organizationId)
                ->where('status', 'APPROVED')
                ->whereNull('deleted_at')
                ->first();
        }

        $customerOtpTemplateVariables = $customerOtpSelectedTemplate ? $variableExtractor->extract($customerOtpSelectedTemplate) : [];

        $lastCustomerOtpDelivery = SallaWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'customer.otp.request')
            ->latest('id')
            ->first();

        $customerOtpWebhookFields = collect($customerOtpSchema->fields())
            ->map(function (array $field) use ($lastCustomerOtpDelivery) {
                $payload = $this->normalizeStoredPayload($lastCustomerOtpDelivery?->payload ?? []);
                $field['sample'] = $lastCustomerOtpDelivery ? data_get($payload, $field['path']) : null;
                return $field;
            })
            ->values()
            ->all();

        $customerOtpMappings = [];
        if ($customerOtpSetting) {
            foreach ($customerOtpSetting->mappings as $mapping) {
                $customerOtpMappings[$mapping->template_variable] = $mapping->payload_path;
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

            'codSetting' => [
                'enabled' => (bool) ($codSetting?->enabled ?? false),
                'template_id' => $codSetting?->template_id,
                'recipient_phone_payload_path' => $codSetting?->recipient_phone_payload_path ?? 'data.customer.mobile_code+data.customer.mobile',
            ],
            'codTemplateVariables' => $codTemplateVariables,
            'codMappings' => $codMappings,

            'statusSetting' => [
                'enabled' => (bool) ($statusUpdatedSetting?->enabled ?? false),
                'recipient_phone_payload_path' => $statusUpdatedSetting?->recipient_phone_payload_path ?? 'data.order.customer.mobile',
            ],
            'statusWebhookFields' => $statusUpdatedWebhookFields,
            'statusRules' => $statusRules,
            'statusOptions' => $statusOptions,

            'abandonedSetting' => [
                'enabled' => (bool) ($abandonedSetting?->enabled ?? false),
                'template_id' => $abandonedSetting?->template_id,
                'recipient_phone_payload_path' => $abandonedSetting?->recipient_phone_payload_path ?? 'data.customer.mobile',
                'send_delay_hours' => $abandonedSetting?->send_delay_hours,
            ],
            'abandonedTemplateVariables' => $abandonedTemplateVariables,
            'abandonedWebhookFields' => $abandonedWebhookFields,
            'abandonedMappings' => $abandonedMappings,

            'customerLoginSetting' => [
                'enabled' => (bool) ($customerLoginSetting?->enabled ?? false),
                'template_id' => $customerLoginSetting?->template_id,
                'recipient_phone_payload_path' => $customerLoginSetting?->recipient_phone_payload_path ?? 'data.mobile_code+data.mobile',
            ],
            'customerLoginTemplateVariables' => $customerLoginTemplateVariables,
            'customerLoginWebhookFields' => $customerLoginWebhookFields,
            'customerLoginMappings' => $customerLoginMappings,
            'customerOtpSetting' => [
                'enabled' => (bool) ($customerOtpSetting?->enabled ?? false),
                'template_id' => $customerOtpSetting?->template_id,
                'recipient_phone_payload_path' => $customerOtpSetting?->recipient_phone_payload_path ?? 'data.contact',
            ],
            'customerOtpTemplateVariables' => $customerOtpTemplateVariables,
            'customerOtpWebhookFields' => $customerOtpWebhookFields,
            'customerOtpMappings' => $customerOtpMappings,
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

    public function webhookFieldsOrderStatusUpdated(SallaOrderStatusUpdatedFieldSchema $schema)
    {
        $organizationId = (int) session()->get('current_organization');

        $lastDelivery = SallaWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'order.status.updated')
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

    public function webhookFieldsAbandonedCart(SallaAbandonedCartFieldSchema $schema)
    {
        $organizationId = (int) session()->get('current_organization');

        $lastDelivery = SallaWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'abandoned.cart')
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

    public function webhookFieldsCustomerLogin(SallaCustomerLoginFieldSchema $schema)
    {
        $organizationId = (int) session()->get('current_organization');

        $lastDelivery = SallaWebhookDelivery::where('organization_id', $organizationId)
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

    public function webhookFieldsCustomerOtp(SallaCustomerOtpFieldSchema $schema)
    {
        $organizationId = (int) session()->get('current_organization');

        $lastDelivery = SallaWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'customer.otp.request')
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

    public function updateOrderCreatedCod(Request $request, WhatsAppTemplateVariableExtractor $extractor)
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
                'event' => 'order.created.cod',
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

        // Ensure unique status slugs within the request payload.
        $slugSet = [];
        foreach ($rules as $rule) {
            $slug = strtolower(trim((string) $rule['status_slug']));
            if ($slug === '') {
                return response()->json([
                    'success' => false,
                    'message' => __('Status slug is required.'),
                ], 422);
            }
            if (isset($slugSet[$slug])) {
                return response()->json([
                    'success' => false,
                    'message' => __('Duplicate status slug: :slug', ['slug' => $slug]),
                ], 422);
            }
            $slugSet[$slug] = true;
        }

        $setting = SallaWebhookEventSetting::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'event' => 'order.status.updated',
            ],
            [
                'enabled' => (bool) $validated['enabled'],
                'recipient_phone_payload_path' => $validated['recipient_phone_payload_path'] ?? null,
                // Template is selected per-status for this event, so we keep this null.
                'template_id' => null,
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
                        'message' => "Missing mapping for {$key} (status: {$statusSlug})",
                    ], 422);
                }
            }

            $ruleModel = SallaWebhookEventStatusRule::updateOrCreate(
                [
                    'salla_webhook_event_setting_id' => $setting->id,
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

        // Remove any rules not present in the current update payload.
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

        $setting = SallaWebhookEventSetting::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'event' => 'abandoned.cart',
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

        $setting = SallaWebhookEventSetting::updateOrCreate(
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

    public function updateCustomerOtp(Request $request, WhatsAppTemplateVariableExtractor $extractor)
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
                'event' => 'customer.otp.request',
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
        // Backward compatibility: some old stored deliveries may still have the `payload` wrapper.
        if (isset($payload['payload']) && is_array($payload['payload'])) {
            return $payload['payload'];
        }

        return $payload;
    }

    private function recentStatusOptions(int $organizationId): array
    {
        $deliveries = SallaWebhookDelivery::where('organization_id', $organizationId)
            ->where('event', 'order.status.updated')
            ->latest('id')
            ->limit(50)
            ->get(['payload']);

        $options = [];

        foreach ($deliveries as $delivery) {
            $payload = $this->normalizeStoredPayload($delivery->payload ?? []);

            $slug = data_get($payload, 'data.customized.slug')
                ?? data_get($payload, 'data.status.slug')
                ?? data_get($payload, 'data.order.status.slug');

            $name = data_get($payload, 'data.customized.name')
                ?? data_get($payload, 'data.status.name')
                ?? data_get($payload, 'data.status')
                ?? data_get($payload, 'data.order.status.name');

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

        return array_values($options);
    }
}
