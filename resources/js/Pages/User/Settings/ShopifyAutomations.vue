<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-10 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] h-full md:overflow-y-auto">
            <div class="flex justify-between mb-6">
                <div>
                    <h1 class="text-xl mb-1">{{ $t('Shopify Automations') }}</h1>
                    <p class="flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z" />
                        </svg>
                        <span class="ltr:ml-1 rtl:mr-1 mt-1">{{ $t('Automate WhatsApp replies for Shopify webhooks') }}</span>
                    </p>
                </div>
                <Link href="/settings/shopify" class="px-3 py-2 rounded-md bg-white shadow-sm text-sm text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                    {{ $t('Back to Shopify') }}
                </Link>
            </div>

            <div class="bg-white border border-slate-200 rounded-lg p-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="flex-1">
                        <h2 class="text-lg font-medium">{{ $t('New Order') }}</h2>
                        <p class="text-sm text-gray-600">{{ $t('Send a template message when a Shopify order is created') }}</p>
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
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Recipient phone path') }}</label>
                        <input
                            type="text"
                            class="w-full border rounded-md px-3 py-2 focus:ring-1 focus:ring-primary focus:border-primary outline-none"
                            v-model="form.recipient_phone_payload_path"
                        />
                        <div v-if="errors.recipient_phone_payload_path" class="form-error text-[#b91c1c] text-xs mt-1">
                            {{ errors.recipient_phone_payload_path }}
                        </div>
                        <p class="text-xs text-gray-500 mt-1">{{ $t('Example: customer.phone or billing_address.phone') }}</p>
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
                        {{ $t('No Shopify payload recorded yet. Trigger an order.created webhook to inspect fields.') }}
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
                        <h2 class="text-lg font-medium">{{ $t('Tracking Link Added') }}</h2>
                        <p class="text-sm text-gray-600">{{ $t('Send a template message when Shopify creates a fulfillment with a tracking link') }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-700">{{ fulfilledForm.enabled ? $t('Enabled') : $t('Disabled') }}</span>
                        <FormToggleSwitch v-model="fulfilledForm.enabled" />
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Template') }}</label>
                        <BaseListbox v-model="fulfilledForm.template_id" :options="templates" :placeholder="$t('Select template')" />
                        <div v-if="fulfilledErrors.template_id" class="form-error text-[#b91c1c] text-xs mt-1">{{ fulfilledErrors.template_id }}</div>
                    </div>

                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Recipient phone path') }}</label>
                        <input
                            type="text"
                            class="w-full border rounded-md px-3 py-2 focus:ring-1 focus:ring-primary focus:border-primary outline-none"
                            v-model="fulfilledForm.recipient_phone_payload_path"
                        />
                        <div v-if="fulfilledErrors.recipient_phone_payload_path" class="form-error text-[#b91c1c] text-xs mt-1">
                            {{ fulfilledErrors.recipient_phone_payload_path }}
                        </div>
                        <p class="text-xs text-gray-500 mt-1">{{ $t('Example: customer.phone or shipping_address.phone') }}</p>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900">{{ $t('Variable Mapping') }}</h3>
                    <button type="button"
                        class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-sm rounded-md"
                        :disabled="fulfilledLoadingFields"
                        @click="refreshFulfilledWebhookFields">
                        <span v-if="fulfilledLoadingFields">{{ $t('Refreshing...') }}</span>
                        <span v-else>{{ $t('Refresh webhook fields') }}</span>
                    </button>
                </div>

                <div v-if="fulfilledTemplateVariablesLocal.length === 0" class="mt-4 text-sm text-gray-600">
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
                            <tr v-for="variable in fulfilledTemplateVariablesLocal" :key="variable.key" class="border-t">
                                <td class="py-3 pr-4">
                                    <div class="font-medium text-gray-900">{{ variable.key }}</div>
                                    <div class="text-xs text-gray-500">{{ variable.placeholder }}</div>
                                </td>
                                <td class="py-3 pr-4">
                                    <select v-model="fulfilledMappingsLocal[variable.key]"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                        <option value="">{{ $t('Select field') }}</option>
                                        <option v-for="field in fulfilledWebhookFieldsLocal" :key="field.path" :value="field.path">
                                            {{ fieldLabel(field) }}
                                        </option>
                                    </select>
                                    <div v-if="fulfilledErrors[`mappings.${variable.key}`]" class="form-error text-[#b91c1c] text-xs mt-1">
                                        {{ fulfilledErrors[`mappings.${variable.key}`] }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="fulfilledWebhookFieldsLocal.length === 0" class="mt-3 text-xs text-gray-500">
                        {{ $t('No Shopify payload recorded yet. Trigger an orders/fulfilled webhook to inspect fields.') }}
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="button"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm rounded-md hover:shadow-md disabled:opacity-50"
                        :disabled="fulfilledSaving"
                        @click="saveFulfilled">
                        <span v-if="fulfilledSaving">{{ $t('Saving...') }}</span>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                    <div v-if="fulfilledSuccessMessage" class="text-sm text-green-700">{{ fulfilledSuccessMessage }}</div>
                    <div v-if="fulfilledErrorMessage" class="text-sm text-red-700">{{ fulfilledErrorMessage }}</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { reactive, ref, watch } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../Layout/App.vue';
import BaseListbox from '@/Components/BaseListbox.vue';
import FormToggleSwitch from '@/Components/FormToggleSwitch.vue';

const props = defineProps({
    title: String,
    templates: {
        type: Array,
        default: () => []
    },
    setting: {
        type: Object,
        default: () => ({ enabled: false, template_id: null, recipient_phone_payload_path: 'customer.phone' })
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
    fulfilledSetting: {
        type: Object,
        default: () => ({ enabled: false, template_id: null, recipient_phone_payload_path: 'customer.phone' })
    },
    fulfilledTemplateVariables: {
        type: Array,
        default: () => []
    },
    fulfilledWebhookFields: {
        type: Array,
        default: () => []
    },
    fulfilledMappings: {
        type: Object,
        default: () => ({})
    }
});

const form = reactive({
    enabled: !!props.setting.enabled,
    template_id: props.setting.template_id ?? null,
    recipient_phone_payload_path: props.setting.recipient_phone_payload_path ?? 'customer.phone',
});

const templateVariablesLocal = ref(props.templateVariables ?? []);
const webhookFieldsLocal = ref(props.webhookFields ?? []);
const mappingsLocal = reactive({ ...(props.mappings ?? {}) });
for (const variable of templateVariablesLocal.value ?? []) {
    if (!(variable.key in mappingsLocal)) {
        mappingsLocal[variable.key] = '';
    }
}

const fulfilledForm = reactive({
    enabled: !!props.fulfilledSetting.enabled,
    template_id: props.fulfilledSetting.template_id ?? null,
    recipient_phone_payload_path: props.fulfilledSetting.recipient_phone_payload_path ?? 'customer.phone',
});

const fulfilledTemplateVariablesLocal = ref(props.fulfilledTemplateVariables ?? []);
const fulfilledWebhookFieldsLocal = ref(props.fulfilledWebhookFields ?? []);
const fulfilledMappingsLocal = reactive({ ...(props.fulfilledMappings ?? {}) });
for (const variable of fulfilledTemplateVariablesLocal.value ?? []) {
    if (!(variable.key in fulfilledMappingsLocal)) {
        fulfilledMappingsLocal[variable.key] = '';
    }
}

const saving = ref(false);
const successMessage = ref('');
const errorMessage = ref('');
const loadingFields = ref(false);
const errors = reactive({});
const fulfilledSaving = ref(false);
const fulfilledSuccessMessage = ref('');
const fulfilledErrorMessage = ref('');
const fulfilledLoadingFields = ref(false);
const fulfilledErrors = reactive({});

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
    const label = field?.label ?? path;
    const sample = field?.sample ?? null;
    const sampleText = stringifySample(sample);

    return sampleText ? `${label} — ${sampleText}` : label;
};

const fetchTemplateVariables = async (templateId) => {
    if (!templateId) {
        return [];
    }

    if (templateId in templateVarsCache) {
        return templateVarsCache[templateId] ?? [];
    }

    const res = await axios.get(`/settings/shopify/automations/template/${templateId}/variables`);
    templateVarsCache[templateId] = res?.data?.data?.variables ?? [];

    return templateVarsCache[templateId];
};

const loadTemplateVariables = async (templateId) => {
    if (!templateId) {
        templateVariablesLocal.value = [];
        return;
    }

    templateVariablesLocal.value = await fetchTemplateVariables(templateId);

    for (const variable of templateVariablesLocal.value) {
        if (!(variable.key in mappingsLocal)) {
            mappingsLocal[variable.key] = '';
        }
    }
};

watch(() => form.template_id, async (newId) => {
    await loadTemplateVariables(newId);
});

const loadFulfilledTemplateVariables = async (templateId) => {
    if (!templateId) {
        fulfilledTemplateVariablesLocal.value = [];
        return;
    }

    fulfilledTemplateVariablesLocal.value = await fetchTemplateVariables(templateId);

    for (const variable of fulfilledTemplateVariablesLocal.value) {
        if (!(variable.key in fulfilledMappingsLocal)) {
            fulfilledMappingsLocal[variable.key] = '';
        }
    }
};

watch(() => fulfilledForm.template_id, async (newId) => {
    await loadFulfilledTemplateVariables(newId);
});

const refreshWebhookFields = async () => {
    loadingFields.value = true;
    try {
        const res = await axios.get('/settings/shopify/automations/order-created/webhook-fields');
        webhookFieldsLocal.value = res?.data?.data?.fields ?? [];
    } finally {
        loadingFields.value = false;
    }
};

const refreshFulfilledWebhookFields = async () => {
    fulfilledLoadingFields.value = true;
    try {
        const res = await axios.get('/settings/shopify/automations/orders-fulfilled/webhook-fields');
        fulfilledWebhookFieldsLocal.value = res?.data?.data?.fields ?? [];
    } finally {
        fulfilledLoadingFields.value = false;
    }
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
        await axios.post('/settings/shopify/automations/order-created', {
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

        if (e?.response?.status === 422 && e?.response?.data?.message?.startsWith('Missing mapping for')) {
            errorMessage.value = e.response.data.message;
        }
    } finally {
        saving.value = false;
    }
};

const saveFulfilled = async () => {
    fulfilledSaving.value = true;
    fulfilledSuccessMessage.value = '';
    fulfilledErrorMessage.value = '';
    Object.keys(fulfilledErrors).forEach((k) => delete fulfilledErrors[k]);

    const mappingsArray = (fulfilledTemplateVariablesLocal.value ?? []).map(v => ({
        template_variable: v.key,
        payload_path: fulfilledMappingsLocal[v.key] ?? '',
    }));

    try {
        await axios.post('/settings/shopify/automations/orders-fulfilled', {
            enabled: fulfilledForm.enabled,
            template_id: fulfilledForm.template_id,
            recipient_phone_payload_path: fulfilledForm.recipient_phone_payload_path,
            mappings: mappingsArray,
        });

        fulfilledSuccessMessage.value = 'Saved';
    } catch (e) {
        fulfilledErrorMessage.value = e?.response?.data?.message ?? 'Failed to save';

        const serverErrors = e?.response?.data?.errors ?? null;
        if (serverErrors && typeof serverErrors === 'object') {
            for (const [key, value] of Object.entries(serverErrors)) {
                const match = String(key).match(/^mappings\.(\d+)\.(payload_path|template_variable)$/);
                if (match) {
                    const idx = parseInt(match[1], 10);
                    const varKey = mappingsArray[idx]?.template_variable;
                    if (varKey) {
                        fulfilledErrors[`mappings.${varKey}`] = Array.isArray(value) ? value[0] : value;
                        continue;
                    }
                }

                fulfilledErrors[key] = Array.isArray(value) ? value[0] : value;
            }
        }

        if (e?.response?.status === 422 && e?.response?.data?.message?.startsWith('Missing mapping for')) {
            fulfilledErrorMessage.value = e.response.data.message;
        }
    } finally {
        fulfilledSaving.value = false;
    }
};

</script>
