<template>
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <button
            type="button"
            class="flex w-full items-center justify-between px-4 py-3 text-left hover:bg-slate-50 transition"
            @click="toggleExpanded"
            :aria-expanded="isExpanded ? 'true' : 'false'"
            :disabled="!collapsible"
        >
            <span class="text-base font-semibold text-slate-900">{{ t('WooCommerce Integration') }}</span>
            <svg
                v-if="collapsible"
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4 text-slate-500 transition-transform"
                :class="isExpanded ? 'rotate-180' : ''"
                viewBox="0 0 20 20"
                fill="currentColor"
            >
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.23 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
            </svg>
        </button>

        <transition name="fade">
            <div v-if="isExpanded" class="space-y-5 border-t border-slate-100 p-4">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-slate-500">
                        {{ t('Provide your WooCommerce credentials and manage automations from this section.') }}
                    </p>
                    <span :class="['rounded-full px-3 py-1 text-[11px] font-semibold tracking-wide', statusPillClass]">
                        {{ statusLabel }}
                    </span>
                </div>

                <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4 shadow-sm">
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

                <div v-if="integration" class="space-y-6">
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

                    <div v-if="automationsUrl" class="rounded-2xl border border-slate-100 bg-white p-4 text-sm text-slate-600 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-base font-semibold text-slate-900">{{ t('WooCommerce Automations') }}</p>
                                <p class="text-xs text-slate-500">{{ t('Manage templates and automation rules for WooCommerce events.') }}</p>
                            </div>
                            <Link
                                :href="automationsUrl"
                                class="inline-flex items-center gap-2 rounded-md border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 hover:border-slate-300 hover:text-slate-800"
                            >
                                {{ t('Open automations') }}
                            </Link>
                        </div>
                    </div>

                    <section class="bg-white border border-slate-200 rounded-lg p-6">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">{{ $t('Message Logs') }}</h2>
                                <p class="text-sm text-slate-500">{{ $t('Review all WhatsApp messages sent by WooCommerce automations.') }}</p>
                            </div>
                            <Link href="/settings/woocommerce/message-logs" class="inline-flex items-center gap-2 text-sm font-semibold text-blue-600 hover:text-blue-800">
                                {{ $t('Open Logs') }}
                            </Link>
                        </div>
                    </section>

                    <div class="space-y-4 rounded-2xl border border-slate-100 bg-white p-4 text-sm text-slate-600 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-base font-semibold text-slate-900">{{ t('Subscriptions') }}</p>
                                <p class="text-xs text-slate-500">{{ t('Enable or disable the events you want to receive.') }}</p>
                            </div>
                        </div>
                        <div class="space-y-3">
                            <div
                                v-for="topic in TOPICS"
                                :key="topic.key"
                                class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div class="space-y-1">
                                    <p class="text-sm font-semibold text-slate-800">{{ topic.label }}</p>
                                    <p class="text-xs text-slate-500">{{ topic.description }}</p>
                                    <p class="text-[11px] text-slate-400">{{ topic.key }}</p>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-md px-4 py-2 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-60"
                                    :class="isTopicActive(topic.key) ? 'border border-rose-200 bg-rose-50 text-rose-700 hover:border-rose-300' : 'border border-emerald-200 bg-emerald-50 text-emerald-700 hover:border-emerald-300'"
                                    :disabled="toggleLoading[topic.key]"
                                    @click="toggleTopic(topic.key)"
                                >
                                    <svg v-if="toggleLoading[topic.key]" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                        <path d="M12 2a10 10 0 0 0 0 20" stroke-linecap="round" />
                                    </svg>
                                    <span v-else>{{ isTopicActive(topic.key) ? t('Disable') : t('Enable') }}</span>
                                </button>
                            </div>
                        </div>
                        <div class="text-xs space-y-1">
                            <p v-if="toggleMessage" class="text-emerald-600">{{ toggleMessage }}</p>
                            <p v-if="toggleError" class="text-rose-600">{{ toggleError }}</p>
                        </div>
                    </div>
                </div>
                <div v-else class="rounded-2xl border border-dashed border-slate-200 bg-white/80 p-4 text-xs text-slate-500">
                    {{ t('No WooCommerce credentials have been saved yet. Fill in the form to connect your store.') }}
                </div>
            </div>
        </transition>
    </div>
</template>

<script setup>
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import FormInput from '@/Components/FormInput.vue';

const props = defineProps({
    expandedByDefault: {
        type: Boolean,
        default: false,
    },
    collapsible: {
        type: Boolean,
        default: true,
    },
});

const { t } = useI18n();
const page = usePage();
const integration = computed(() => page.props.woocommerceIntegration ?? null);
const automationsUrl = computed(() => page.props.woocommerceAutomationsUrl ?? '');

const isExpanded = ref(props.collapsible ? props.expandedByDefault : true);
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

const toggleExpanded = () => {
    if (!props.collapsible) {
        return;
    }
    if (!isExpanded.value) {
        prepareForm();
    }
    isExpanded.value = !isExpanded.value;
};

watch(integration, () => {
    if (isExpanded.value) {
        prepareForm();
    }
});

onMounted(() => {
    if (isExpanded.value) {
        prepareForm();
    }
});

const submitForm = () => {
    form.post('/settings/plugins/woocommerce', {
        preserveScroll: true,
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

const TOPICS = [
    {
        key: 'order.created',
        label: t('Order Created'),
        description: t('Fires when a new order is placed in WooCommerce.'),
    },
    
    {
        key: 'action.woocommerce_add_to_cart',
        label: t('Add to Cart'),
        description: t('Fires when a customer adds a product to the cart.'),
    },
    {
        key: 'order.updated',
        label: t('Order Status Updated'),
        description: t('Fires when an order status is updated in WooCommerce.'),
    },
];

const subscriptionMap = ref({});
const toggleLoading = ref({});
const toggleMessage = ref(null);
const toggleError = ref(null);
const testLoading = ref(false);
const testMessage = ref(null);
const testError = ref(null);

const setSubscriptionMap = () => {
    const map = {};
    const subscriptions = page.props.woocommerceWebhookSubscriptions ?? [];
    subscriptions.forEach((subscription) => {
        map[subscription.topic] = subscription;
    });
    subscriptionMap.value = map;
};

setSubscriptionMap();

watch(
    () => page.props.woocommerceWebhookSubscriptions,
    () => {
        setSubscriptionMap();
    },
    { deep: true }
);

const isTopicActive = (topic) => subscriptionMap.value[topic]?.status === 'active';

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

const toggleTopic = async (topic) => {
    if (!integration.value) {
        toggleError.value = t('Save your WooCommerce credentials before managing subscriptions.');
        return;
    }

    toggleLoading.value = { ...toggleLoading.value, [topic]: true };
    toggleMessage.value = null;
    toggleError.value = null;

    try {
        if (isTopicActive(topic)) {
            await axios.delete(`/settings/plugins/woocommerce/subscriptions/${encodeURIComponent(topic)}`);
            const updated = { ...subscriptionMap.value };
            delete updated[topic];
            subscriptionMap.value = updated;
            toggleMessage.value = t('Subscription disabled for {topic}.', { topic });
        } else {
            const response = await axios.post('/settings/plugins/woocommerce/subscriptions', { topic });
            const payload = response.data.subscription ?? { topic, status: 'active' };
            subscriptionMap.value = {
                ...subscriptionMap.value,
                [topic]: payload,
            };
            toggleMessage.value = response.data.message ?? t('Subscription enabled for {topic}.', { topic });
        }
    } catch (error) {
        toggleError.value =
            error.response?.data?.reason ??
            error.response?.data?.message ??
            error.message ??
            t('Unable to update subscription.');
    } finally {
        toggleLoading.value = { ...toggleLoading.value, [topic]: false };
    }
};
</script>

