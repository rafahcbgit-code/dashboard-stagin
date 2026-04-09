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
});

const { t } = useI18n();

const form = ref({
    enabled: Boolean(props.customerLoginSetting.enabled),
    template_id: props.customerLoginSetting.template_id ?? null,
    recipient_phone_payload_path: props.customerLoginSetting.recipient_phone_payload_path ?? 'telephone',
});

const templateVariablesLocal = ref([...props.customerLoginTemplateVariables]);
const webhookFieldsLocal = ref([...props.customerLoginWebhookFields]);
const mappingsLocal = reactive({ ...props.customerLoginMappings });

const errors = reactive({});
const saving = ref(false);
const successMessage = ref('');
const errorMessage = ref('');
const webhookLoadingFields = ref(false);
const templateVarsCache = {};

const ensureMappingKeys = (variables) => {
    for (const variable of variables ?? []) {
        if (!(variable.key in mappingsLocal)) {
            mappingsLocal[variable.key] = '';
        }
    }
};

ensureMappingKeys(templateVariablesLocal.value);

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

const refreshWebhookFields = async () => {
    webhookLoadingFields.value = true;
    try {
        const response = await axios.get('/settings/zid/automations/customer-login/webhook-fields');
        webhookFieldsLocal.value = response?.data?.data?.fields ?? [];
    } finally {
        webhookLoadingFields.value = false;
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
</script>
