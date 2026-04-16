<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-10 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] h-full md:overflow-y-auto">
            <div class="flex justify-between mb-6">
                <div>
                    <h1 class="text-xl mb-1">{{ $t('WooCommerce Automations') }}</h1>
                    <p class="flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z" />
                        </svg>
                        <span class="ltr:ml-1 rtl:mr-1 mt-1">{{ $t('Automate WhatsApp replies for WooCommerce webhooks') }}</span>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="/settings/woocommerce/message-logs" class="px-3 py-2 rounded-md bg-white shadow-sm text-sm text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                        {{ $t('Message Logs') }}
                    </a>
                    <Link href="/settings/plugins" class="px-3 py-2 rounded-md bg-white shadow-sm text-sm text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                        {{ $t('Back to Plugins') }}
                    </Link>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-lg p-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="flex-1">
                        <h2 class="text-lg font-medium">{{ $t('New Order') }}</h2>
                        <p class="text-sm text-gray-600">{{ $t('Send a template message when a WooCommerce order is created') }}</p>
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

                    <!-- <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Recipient phone path') }}</label>
                        <input
                            type="text"
                            class="w-full border rounded-md px-3 py-2 focus:ring-1 focus:ring-primary focus:border-primary outline-none"
                            v-model="form.recipient_phone_payload_path"
                        />
                        <div v-if="errors.recipient_phone_payload_path" class="form-error text-[#b91c1c] text-xs mt-1">
                            {{ errors.recipient_phone_payload_path }}
                        </div>
                        <p class="text-xs text-gray-500 mt-1">{{ $t('Example: billing.phone or shipping.phone') }}</p>
                    </div> -->
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
                        {{ $t('No WooCommerce payload recorded yet. Trigger an order.created webhook to inspect fields.') }}
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
                        <h2 class="text-lg font-medium">{{ $t('Abandoned Cart (Add to Cart)') }}</h2>
                        <p class="text-sm text-gray-600">{{ $t('Send a template message when a customer adds products to cart.') }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-700">{{ addToCartForm.enabled ? $t('Enabled') : $t('Disabled') }}</span>
                        <FormToggleSwitch v-model="addToCartForm.enabled" />
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Template') }}</label>
                        <BaseListbox v-model="addToCartForm.template_id" :options="templates" :placeholder="$t('Select template')" />
                        <div v-if="addToCartErrors.template_id" class="form-error text-[#b91c1c] text-xs mt-1">{{ addToCartErrors.template_id }}</div>
                    </div>

                    <!-- <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Recipient phone path') }}</label>
                        <input
                            type="text"
                            class="w-full border rounded-md px-3 py-2 focus:ring-1 focus:ring-primary focus:border-primary outline-none"
                            v-model="addToCartForm.recipient_phone_payload_path"
                        />
                        <div v-if="addToCartErrors.recipient_phone_payload_path" class="form-error text-[#b91c1c] text-xs mt-1">
                            {{ addToCartErrors.recipient_phone_payload_path }}
                        </div>
                        <p class="text-xs text-gray-500 mt-1">{{ $t('Example: chatberry_add_to_cart.customer.billing.phone') }}</p>
                    </div> -->

                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Send after (hours)') }}</label>
                        <input
                            type="number"
                            min="0"
                            max="720"
                            class="w-full border rounded-md px-3 py-2 focus:ring-1 focus:ring-primary focus:border-primary outline-none"
                            v-model.number="addToCartForm.delay_hours"
                        />
                        <div v-if="addToCartErrors.delay_hours" class="form-error text-[#b91c1c] text-xs mt-1">
                            {{ addToCartErrors.delay_hours }}
                        </div>
                        <p class="text-xs text-gray-500 mt-1">{{ $t('0 means immediate send. Recommended for abandoned-cart flow: 1-24 hours.') }}</p>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900">{{ $t('Variable Mapping') }}</h3>
                    <button type="button"
                        class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-sm rounded-md"
                        :disabled="addToCartLoadingFields"
                        @click="refreshAddToCartWebhookFields">
                        <span v-if="addToCartLoadingFields">{{ $t('Refreshing...') }}</span>
                        <span v-else>{{ $t('Refresh webhook fields') }}</span>
                    </button>
                </div>

                <div v-if="addToCartTemplateVariablesLocal.length === 0" class="mt-4 text-sm text-gray-600">
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
                            <tr v-for="variable in addToCartTemplateVariablesLocal" :key="variable.key" class="border-t">
                                <td class="py-3 pr-4">
                                    <div class="font-medium text-gray-900">{{ variable.key }}</div>
                                    <div class="text-xs text-gray-500">{{ variable.placeholder }}</div>
                                </td>
                                <td class="py-3 pr-4">
                                    <select v-model="addToCartMappingsLocal[variable.key]"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                        <option value="">{{ $t('Select field') }}</option>
                                        <option v-for="field in addToCartWebhookFieldsLocal" :key="field.path" :value="field.path">
                                            {{ fieldLabel(field) }}
                                        </option>
                                    </select>
                                    <div v-if="addToCartErrors[`mappings.${variable.key}`]" class="form-error text-[#b91c1c] text-xs mt-1">
                                        {{ addToCartErrors[`mappings.${variable.key}`] }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="addToCartWebhookFieldsLocal.length === 0" class="mt-3 text-xs text-gray-500">
                        {{ $t('No add-to-cart payload recorded yet. Trigger action.woocommerce_add_to_cart to inspect fields.') }}
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="button"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm rounded-md hover:shadow-md disabled:opacity-50"
                        :disabled="addToCartSaving"
                        @click="saveAddToCart">
                        <span v-if="addToCartSaving">{{ $t('Saving...') }}</span>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                    <div v-if="addToCartSuccessMessage" class="text-sm text-green-700">{{ addToCartSuccessMessage }}</div>
                    <div v-if="addToCartErrorMessage" class="text-sm text-red-700">{{ addToCartErrorMessage }}</div>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-lg p-6 mt-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="flex-1">
                        <h2 class="text-lg font-medium">{{ $t('Order Updated') }}</h2>
                        <p class="text-sm text-gray-600">{{ $t('Send a template message when a WooCommerce order is updated') }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-700">{{ updatedForm.enabled ? $t('Enabled') : $t('Disabled') }}</span>
                        <FormToggleSwitch v-model="updatedForm.enabled" />
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-4 gap-3 border border-slate-200 rounded-lg p-3 bg-slate-50">
                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-1">{{ $t('Rule Status') }}</label>
                        <select
                            v-model="ruleForm.status"
                            class="w-full border rounded-md px-3 py-2 focus:ring-1 focus:ring-primary focus:border-primary outline-none"
                        >
                            <option v-for="status in orderUpdatedStatuses" :key="status" :value="status">
                                {{ status }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-1">{{ $t('Rule Template') }}</label>
                        <BaseListbox v-model="ruleForm.template_id" :options="templates" :placeholder="$t('Select template')" />
                    </div>
                    <div class="md:col-span-2 flex items-end gap-2">
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm rounded-md hover:shadow-md disabled:opacity-50"
                            :disabled="ruleSaving || !ruleForm.status"
                            @click="saveOrderUpdatedRule"
                        >
                            <span v-if="ruleSaving">{{ $t('Saving...') }}</span>
                            <span v-else>{{ $t('Add/Update Rule') }}</span>
                        </button>
                        <span v-if="ruleMessage" class="text-xs text-emerald-700">{{ ruleMessage }}</span>
                        <span v-if="ruleError" class="text-xs text-rose-700">{{ ruleError }}</span>
                    </div>
                </div>

                <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-2">
                    <div
                        v-for="status in orderUpdatedStatuses"
                        :key="`rule-${status}`"
                        class="flex items-center justify-between border rounded-md px-3 py-2 text-sm"
                    >
                        <span class="font-medium">{{ status }}</span>
                        <span class="text-xs text-slate-600">
                            {{ orderUpdatedStatusTemplateName(status) }}
                        </span>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Order status') }}</label>
                        <select
                            v-model="selectedOrderUpdatedStatus"
                            class="w-full border rounded-md px-3 py-2 focus:ring-1 focus:ring-primary focus:border-primary outline-none"
                        >
                            <option v-for="status in orderUpdatedStatuses" :key="status" :value="status">
                                {{ status }}
                            </option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">{{ $t('Each status can use a different template and mapping.') }}</p>
                    </div>

                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Template') }}</label>
                        <BaseListbox v-model="updatedForm.template_id" :options="templates" :placeholder="$t('Select template')" />
                        <div v-if="updatedErrors.template_id" class="form-error text-[#b91c1c] text-xs mt-1">{{ updatedErrors.template_id }}</div>
                    </div>

                    <!-- <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Recipient phone path') }}</label>
                        <input
                            type="text"
                            class="w-full border rounded-md px-3 py-2 focus:ring-1 focus:ring-primary focus:border-primary outline-none"
                            v-model="updatedForm.recipient_phone_payload_path"
                        />
                        <div v-if="updatedErrors.recipient_phone_payload_path" class="form-error text-[#b91c1c] text-xs mt-1">
                            {{ updatedErrors.recipient_phone_payload_path }}
                        </div>
                        <p class="text-xs text-gray-500 mt-1">{{ $t('Example: billing.phone or shipping.phone') }}</p>
                    </div> -->
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900">{{ $t('Variable Mapping') }}</h3>
                    <button type="button"
                        class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-sm rounded-md"
                        :disabled="updatedLoadingFields"
                        @click="refreshUpdatedWebhookFields">
                        <span v-if="updatedLoadingFields">{{ $t('Refreshing...') }}</span>
                        <span v-else>{{ $t('Refresh webhook fields') }}</span>
                    </button>
                </div>

                <div v-if="updatedTemplateVariablesLocal.length === 0" class="mt-4 text-sm text-gray-600">
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
                            <tr v-for="variable in updatedTemplateVariablesLocal" :key="variable.key" class="border-t">
                                <td class="py-3 pr-4">
                                    <div class="font-medium text-gray-900">{{ variable.key }}</div>
                                    <div class="text-xs text-gray-500">{{ variable.placeholder }}</div>
                                </td>
                                <td class="py-3 pr-4">
                                    <select v-model="updatedMappingsLocal[variable.key]"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                        <option value="">{{ $t('Select field') }}</option>
                                        <option v-for="field in updatedWebhookFieldsLocal" :key="field.path" :value="field.path">
                                            {{ fieldLabel(field) }}
                                        </option>
                                    </select>
                                    <div v-if="updatedErrors[`mappings.${variable.key}`]" class="form-error text-[#b91c1c] text-xs mt-1">
                                        {{ updatedErrors[`mappings.${variable.key}`] }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="updatedWebhookFieldsLocal.length === 0" class="mt-3 text-xs text-gray-500">
                        {{ $t('No WooCommerce payload recorded yet. Trigger an order.updated webhook to inspect fields.') }}
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="button"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm rounded-md hover:shadow-md disabled:opacity-50"
                        :disabled="updatedSaving"
                        @click="saveUpdated">
                        <span v-if="updatedSaving">{{ $t('Saving...') }}</span>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                    <div v-if="updatedSuccessMessage" class="text-sm text-green-700">{{ updatedSuccessMessage }}</div>
                    <div v-if="updatedErrorMessage" class="text-sm text-red-700">{{ updatedErrorMessage }}</div>
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
        default: () => ({ enabled: false, template_id: null, recipient_phone_payload_path: 'billing.phone' })
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
    addToCartSetting: {
        type: Object,
        default: () => ({ enabled: false, template_id: null, recipient_phone_payload_path: 'chatberry_add_to_cart.customer.billing.phone', delay_hours: 0 })
    },
    addToCartTemplateVariables: {
        type: Array,
        default: () => []
    },
    addToCartWebhookFields: {
        type: Array,
        default: () => []
    },
    addToCartMappings: {
        type: Object,
        default: () => ({})
    },
    orderUpdatedStatuses: {
        type: Array,
        default: () => ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed']
    },
    orderUpdatedStatusConfigs: {
        type: Object,
        default: () => ({})
    },
});
const orderUpdatedStatuses = props.orderUpdatedStatuses ?? [];
const configuredOrderUpdatedStatus = orderUpdatedStatuses.find((status) => {
    const config = props.orderUpdatedStatusConfigs?.[status];
    return !!config?.enabled;
});
const statusConfigsLocal = reactive({ ...(props.orderUpdatedStatusConfigs ?? {}) });

const form = reactive({
    enabled: !!props.setting.enabled,
    template_id: props.setting.template_id ?? null,
    recipient_phone_payload_path: props.setting.recipient_phone_payload_path ?? 'billing.phone',
});

const templateVariablesLocal = ref(props.templateVariables ?? []);
const webhookFieldsLocal = ref(props.webhookFields ?? []);
const mappingsLocal = reactive({ ...(props.mappings ?? {}) });
for (const variable of templateVariablesLocal.value ?? []) {
    if (!(variable.key in mappingsLocal)) {
        mappingsLocal[variable.key] = '';
    }
}

const addToCartForm = reactive({
    enabled: !!props.addToCartSetting.enabled,
    template_id: props.addToCartSetting.template_id ?? null,
    recipient_phone_payload_path: props.addToCartSetting.recipient_phone_payload_path ?? 'chatberry_add_to_cart.customer.billing.phone',
    delay_hours: Number.isFinite(Number(props.addToCartSetting.delay_hours)) ? Number(props.addToCartSetting.delay_hours) : 0,
});
const addToCartTemplateVariablesLocal = ref(props.addToCartTemplateVariables ?? []);
const addToCartWebhookFieldsLocal = ref(props.addToCartWebhookFields ?? []);
const addToCartMappingsLocal = reactive({ ...(props.addToCartMappings ?? {}) });
for (const variable of addToCartTemplateVariablesLocal.value ?? []) {
    if (!(variable.key in addToCartMappingsLocal)) {
        addToCartMappingsLocal[variable.key] = '';
    }
}
const addToCartSaving = ref(false);
const addToCartSuccessMessage = ref('');
const addToCartErrorMessage = ref('');
const addToCartLoadingFields = ref(false);
const addToCartErrors = reactive({});

const selectedOrderUpdatedStatus = ref(configuredOrderUpdatedStatus ?? orderUpdatedStatuses[0] ?? 'completed');
const ruleForm = reactive({
    status: configuredOrderUpdatedStatus ?? orderUpdatedStatuses[0] ?? 'completed',
    template_id: null,
});
const ruleSaving = ref(false);
const ruleMessage = ref('');
const ruleError = ref('');
ruleForm.template_id = statusConfigsLocal?.[ruleForm.status]?.template_id ?? null;

const updatedForm = reactive({
    enabled: false,
    template_id: null,
    recipient_phone_payload_path: 'billing.phone',
});

const updatedTemplateVariablesLocal = ref([]);
const updatedWebhookFieldsLocal = ref([]);
const updatedMappingsLocal = reactive({});
for (const variable of updatedTemplateVariablesLocal.value ?? []) {
    if (!(variable.key in updatedMappingsLocal)) {
        updatedMappingsLocal[variable.key] = '';
    }
}

const updatedSaving = ref(false);
const updatedSuccessMessage = ref('');
const updatedErrorMessage = ref('');
const updatedLoadingFields = ref(false);
const updatedErrors = reactive({});

const saving = ref(false);
const successMessage = ref('');
const errorMessage = ref('');
const loadingFields = ref(false);
const errors = reactive({});
const templateVarsCache = reactive({});

const stringifySample = (value) => {
    try {
        if (typeof value === 'string') {
            return value.length > 60 ? value.slice(0, 60) + '...' : value;
        }
        if (typeof value === 'number' || typeof value === 'boolean') {
            return String(value);
        }
        if (value === null || value === undefined) {
            return '';
        }
        const json = JSON.stringify(value);
        return json.length > 60 ? json.slice(0, 60) + '...' : json;
    } catch (e) {
        return '';
    }
};

const fieldLabel = (field) => {
    const path = field?.path ?? '';
    const label = field?.label ?? path;
    const sample = field?.sample ?? null;
    const sampleText = stringifySample(sample);

    return sampleText ? `${label} - ${sampleText}` : label;
};

const orderUpdatedStatusTemplateName = (status) => {
    const config = statusConfigsLocal?.[status];
    if (!config?.enabled || !config?.template_id) {
        return 'Not configured';
    }
    const template = (props.templates ?? []).find((t) => Number(t.value) === Number(config.template_id));
    return template?.label ?? `Template #${config.template_id}`;
};

const fetchTemplateVariables = async (templateId) => {
    if (!templateId) {
        return [];
    }

    if (templateId in templateVarsCache) {
        return templateVarsCache[templateId] ?? [];
    }

    const res = await axios.get(`/settings/woocommerce/automations/template/${templateId}/variables`);
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

const loadAddToCartTemplateVariables = async (templateId) => {
    if (!templateId) {
        addToCartTemplateVariablesLocal.value = [];
        return;
    }

    addToCartTemplateVariablesLocal.value = await fetchTemplateVariables(templateId);

    for (const variable of addToCartTemplateVariablesLocal.value) {
        if (!(variable.key in addToCartMappingsLocal)) {
            addToCartMappingsLocal[variable.key] = '';
        }
    }
};

const loadUpdatedTemplateVariables = async (templateId) => {
    if (!templateId) {
        updatedTemplateVariablesLocal.value = [];
        return;
    }

    updatedTemplateVariablesLocal.value = await fetchTemplateVariables(templateId);

    for (const variable of updatedTemplateVariablesLocal.value) {
        if (!(variable.key in updatedMappingsLocal)) {
            updatedMappingsLocal[variable.key] = '';
        }
    }
};

const applyUpdatedConfig = (payload) => {
    const setting = payload?.setting ?? {};
    updatedForm.enabled = !!setting.enabled;
    updatedForm.template_id = setting.template_id ?? null;
    updatedForm.recipient_phone_payload_path = setting.recipient_phone_payload_path ?? 'billing.phone';

    updatedTemplateVariablesLocal.value = payload?.templateVariables ?? [];
    updatedWebhookFieldsLocal.value = payload?.webhookFields ?? [];

    Object.keys(updatedMappingsLocal).forEach((key) => delete updatedMappingsLocal[key]);
    const mappings = payload?.mappings ?? {};
    Object.entries(mappings).forEach(([key, value]) => {
        updatedMappingsLocal[key] = value;
    });

    for (const variable of updatedTemplateVariablesLocal.value ?? []) {
        if (!(variable.key in updatedMappingsLocal)) {
            updatedMappingsLocal[variable.key] = '';
        }
    }

    if (selectedOrderUpdatedStatus.value) {
        statusConfigsLocal[selectedOrderUpdatedStatus.value] = {
            ...(statusConfigsLocal[selectedOrderUpdatedStatus.value] ?? {}),
            enabled: !!updatedForm.enabled,
            template_id: updatedForm.template_id ?? null,
            recipient_phone_payload_path: updatedForm.recipient_phone_payload_path ?? 'billing.phone',
        };
    }
};

const loadOrderUpdatedStatusConfig = async (status) => {
    const res = await axios.get(`/settings/woocommerce/automations/order-updated/${encodeURIComponent(status)}/config`);
    applyUpdatedConfig(res?.data?.data ?? {});
};

watch(() => form.template_id, async (newId) => {
    await loadTemplateVariables(newId);
});

watch(() => addToCartForm.template_id, async (newId, oldId) => {
    if (newId === oldId) {
        return;
    }
    await loadAddToCartTemplateVariables(newId);
});

watch(() => selectedOrderUpdatedStatus.value, async (status) => {
    if (!status) {
        return;
    }
    ruleForm.status = status;
    await loadOrderUpdatedStatusConfig(status);
}, { immediate: true });

watch(() => ruleForm.status, async (status) => {
    if (!status) {
        return;
    }
    if (selectedOrderUpdatedStatus.value !== status) {
        selectedOrderUpdatedStatus.value = status;
        await loadOrderUpdatedStatusConfig(status);
    }
    ruleForm.template_id = statusConfigsLocal?.[status]?.template_id ?? null;
});

watch(() => updatedForm.template_id, async (newId, oldId) => {
    if (newId === oldId) {
        return;
    }
    await loadUpdatedTemplateVariables(newId);
});

const saveOrderUpdatedRule = async () => {
    if (!ruleForm.status) {
        return;
    }

    ruleSaving.value = true;
    ruleMessage.value = '';
    ruleError.value = '';

    try {
        const currentConfig = statusConfigsLocal?.[ruleForm.status] ?? {};
        await axios.post(`/settings/woocommerce/automations/order-updated/${encodeURIComponent(ruleForm.status)}`, {
            enabled: true,
            template_id: ruleForm.template_id,
            recipient_phone_payload_path: currentConfig.recipient_phone_payload_path ?? 'billing.phone',
            quick_rule: true,
            mappings: [],
        });

        statusConfigsLocal[ruleForm.status] = {
            ...(statusConfigsLocal[ruleForm.status] ?? {}),
            enabled: true,
            template_id: ruleForm.template_id,
        };

        selectedOrderUpdatedStatus.value = ruleForm.status;
        await loadOrderUpdatedStatusConfig(ruleForm.status);
        ruleMessage.value = 'Rule saved';
    } catch (e) {
        ruleError.value = e?.response?.data?.message ?? 'Failed to save rule';
    } finally {
        ruleSaving.value = false;
    }
};

const refreshWebhookFields = async () => {
    loadingFields.value = true;
    try {
        const res = await axios.get('/settings/woocommerce/automations/order-created/webhook-fields');
        webhookFieldsLocal.value = res?.data?.data?.fields ?? [];
    } finally {
        loadingFields.value = false;
    }
};

const refreshAddToCartWebhookFields = async () => {
    addToCartLoadingFields.value = true;
    try {
        const res = await axios.get('/settings/woocommerce/automations/add-to-cart/webhook-fields');
        addToCartWebhookFieldsLocal.value = res?.data?.data?.fields ?? [];
    } finally {
        addToCartLoadingFields.value = false;
    }
};

const refreshUpdatedWebhookFields = async () => {
    updatedLoadingFields.value = true;
    try {
        const res = await axios.get(`/settings/woocommerce/automations/order-updated/${encodeURIComponent(selectedOrderUpdatedStatus.value)}/webhook-fields`);
        updatedWebhookFieldsLocal.value = res?.data?.data?.fields ?? [];
    } finally {
        updatedLoadingFields.value = false;
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
        await axios.post('/settings/woocommerce/automations/order-created', {
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

const saveAddToCart = async () => {
    addToCartSaving.value = true;
    addToCartSuccessMessage.value = '';
    addToCartErrorMessage.value = '';
    Object.keys(addToCartErrors).forEach((k) => delete addToCartErrors[k]);

    const mappingsArray = (addToCartTemplateVariablesLocal.value ?? []).map(v => ({
        template_variable: v.key,
        payload_path: addToCartMappingsLocal[v.key] ?? '',
    }));

    try {
        await axios.post('/settings/woocommerce/automations/add-to-cart', {
            enabled: addToCartForm.enabled,
            template_id: addToCartForm.template_id,
            recipient_phone_payload_path: addToCartForm.recipient_phone_payload_path,
            delay_hours: addToCartForm.delay_hours ?? 0,
            mappings: mappingsArray,
        });

        addToCartSuccessMessage.value = 'Saved';
    } catch (e) {
        addToCartErrorMessage.value = e?.response?.data?.message ?? 'Failed to save';

        const serverErrors = e?.response?.data?.errors ?? null;
        if (serverErrors && typeof serverErrors === 'object') {
            for (const [key, value] of Object.entries(serverErrors)) {
                const match = String(key).match(/^mappings\.(\d+)\.(payload_path|template_variable)$/);
                if (match) {
                    const idx = parseInt(match[1], 10);
                    const varKey = mappingsArray[idx]?.template_variable;
                    if (varKey) {
                        addToCartErrors[`mappings.${varKey}`] = Array.isArray(value) ? value[0] : value;
                        continue;
                    }
                }

                addToCartErrors[key] = Array.isArray(value) ? value[0] : value;
            }
        }
    } finally {
        addToCartSaving.value = false;
    }
};

const saveUpdated = async () => {
    updatedSaving.value = true;
    updatedSuccessMessage.value = '';
    updatedErrorMessage.value = '';
    Object.keys(updatedErrors).forEach((k) => delete updatedErrors[k]);

    const mappingsArray = (updatedTemplateVariablesLocal.value ?? []).map(v => ({
        template_variable: v.key,
        payload_path: updatedMappingsLocal[v.key] ?? '',
    }));

    try {
        await axios.post(`/settings/woocommerce/automations/order-updated/${encodeURIComponent(selectedOrderUpdatedStatus.value)}`, {
            enabled: updatedForm.enabled,
            template_id: updatedForm.template_id,
            recipient_phone_payload_path: updatedForm.recipient_phone_payload_path,
            mappings: mappingsArray,
        });

        updatedSuccessMessage.value = 'Saved';
    } catch (e) {
        updatedErrorMessage.value = e?.response?.data?.message ?? 'Failed to save';

        const serverErrors = e?.response?.data?.errors ?? null;
        if (serverErrors && typeof serverErrors === 'object') {
            for (const [key, value] of Object.entries(serverErrors)) {
                const match = String(key).match(/^mappings\.(\d+)\.(payload_path|template_variable)$/);
                if (match) {
                    const idx = parseInt(match[1], 10);
                    const varKey = mappingsArray[idx]?.template_variable;
                    if (varKey) {
                        updatedErrors[`mappings.${varKey}`] = Array.isArray(value) ? value[0] : value;
                        continue;
                    }
                }

                updatedErrors[key] = Array.isArray(value) ? value[0] : value;
            }
        }

        if (e?.response?.status === 422 && e?.response?.data?.message?.startsWith('Missing mapping for')) {
            updatedErrorMessage.value = e.response.data.message;
        }
    } finally {
        updatedSaving.value = false;
    }
};
</script>


