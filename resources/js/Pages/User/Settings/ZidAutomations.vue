<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-10 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] h-full md:overflow-y-auto">
            <div class="flex justify-between mb-6">
                <div>
                    <h1 class="text-xl mb-1">{{ $t('Zid Automations') }}</h1>
                    <p class="flex items-center text-sm leading-6 text-gray-600">
                        <span class="ltr:ml-1 rtl:mr-1 mt-1">{{ $t('Send automated WhatsApp templates when a Zid customer logs in.') }}</span>
                    </p>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-lg p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Template') }}</label>
                        <BaseListbox v-model="form.template_id" :options="props.templates" :placeholder="$t('Select template')" />
                        <div v-if="errors.template_id" class="form-error text-[#b91c1c] text-xs mt-1">{{ errors.template_id }}</div>
                    </div>
                    <!-- <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Recipient phone payload path') }}</label>
                        <input
                            v-model="form.recipient_phone_payload_path"
                            type="text"
                            placeholder="telephone"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                        />
                        <p class="text-xs text-gray-500 mt-1">{{ $t('Example: telephone or data.mobile_code+data.mobile') }}</p>
                        <div v-if="errors.recipient_phone_payload_path" class="form-error text-[#b91c1c] text-xs mt-1">
                            {{ errors.recipient_phone_payload_path }}
                        </div>
                    </div> -->
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900">{{ $t('Automation Status') }}</h3>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-700">{{ form.enabled ? $t('Enabled') : $t('Disabled') }}</span>
                        <FormToggleSwitch v-model="form.enabled" />
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900">{{ $t('Variable Mapping') }}</h3>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-sm rounded-md"
                        :disabled="webhookLoadingFields"
                        @click="refreshWebhookFields"
                    >
                        <span v-if="webhookLoadingFields">{{ $t('Refreshing...') }}</span>
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
                        {{ $t('No webhook payload received yet. Trigger a customer login to see available fields.') }}
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="button"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm rounded-md hover:shadow-md disabled:opacity-50"
                        :disabled="saving"
                        @click="save"
                    >
                        <span v-if="saving">{{ $t('Saving...') }}</span>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                    <div v-if="successMessage" class="text-sm text-green-700">{{ successMessage }}</div>
                    <div v-if="errorMessage" class="text-sm text-red-700">{{ errorMessage }}</div>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-lg p-6 mt-6">
                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ $t('Order Created') }}</h2>
                        <p class="text-sm text-gray-600">{{ $t('Send a message automatically when a Zid order is created.') }}</p>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Template') }}</label>
                        <BaseListbox v-model="orderForm.template_id" :options="props.templates" :placeholder="$t('Select template')" />
                        <div v-if="orderErrors.template_id" class="form-error text-[#b91c1c] text-xs mt-1">{{ orderErrors.template_id }}</div>
                    </div>
                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Recipient phone payload path') }}</label>
                        <input
                            v-model="orderForm.recipient_phone_payload_path"
                            type="text"
                            placeholder="customer.mobile"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                        />
                        <p class="text-xs text-gray-500 mt-1">{{ $t('Example: customer.mobile or customer.mobile_code+customer.mobile') }}</p>
                        <div v-if="orderErrors.recipient_phone_payload_path" class="form-error text-[#b91c1c] text-xs mt-1">
                            {{ orderErrors.recipient_phone_payload_path }}
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900">{{ $t('Automation Status') }}</h3>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-700">{{ orderForm.enabled ? $t('Enabled') : $t('Disabled') }}</span>
                        <FormToggleSwitch v-model="orderForm.enabled" />
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900">{{ $t('Variable Mapping') }}</h3>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-sm rounded-md"
                        :disabled="orderWebhookLoadingFields"
                        @click="refreshOrderWebhookFields"
                    >
                        <span v-if="orderWebhookLoadingFields">{{ $t('Refreshing...') }}</span>
                        <span v-else>{{ $t('Refresh webhook fields') }}</span>
                    </button>
                </div>

                <div v-if="orderTemplateVariablesLocal.length === 0" class="mt-4 text-sm text-gray-600">
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
                            <tr v-for="variable in orderTemplateVariablesLocal" :key="variable.key" class="border-t">
                                <td class="py-3 pr-4">
                                    <div class="font-medium text-gray-900">{{ variable.key }}</div>
                                    <div class="text-xs text-gray-500">{{ variable.placeholder }}</div>
                                </td>
                                <td class="py-3 pr-4">
                                    <select v-model="orderMappingsLocal[variable.key]"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                        <option value="">{{ $t('Select field') }}</option>
                                        <option v-for="field in orderWebhookFieldsLocal" :key="field.path" :value="field.path">
                                            {{ fieldLabel(field) }}
                                        </option>
                                    </select>
                                    <div v-if="orderErrors[`mappings.${variable.key}`]" class="form-error text-[#b91c1c] text-xs mt-1">
                                        {{ orderErrors[`mappings.${variable.key}`] }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="orderWebhookFieldsLocal.length === 0" class="mt-3 text-xs text-gray-500">
                        {{ $t('No webhook payload received yet. Trigger an order.create webhook to see available fields.') }}
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="button"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm rounded-md hover:shadow-md disabled:opacity-50"
                        :disabled="orderSaving"
                        @click="saveOrder"
                    >
                        <span v-if="orderSaving">{{ $t('Saving...') }}</span>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                    <div v-if="orderSuccessMessage" class="text-sm text-green-700">{{ orderSuccessMessage }}</div>
                    <div v-if="orderErrorMessage" class="text-sm text-red-700">{{ orderErrorMessage }}</div>
                </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-lg p-6 mt-6">
                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">{{ $t('Abandoned Cart') }}</h2>
                        <p class="text-sm text-gray-600">{{ $t('Send a reminder when a Zid customer abandons their cart.') }}</p>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Template') }}</label>
                        <BaseListbox v-model="abandonedForm.template_id" :options="props.templates" :placeholder="$t('Select template')" />
                        <div v-if="abandonedErrors.template_id" class="form-error text-[#b91c1c] text-xs mt-1">{{ abandonedErrors.template_id }}</div>
                    </div>
                    <div>
                        <FormInput
                            v-model="abandonedForm.recipient_phone_payload_path"
                            :name="$t('Recipient phone payload path')"
                            :placeholder="'customer_mobile'"
                            :type="'text'"
                            :error="abandonedErrors.recipient_phone_payload_path"
                        />
                        <p class="text-xs text-gray-500 mt-1">
                            {{ $t('Example: customer_mobile or customer_mobile_country+customer_mobile') }}
                        </p>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <FormInput
                            v-model="abandonedForm.send_delay_hours"
                            :name="$t('Send after (hours)')"
                            :placeholder="'0'"
                            :type="'number'"
                            :error="abandonedErrors.send_delay_hours"
                        />
                        <p class="text-xs text-gray-500 mt-1">
                            {{ $t('0 = send immediately after receiving the webhook. Otherwise the reminder is scheduled after the specified delay.') }}
                        </p>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900">{{ $t('Automation Status') }}</h3>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-700">{{ abandonedForm.enabled ? $t('Enabled') : $t('Disabled') }}</span>
                        <FormToggleSwitch v-model="abandonedForm.enabled" />
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900">{{ $t('Variable Mapping') }}</h3>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-sm rounded-md"
                        :disabled="abandonedLoadingFields"
                        @click="refreshAbandonedWebhookFields"
                    >
                        <span v-if="abandonedLoadingFields">{{ $t('Refreshing...') }}</span>
                        <span v-else>{{ $t('Refresh webhook fields') }}</span>
                    </button>
                </div>

                <div v-if="abandonedTemplateVariablesLocal.length === 0" class="mt-4 text-sm text-gray-600">
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
                            <tr v-for="variable in abandonedTemplateVariablesLocal" :key="variable.key" class="border-t">
                                <td class="py-3 pr-4">
                                    <div class="font-medium text-gray-900">{{ variable.key }}</div>
                                    <div class="text-xs text-gray-500">{{ variable.placeholder }}</div>
                                </td>
                                <td class="py-3 pr-4">
                                    <select v-model="abandonedMappingsLocal[variable.key]"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                        <option value="">{{ $t('Select field') }}</option>
                                        <option v-for="field in abandonedWebhookFieldsLocal" :key="field.path" :value="field.path">
                                            {{ fieldLabel(field) }}
                                        </option>
                                    </select>
                                    <div v-if="abandonedErrors[`mappings.${variable.key}`]" class="form-error text-[#b91c1c] text-xs mt-1">
                                        {{ abandonedErrors[`mappings.${variable.key}`] }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="abandonedWebhookFieldsLocal.length === 0" class="mt-3 text-xs text-gray-500">
                        {{ $t('No webhook payload received yet. Trigger an abandoned_cart.created webhook to see available fields.') }}
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="button"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm rounded-md hover:shadow-md disabled:opacity-50"
                        :disabled="abandonedSaving"
                        @click="saveAbandoned"
                    >
                        <span v-if="abandonedSaving">{{ $t('Saving...') }}</span>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                    <div v-if="abandonedSuccessMessage" class="text-sm text-green-700">{{ abandonedSuccessMessage }}</div>
                    <div v-if="abandonedErrorMessage" class="text-sm text-red-700">{{ abandonedErrorMessage }}</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import axios from 'axios';
import { reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '../Layout/App.vue';
import BaseListbox from '@/Components/BaseListbox.vue';
import FormToggleSwitch from '@/Components/FormToggleSwitch.vue';
import FormInput from '@/Components/FormInput.vue';

const props = defineProps({
    templates: {
        type: Array,
        default: () => []
    },
    customerLoginSetting: {
        type: Object,
        required: true
    },
    customerLoginTemplateVariables: {
        type: Array,
        default: () => []
    },
    customerLoginWebhookFields: {
        type: Array,
        default: () => []
    },
    customerLoginMappings: {
        type: Object,
        default: () => ({})
    },
    orderCreatedSetting: {
        type: Object,
        required: true
    },
    orderCreatedTemplateVariables: {
        type: Array,
        default: () => []
    },
    orderCreatedWebhookFields: {
        type: Array,
        default: () => []
    },
    orderCreatedMappings: {
        type: Object,
        default: () => ({})
    },
    abandonedSetting: {
        type: Object,
        required: true
    },
    abandonedTemplateVariables: {
        type: Array,
        default: () => []
    },
    abandonedWebhookFields: {
        type: Array,
        default: () => []
    },
    abandonedMappings: {
        type: Object,
        default: () => ({})
    },
});

const { t } = useI18n();

const form = ref({
    enabled: Boolean(props.customerLoginSetting.enabled),
    template_id: props.customerLoginSetting.template_id ?? null,
    recipient_phone_payload_path: props.customerLoginSetting.recipient_phone_payload_path ?? 'telephone',
});

const orderForm = ref({
    enabled: Boolean(props.orderCreatedSetting.enabled),
    template_id: props.orderCreatedSetting.template_id ?? null,
    recipient_phone_payload_path: props.orderCreatedSetting.recipient_phone_payload_path ?? 'customer.mobile',
});

const abandonedForm = ref({
    enabled: Boolean(props.abandonedSetting.enabled),
    template_id: props.abandonedSetting.template_id ?? null,
    recipient_phone_payload_path: props.abandonedSetting.recipient_phone_payload_path ?? 'customer_mobile',
    send_delay_hours: props.abandonedSetting.send_delay_hours ?? 0,
});

const templateVariablesLocal = ref([...props.customerLoginTemplateVariables]);
const webhookFieldsLocal = ref([...props.customerLoginWebhookFields]);
const mappingsLocal = reactive({ ...props.customerLoginMappings });

const orderTemplateVariablesLocal = ref([...props.orderCreatedTemplateVariables]);
const orderWebhookFieldsLocal = ref([...props.orderCreatedWebhookFields]);
const orderMappingsLocal = reactive({ ...props.orderCreatedMappings });

const abandonedTemplateVariablesLocal = ref([...props.abandonedTemplateVariables]);
const abandonedWebhookFieldsLocal = ref([...props.abandonedWebhookFields]);
const abandonedMappingsLocal = reactive({ ...props.abandonedMappings });

const errors = reactive({});
const orderErrors = reactive({});
const abandonedErrors = reactive({});
const saving = ref(false);
const orderSaving = ref(false);
const abandonedSaving = ref(false);
const successMessage = ref('');
const orderSuccessMessage = ref('');
const errorMessage = ref('');
const orderErrorMessage = ref('');
const abandonedSuccessMessage = ref('');
const abandonedErrorMessage = ref('');
const webhookLoadingFields = ref(false);
const orderWebhookLoadingFields = ref(false);
const abandonedLoadingFields = ref(false);
const templateVarsCache = {};

const ensureMappingKeys = (variables, target) => {
    if (!target) {
        return;
    }

    for (const variable of variables ?? []) {
        if (!(variable.key in target)) {
            target[variable.key] = '';
        }
    }
};

ensureMappingKeys(templateVariablesLocal.value, mappingsLocal);
ensureMappingKeys(orderTemplateVariablesLocal.value, orderMappingsLocal);
ensureMappingKeys(abandonedTemplateVariablesLocal.value, abandonedMappingsLocal);

const fieldLabel = (field) => {
    const sample = field.sample ? ` — ${field.sample}` : '';
    return `${field.path}${sample}`;
};

const fetchTemplateVariables = async (templateId) => {
    if (!templateId) {
        return [];
    }

    if (templateId in templateVarsCache) {
        return templateVarsCache[templateId];
    }

    const response = await axios.get(`/settings/zid/automations/template/${templateId}/variables`);
    templateVarsCache[templateId] = response?.data?.data?.variables ?? [];
    return templateVarsCache[templateId];
};

const loadTemplateVariables = async (templateId) => {
    if (!templateId) {
        templateVariablesLocal.value = [];
        return;
    }

    templateVariablesLocal.value = await fetchTemplateVariables(templateId);
    ensureMappingKeys(templateVariablesLocal.value);
};

watch(() => form.value.template_id, async (newId) => {
    await loadTemplateVariables(newId);
});

watch(() => orderForm.value.template_id, async (newId) => {
    if (!newId) {
        orderTemplateVariablesLocal.value = [];
        return;
    }

    orderTemplateVariablesLocal.value = await fetchTemplateVariables(newId);
    ensureMappingKeys(orderTemplateVariablesLocal.value, orderMappingsLocal);
});

watch(() => abandonedForm.value.template_id, async (newId) => {
    if (!newId) {
        abandonedTemplateVariablesLocal.value = [];
        return;
    }

    abandonedTemplateVariablesLocal.value = await fetchTemplateVariables(newId);
    ensureMappingKeys(abandonedTemplateVariablesLocal.value, abandonedMappingsLocal);
});

const refreshWebhookFields = async () => {
    webhookLoadingFields.value = true;
    try {
        const response = await axios.get('/settings/zid/automations/customer-login/webhook-fields');
        webhookFieldsLocal.value = response?.data?.data?.fields ?? [];
    } finally {
        webhookLoadingFields.value = false;
    }
};

const refreshOrderWebhookFields = async () => {
    orderWebhookLoadingFields.value = true;
    try {
        const response = await axios.get('/settings/zid/automations/order-created/webhook-fields');
        orderWebhookFieldsLocal.value = response?.data?.data?.fields ?? [];
    } finally {
        orderWebhookLoadingFields.value = false;
    }
};

const refreshAbandonedWebhookFields = async () => {
    abandonedLoadingFields.value = true;
    try {
        const response = await axios.get('/settings/zid/automations/abandoned-cart/webhook-fields');
        abandonedWebhookFieldsLocal.value = response?.data?.data?.fields ?? [];
    } finally {
        abandonedLoadingFields.value = false;
    }
};

const save = async () => {
    saving.value = true;
    successMessage.value = '';
    errorMessage.value = '';
    Object.keys(errors).forEach((key) => delete errors[key]);

    const mappingsArray = (templateVariablesLocal.value ?? []).map(v => ({
        template_variable: v.key,
        payload_path: mappingsLocal[v.key] ?? '',
    }));

    try {
        await axios.post('/settings/zid/automations/customer-login', {
            enabled: form.value.enabled,
            template_id: form.value.template_id,
            recipient_phone_payload_path: form.value.recipient_phone_payload_path,
            mappings: mappingsArray,
        });

        successMessage.value = t('Saved');
    } catch (e) {
        errorMessage.value = e?.response?.data?.message ?? t('Failed to save');

        const serverErrors = e?.response?.data?.errors ?? null;
        if (serverErrors && typeof serverErrors === 'object') {
            for (const [key, value] of Object.entries(serverErrors)) {
                const match = String(key).match(/^mappings\\.(\\d+)\\.(payload_path|template_variable)$/);
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

const saveOrder = async () => {
    orderSaving.value = true;
    orderSuccessMessage.value = '';
    orderErrorMessage.value = '';
    Object.keys(orderErrors).forEach((key) => delete orderErrors[key]);

    const mappingsArray = (orderTemplateVariablesLocal.value ?? []).map(v => ({
        template_variable: v.key,
        payload_path: orderMappingsLocal[v.key] ?? '',
    }));

    try {
        await axios.post('/settings/zid/automations/order-created', {
            enabled: orderForm.value.enabled,
            template_id: orderForm.value.template_id,
            recipient_phone_payload_path: orderForm.value.recipient_phone_payload_path,
            mappings: mappingsArray,
        });

        orderSuccessMessage.value = t('Saved');
    } catch (e) {
        orderErrorMessage.value = e?.response?.data?.message ?? t('Failed to save');

        const serverErrors = e?.response?.data?.errors ?? null;
        if (serverErrors && typeof serverErrors === 'object') {
            for (const [key, value] of Object.entries(serverErrors)) {
                const match = String(key).match(/^mappings\.(\d+)\.(payload_path|template_variable)$/);
                if (match) {
                    const idx = parseInt(match[1], 10);
                    const varKey = mappingsArray[idx]?.template_variable;
                    if (varKey) {
                        orderErrors[`mappings.${varKey}`] = Array.isArray(value) ? value[0] : value;
                        continue;
                    }
                }

                orderErrors[key] = Array.isArray(value) ? value[0] : value;
            }
        }

        if (e?.response?.status === 422 && e?.response?.data?.message?.startsWith('Missing mapping for')) {
            orderErrorMessage.value = e.response.data.message;
        }
    } finally {
        orderSaving.value = false;
    }
};

const saveAbandoned = async () => {
    abandonedSaving.value = true;
    abandonedSuccessMessage.value = '';
    abandonedErrorMessage.value = '';
    Object.keys(abandonedErrors).forEach((key) => delete abandonedErrors[key]);

    const mappingsArray = (abandonedTemplateVariablesLocal.value ?? []).map(v => ({
        template_variable: v.key,
        payload_path: abandonedMappingsLocal[v.key] ?? '',
    }));

    try {
        await axios.post('/settings/zid/automations/abandoned-cart', {
            enabled: abandonedForm.value.enabled,
            template_id: abandonedForm.value.template_id,
            recipient_phone_payload_path: abandonedForm.value.recipient_phone_payload_path,
            send_delay_hours: abandonedForm.value.send_delay_hours,
            mappings: mappingsArray,
        });

        abandonedSuccessMessage.value = t('Saved');
    } catch (e) {
        abandonedErrorMessage.value = e?.response?.data?.message ?? t('Failed to save');

        const serverErrors = e?.response?.data?.errors ?? null;
        if (serverErrors && typeof serverErrors === 'object') {
            for (const [key, value] of Object.entries(serverErrors)) {
                const match = String(key).match(/^mappings\.(\d+)\.(payload_path|template_variable)$/);
                if (match) {
                    const idx = parseInt(match[1], 10);
                    const varKey = mappingsArray[idx]?.template_variable;
                    if (varKey) {
                        abandonedErrors[`mappings.${varKey}`] = Array.isArray(value) ? value[0] : value;
                        continue;
                    }
                }

                abandonedErrors[key] = Array.isArray(value) ? value[0] : value;
            }
        }

        if (e?.response?.status === 422 && e?.response?.data?.message?.startsWith('Missing mapping for')) {
            abandonedErrorMessage.value = e.response.data.message;
        }
    } finally {
        abandonedSaving.value = false;
    }
};
</script>
