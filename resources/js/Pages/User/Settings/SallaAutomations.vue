<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-10 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] h-full md:overflow-y-auto">
            <div class="flex justify-between mb-6">
                <div>
                    <h1 class="text-xl mb-1">{{ $t('Salla Automations') }}</h1>
                    <p class="flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z" />
                        </svg>
                        <span class="ltr:ml-1 rtl:mr-1 mt-1">{{ $t('Configure automatic WhatsApp messages from Salla webhooks') }}</span>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="/settings/salla" class="rounded-md bg-white px-3 py-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                        {{ $t('Back') }}
                    </a>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-lg p-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="flex-1">
                        <h2 class="text-lg font-medium">{{ $t('New Order') }}</h2>
                        <p class="text-sm text-gray-600">{{ $t('Send a message automatically when a new order is created in Salla (order.created).') }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-700">{{ form.enabled ? $t('Enabled') : $t('Disabled') }}</span>
                        <FormToggleSwitch v-model="form.enabled" />
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Template') }}</label>
                        <BaseListbox v-model="form.template_id" :options="templates" :placeholder="$t('Select template')" />
                        <div v-if="errors.template_id" class="form-error text-[#b91c1c] text-xs mt-1">{{ errors.template_id }}</div>
                    </div>

                    <div>
                        <FormInput
                            v-model="form.recipient_phone_payload_path"
                            :name="$t('Recipient phone payload path')"
                            :placeholder="'data.customer.mobile_code+data.customer.mobile'"
                            :error="errors.recipient_phone_payload_path"
                            :type="'text'"
                        />
                        <p class="text-xs text-gray-500 mt-1">{{ $t('Example: data.customer.mobile_code+data.customer.mobile') }}</p>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900">{{ $t('Variable Mapping') }}</h3>
                    <button type="button"
                        class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-sm rounded-md"
                        :disabled="loadingFields"
                        @click="refreshWebhookFields">
                        <span v-if="loadingFields">{{ $t('Refreshing...') }}</span>
                        <span v-else>{{ $t('Refresh webhook fields') }}</span>
                    </button>
                </div>

                <div v-if="templateVariablesLocal.length === 0" class="mt-4 text-sm text-gray-600">
                    {{ $t('Select a template to configure variable mapping.') }}
                </div>

                <div v-else class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600">
                                <th class="py-2 pr-4">{{ $t('Template variable') }}</th>
                                <th class="py-2 pr-4">{{ $t('Webhook field') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="variable in templateVariablesLocal" :key="variable.key" class="border-t">
                                <td class="py-3 pr-4">
                                    <div class="font-medium text-gray-900">{{ variable.key }}</div>
                                    <div class="text-xs text-gray-500">{{ variable.placeholder }}</div>
                                </td>
                                <td class="py-3 pr-4">
                                    <select v-model="mappingsLocal[variable.key]"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                        <option value="">{{ $t('Select field') }}</option>
                                        <option v-for="field in webhookFieldsLocal" :key="field.path" :value="field.path">
                                            {{ fieldLabel(field) }}
                                        </option>
                                    </select>
                                    <div v-if="errors[`mappings.${variable.key}`]" class="form-error text-[#b91c1c] text-xs mt-1">
                                        {{ errors[`mappings.${variable.key}`] }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="webhookFieldsLocal.length === 0" class="mt-3 text-xs text-gray-500">
                        {{ $t('No webhook payload received yet. Trigger an order.created webhook to see available fields.') }}
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="button"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm rounded-md hover:shadow-md disabled:opacity-50"
                        :disabled="saving"
                        @click="save">
                        <span v-if="saving">{{ $t('Saving...') }}</span>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                    <div v-if="successMessage" class="text-sm text-green-700">{{ successMessage }}</div>
                    <div v-if="errorMessage" class="text-sm text-red-700">{{ errorMessage }}</div>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-lg p-6 mt-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="flex-1">
                        <h2 class="text-lg font-medium">{{ $t('Order Status Updated') }}</h2>
                        <p class="text-sm text-gray-600">
                            {{ $t('Send a message automatically when an order status changes in Salla (order.status.updated).') }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-700">{{ statusForm.enabled ? $t('Enabled') : $t('Disabled') }}</span>
                        <FormToggleSwitch v-model="statusForm.enabled" />
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <FormInput
                            v-model="statusForm.recipient_phone_payload_path"
                            :name="$t('Recipient phone payload path')"
                            :placeholder="'data.order.customer.mobile'"
                            :error="statusErrors.recipient_phone_payload_path"
                            :type="'text'"
                        />
                        <p class="text-xs text-gray-500 mt-1">{{ $t('Example: data.order.customer.mobile') }}</p>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900">{{ $t('Status Rules') }}</h3>
                    <div class="flex items-center gap-2">
                        <button type="button"
                            class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-sm rounded-md"
                            :disabled="statusLoadingFields"
                            @click="refreshStatusWebhookFields">
                            <span v-if="statusLoadingFields">{{ $t('Refreshing...') }}</span>
                            <span v-else>{{ $t('Refresh webhook fields') }}</span>
                        </button>
                        <button type="button"
                            class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-sm rounded-md"
                            @click="addStatusRule">
                            {{ $t('Add rule') }}
                        </button>
                    </div>
                </div>

                <div v-if="statusRulesLocal.length === 0" class="mt-4 text-sm text-gray-600">
                    {{ $t('No status rules configured yet. Add a rule for a status slug (e.g. under_review).') }}
                </div>

                <div v-else class="mt-4 space-y-4">
                    <div v-for="(rule, idx) in statusRulesLocal" :key="rule._key" class="border border-slate-200 rounded-md p-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Status slug') }}</label>
                                    <div class="flex items-center gap-2">
                                        <input v-model="rule.status_slug"
                                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                            :placeholder="'under_review'"
                                        />
                                        <select class="block w-44 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                            @change="(e) => { const v = e?.target?.value; if (v) { rule.status_slug = v; const opt = (statusOptions || []).find(o => o.slug === v); if (opt && opt.name) rule.status_name = opt.name; e.target.value = ''; } }">
                                            <option value="">{{ $t('Quick pick') }}</option>
                                            <option v-for="opt in statusOptions" :key="opt.slug" :value="opt.slug">
                                                {{ opt.name ? `${opt.name} (${opt.slug})` : opt.slug }}
                                            </option>
                                        </select>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">{{ $t('Use the status slug sent by Salla (example: under_review).') }}</p>

                                    <label class="block text-sm leading-6 text-gray-900 mb-2 mt-3">{{ $t('Status name (optional)') }}</label>
                                    <input v-model="rule.status_name"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                        :placeholder="$t('Under review')"
                                    />
                                </div>

                                <div>
                                    <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Template') }}</label>
                                    <BaseListbox v-model="rule.template_id" :options="templates" :placeholder="$t('Select template')" />
                                </div>

                                <div class="flex items-center justify-between md:justify-start md:gap-3">
                                    <div>
                                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Enabled') }}</label>
                                        <FormToggleSwitch v-model="rule.enabled" />
                                    </div>
                                    <button type="button"
                                        class="inline-flex items-center gap-2 px-3 py-2 bg-red-50 hover:bg-red-100 text-red-700 text-sm rounded-md md:mt-6"
                                        @click="removeStatusRule(idx)">
                                        {{ $t('Remove') }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div v-if="!rule.template_id" class="mt-4 text-sm text-gray-600">
                            {{ $t('Select a template to configure variable mapping for this status.') }}
                        </div>

                        <div v-else class="mt-4 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="text-left text-gray-600">
                                        <th class="py-2 pr-4">{{ $t('Template variable') }}</th>
                                        <th class="py-2 pr-4">{{ $t('Webhook field') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="variable in (templateVarsCache[rule.template_id] ?? [])" :key="variable.key" class="border-t">
                                        <td class="py-3 pr-4">
                                            <div class="font-medium text-gray-900">{{ variable.key }}</div>
                                            <div class="text-xs text-gray-500">{{ variable.placeholder }}</div>
                                        </td>
                                        <td class="py-3 pr-4">
                                            <select v-model="rule.mappings[variable.key]"
                                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                                <option value="">{{ $t('Select field') }}</option>
                                                <option v-for="field in statusWebhookFieldsLocal" :key="field.path" :value="field.path">
                                                    {{ fieldLabel(field) }}
                                                </option>
                                            </select>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div v-if="!(rule.template_id in templateVarsCache)" class="mt-3 text-xs text-gray-500">
                                {{ $t('Loading template variables...') }}
                            </div>
                            <div v-if="statusWebhookFieldsLocal.length === 0" class="mt-3 text-xs text-gray-500">
                                {{ $t('No webhook payload received yet. Trigger an order.status.updated webhook to see available fields.') }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="button"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm rounded-md hover:shadow-md disabled:opacity-50"
                        :disabled="statusSaving"
                        @click="saveStatusUpdated">
                        <span v-if="statusSaving">{{ $t('Saving...') }}</span>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                    <div v-if="statusSuccessMessage" class="text-sm text-green-700">{{ statusSuccessMessage }}</div>
                    <div v-if="statusErrorMessage" class="text-sm text-red-700">{{ statusErrorMessage }}</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { reactive, ref, watch } from 'vue';
import axios from 'axios';
import AppLayout from '../Layout/App.vue';
import BaseListbox from '@/Components/BaseListbox.vue';
import FormInput from '@/Components/FormInput.vue';
import FormToggleSwitch from '@/Components/FormToggleSwitch.vue';

const props = defineProps({
    title: String,
    templates: {
        type: Array,
        default: () => []
    },
    setting: {
        type: Object,
        default: () => ({ enabled: false, template_id: null, recipient_phone_payload_path: 'data.customer.mobile' })
    },
    templateVariables: {
        type: Array,
        default: () => []
    },
    webhookFields: {
        type: Array,
        default: () => []
    },
    mappings: {
        type: Object,
        default: () => ({})
    },
    statusSetting: {
        type: Object,
        default: () => ({ enabled: false, recipient_phone_payload_path: 'data.order.customer.mobile' })
    },
    statusWebhookFields: {
        type: Array,
        default: () => []
    },
    statusRules: {
        type: Array,
        default: () => []
    },
    statusOptions: {
        type: Array,
        default: () => []
    }
});

const form = reactive({
    enabled: !!props.setting.enabled,
    template_id: props.setting.template_id ?? null,
    recipient_phone_payload_path: props.setting.recipient_phone_payload_path ?? 'data.customer.mobile',
});

const templateVariablesLocal = ref(props.templateVariables ?? []);
const webhookFieldsLocal = ref(props.webhookFields ?? []);
const mappingsLocal = reactive({ ...(props.mappings ?? {}) });

const statusForm = reactive({
    enabled: !!props.statusSetting.enabled,
    recipient_phone_payload_path: props.statusSetting.recipient_phone_payload_path ?? 'data.order.customer.mobile',
});

const statusWebhookFieldsLocal = ref(props.statusWebhookFields ?? []);
const statusRulesLocal = ref((props.statusRules ?? []).map((rule, idx) => ({
    _key: rule?.id ?? `rule-${idx}-${Date.now()}`,
    _loadedTemplateId: null,
    id: rule?.id ?? null,
    status_slug: rule?.status_slug ?? '',
    status_name: rule?.status_name ?? '',
    enabled: typeof rule?.enabled === 'boolean' ? rule.enabled : true,
    template_id: rule?.template_id ? Number(rule.template_id) : null,
    mappings: { ...(rule?.mappings ?? {}) },
})));

const saving = ref(false);
const loadingFields = ref(false);
const errors = reactive({});
const successMessage = ref('');
const errorMessage = ref('');

const statusSaving = ref(false);
const statusLoadingFields = ref(false);
const statusErrors = reactive({});
const statusSuccessMessage = ref('');
const statusErrorMessage = ref('');

const templateVarsCache = reactive({});

const stringifySample = (value) => {
    try {
        if (typeof value === 'string') {
            return value.length > 60 ? value.slice(0, 60) + '…' : value;
        }
        if (typeof value === 'number' || typeof value === 'boolean') {
            return String(value);
        }
        if (value === null || value === undefined) {
            return '';
        }
        const json = JSON.stringify(value);
        return json.length > 60 ? json.slice(0, 60) + '…' : json;
    } catch (e) {
        return '';
    }
};

const fieldLabel = (field) => {
    const path = field?.path ?? '';
    const label = field?.label ?? '';
    const sample = field?.sample ?? null;
    const sampleText = stringifySample(sample);

    const base = label ? `${label} (${path})` : path;
    return sampleText ? `${base} — ${sampleText}` : base;
};

const refreshWebhookFields = async () => {
    loadingFields.value = true;
    try {
        const res = await axios.get('/settings/salla/automations/order-created/webhook-fields');
        webhookFieldsLocal.value = res?.data?.data?.fields ?? [];
    } finally {
        loadingFields.value = false;
    }
};

const fetchTemplateVariables = async (templateId) => {
    if (!templateId) {
        return [];
    }

    if (templateId in templateVarsCache) {
        return templateVarsCache[templateId] ?? [];
    }

    const res = await axios.get(`/settings/salla/automations/template/${templateId}/variables`);
    templateVarsCache[templateId] = res?.data?.data?.variables ?? [];

    return templateVarsCache[templateId];
};

const loadTemplateVariables = async (templateId) => {
    if (!templateId) {
        templateVariablesLocal.value = [];
        return;
    }

    templateVariablesLocal.value = await fetchTemplateVariables(templateId);

    // Initialize missing mapping keys
    for (const variable of templateVariablesLocal.value) {
        if (!(variable.key in mappingsLocal)) {
            mappingsLocal[variable.key] = '';
        }
    }
};

watch(() => form.template_id, async (newId) => {
    await loadTemplateVariables(newId);
});

watch(statusRulesLocal, async (rules) => {
    for (const rule of rules ?? []) {
        const templateId = rule?.template_id ?? null;

        if (!templateId) {
            rule._loadedTemplateId = null;
            continue;
        }

        if (rule._loadedTemplateId === templateId) {
            continue;
        }

        const vars = await fetchTemplateVariables(templateId);
        rule._loadedTemplateId = templateId;

        for (const v of vars ?? []) {
            if (!(v.key in rule.mappings)) {
                rule.mappings[v.key] = '';
            }
        }
    }
}, { deep: true, immediate: true });

const refreshStatusWebhookFields = async () => {
    statusLoadingFields.value = true;
    try {
        const res = await axios.get('/settings/salla/automations/order-status-updated/webhook-fields');
        statusWebhookFieldsLocal.value = res?.data?.data?.fields ?? [];
    } finally {
        statusLoadingFields.value = false;
    }
};

const addStatusRule = () => {
    statusRulesLocal.value.push({
        _key: `new-${Date.now()}-${Math.random().toString(16).slice(2)}`,
        _loadedTemplateId: null,
        id: null,
        status_slug: '',
        status_name: '',
        enabled: true,
        template_id: null,
        mappings: {},
    });
};

const removeStatusRule = (idx) => {
    statusRulesLocal.value.splice(idx, 1);
};

const save = async () => {
    saving.value = true;
    successMessage.value = '';
    errorMessage.value = '';
    Object.keys(errors).forEach((k) => delete errors[k]);

    const mappingsArray = (templateVariablesLocal.value ?? []).map(v => ({
        template_variable: v.key,
        payload_path: mappingsLocal[v.key] ?? '',
    }));

    try {
        await axios.post('/settings/salla/automations/order-created', {
            enabled: form.enabled,
            template_id: form.template_id,
            recipient_phone_payload_path: form.recipient_phone_payload_path,
            mappings: mappingsArray,
        });

        successMessage.value = 'Saved';
    } catch (e) {
        errorMessage.value = e?.response?.data?.message ?? 'Failed to save';

        const serverErrors = e?.response?.data?.errors ?? null;
        if (serverErrors && typeof serverErrors === 'object') {
            for (const [key, value] of Object.entries(serverErrors)) {
                // Remap index-based mapping errors to stable keys like `mappings.body.1`
                const match = String(key).match(/^mappings\.(\d+)\.(payload_path|template_variable)$/);
                if (match) {
                    const idx = parseInt(match[1], 10);
                    const varKey = mappingsArray[idx]?.template_variable;
                    if (varKey) {
                        errors[`mappings.${varKey}`] = Array.isArray(value) ? value[0] : value;
                        continue;
                    }
                }

                errors[key] = Array.isArray(value) ? value[0] : value;
            }
        }

        // Special case for missing required mappings (422 custom response)
        if (e?.response?.status === 422 && e?.response?.data?.message?.startsWith('Missing mapping for')) {
            errorMessage.value = e.response.data.message;
        }
    } finally {
        saving.value = false;
    }
};

const saveStatusUpdated = async () => {
    statusSaving.value = true;
    statusSuccessMessage.value = '';
    statusErrorMessage.value = '';
    Object.keys(statusErrors).forEach((k) => delete statusErrors[k]);

    try {
        const rulesPayload = [];

        for (const rule of statusRulesLocal.value ?? []) {
            const templateId = rule.template_id ? Number(rule.template_id) : null;
            const vars = templateId ? await fetchTemplateVariables(templateId) : [];

            const mappingsArray = (vars ?? []).map(v => ({
                template_variable: v.key,
                payload_path: rule.mappings?.[v.key] ?? '',
            }));

            rulesPayload.push({
                id: rule.id,
                status_slug: rule.status_slug,
                status_name: rule.status_name,
                enabled: !!rule.enabled,
                template_id: templateId,
                mappings: mappingsArray,
            });
        }

        await axios.post('/settings/salla/automations/order-status-updated', {
            enabled: statusForm.enabled,
            recipient_phone_payload_path: statusForm.recipient_phone_payload_path,
            rules: rulesPayload,
        });

        statusSuccessMessage.value = 'Saved';
    } catch (e) {
        statusErrorMessage.value = e?.response?.data?.message ?? 'Failed to save';

        const serverErrors = e?.response?.data?.errors ?? null;
        if (serverErrors && typeof serverErrors === 'object') {
            for (const [key, value] of Object.entries(serverErrors)) {
                statusErrors[key] = Array.isArray(value) ? value[0] : value;
            }
        }
    } finally {
        statusSaving.value = false;
    }
};
</script>
