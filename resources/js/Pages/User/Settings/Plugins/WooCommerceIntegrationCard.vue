<template>
    <div class="space-y-5">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="space-y-1">
                <p class="text-lg font-semibold text-slate-900">{{ t('WooCommerce Integration') }}</p>
                <p class="text-sm text-slate-500">
                    {{ t('Provide the credentials below so the WooCommerce plugin can securely connect with your store.') }}
                </p>
            </div>
            <div class="flex items-center gap-3">
                <span :class="['rounded-full px-3 py-1 text-[11px] font-semibold tracking-wide', statusPillClass]">
                    {{ statusLabel }}
                </span>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-full border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-slate-300 hover:text-slate-800"
                    @click="toggleForm"
                    :aria-pressed="showForm.toString()"
                >
                    <span>{{ t('Form') }}</span>
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-3 w-3 transition-transform"
                        :class="showForm ? 'rotate-180' : ''"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                    >
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.23 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                    </svg>
                </button>
            </div>
        </div>

        <transition name="fade">
            <div v-if="showForm" class="rounded-2xl border border-slate-100 bg-slate-50 p-4 shadow-sm">
                <form @submit.prevent="submitForm" class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <FormInput
                            v-model="form.consumer_key"
                            :error="form.errors.consumer_key"
                            label="Consumer key"
                            placeholder="ck_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX"
                        />
                        <FormInput
                            v-model="form.consumer_secret"
                            :error="form.errors.consumer_secret"
                            label="Consumer secret"
                            placeholder="cs_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX"
                        />
                    </div>
                    <div class="space-y-1">
                        <FormInput
                            v-model="form.domain_link"
                            :error="form.errors.domain_link"
                            label="Domain link"
                            placeholder="https://example.com"
                        />
                        <p class="text-xs text-slate-500">
                            {{ t('Store URL (e.g., https://example.com)') }}
                        </p>
                    </div>
                    <div class="flex justify-end">
                        <button
                            type="submit"
                            class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="form.processing"
                        >
                            <span v-if="form.processing">{{ t('Saving...') }}</span>
                            <span v-else>{{ t('Save') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </transition>

        <div v-if="integration" class="space-y-4">
            <div class="rounded-2xl border border-slate-100 bg-white p-4 text-sm text-slate-600 shadow-sm">
                <div class="space-y-2">
                    <div class="flex flex-col gap-1">
                        <span class="font-semibold text-slate-800">{{ t('Store URL') }}</span>
                        <span class="text-slate-500 break-all">{{ integration.domain_link }}</span>
                    </div>
                    <div class="flex flex-col gap-1">
                        <span class="font-semibold text-slate-800">{{ t('Saved by') }}</span>
                        <span class="text-slate-500">{{ integration.user?.first_name ?? integration.user?.name ?? t('Unknown user') }}</span>
                    </div>
                    <div class="flex flex-col gap-1">
                        <span class="font-semibold text-slate-800">{{ t('Last updated') }}</span>
                        <span class="text-slate-500">{{ formattedUpdatedAt }}</span>
                    </div>
                </div>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="testLoading"
                    @click="testConnection"
                >
                    <svg v-if="testLoading" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                        <path d="M12 2a10 10 0 0 0 0 20" stroke-linecap="round" />
                    </svg>
                    <span v-if="testLoading">{{ t('Testing...') }}</span>
                    <span v-else>{{ t('Test connection') }}</span>
                </button>
                <div class="text-xs space-y-1">
                    <p v-if="testMessage" class="text-emerald-600">{{ testMessage }}</p>
                    <p v-if="testError" class="text-rose-600">{{ testError }}</p>
                </div>
            </div>
        </div>
        <div v-else class="rounded-2xl border border-dashed border-slate-200 bg-white/80 p-4 text-xs text-slate-500">
            {{ t('No WooCommerce credentials have been saved yet. Open the form to connect your store.') }}
        </div>
    </div>
</template>

<script setup>
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import FormInput from '@/Components/FormInput.vue';

const { t } = useI18n();
const page = usePage();
const integration = computed(() => page.props.woocommerceIntegration ?? null);

const showForm = ref(false);

const form = useForm({
    consumer_key: '',
    consumer_secret: '',
    domain_link: '',
});

const prepareForm = () => {
    form.consumer_key = integration.value?.consumer_key ?? '';
    form.consumer_secret = integration.value?.consumer_secret ?? '';
    form.domain_link = integration.value?.domain_link ?? '';
};

const toggleForm = () => {
    if (!showForm.value) {
        prepareForm();
    }
    showForm.value = !showForm.value;
};

watch(integration, () => {
    if (showForm.value) {
        prepareForm();
    }
});

const submitForm = () => {
    form.post('/settings/plugins/woocommerce', {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
        },
    });
};

const statusLabel = computed(() => (integration.value ? t('Connected') : t('Not connected')));
const statusPillClass = computed(() =>
    integration.value ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'
);

const formattedUpdatedAt = computed(() => {
    if (!integration.value?.updated_at) {
        return t('Not available');
    }
    try {
        return new Date(integration.value.updated_at).toLocaleString();
    } catch {
        return integration.value.updated_at;
    }
});

const testLoading = ref(false);
const testMessage = ref(null);
const testError = ref(null);

const testConnection = async () => {
    if (!integration.value) {
        testError.value = t('Save your WooCommerce credentials before running the test.');
        return;
    }

    testLoading.value = true;
    testMessage.value = null;
    testError.value = null;

    try {
        const response = await axios.post('/settings/plugins/woocommerce/test');
        testMessage.value = response.data.message ?? t('WooCommerce connection succeeded.');
    } catch (error) {
        testError.value = error.response?.data?.message ?? error.message ?? t('WooCommerce connection failed.');
    } finally {
        testLoading.value = false;
    }
};
</script>
