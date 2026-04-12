<template>
    <SettingLayout :modules="props.modules">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center mb-8">
                <div class="md:w-[60em] space-y-6">
                    <div class="bg-white border border-slate-200 rounded-lg p-6">
                        <div class="flex items-start justify-between gap-6">
                            <div class="max-w-[32rem] space-y-2">
                                <h1 class="text-xl font-semibold text-gray-900">{{ $t('Zid Integration') }}</h1>
                                <p class="text-sm text-slate-500">
                                    {{ $t('Connect your Zid store, keep subscriptions healthy, and trigger WhatsApp automations based on Zid webhook events.') }}
                                </p>
                            </div>
                            <span
                                class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide rounded-full bg-slate-100 px-3 py-1"
                            >
                                <span :class="zidStatusPillClass" class="text-xs font-semibold leading-none">
                                    •
                                </span>
                                {{ zidStatusLabel }}
                            </span>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <section class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h2 class="text-lg font-semibold text-gray-900">{{ $t('Integration') }}</h2>
                                    <p class="text-sm text-slate-500">{{ $t('Authorize Zid, see the connected store, and keep your tokens in sync.') }}</p>
                                </div>
                            </div>
                            <ZidIntegrationCard
                                :connection="props.zidConnection"
                                :connect-url="props.zidConnectUrl"
                                :disconnect-url="props.zidDisconnectUrl"
                            />
                        </section>

                        <section class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h2 class="text-lg font-semibold text-gray-900">{{ $t('Subscriptions') }}</h2>
                                    <p class="text-sm text-slate-500">
                                        {{ $t('Choose which Zid webhook events stay active and add conditions when needed.') }}
                                    </p>
                                </div>
                            </div>
                            <ZidSubscriptionManager
                                :events="props.zidSubscriptionEvents"
                                :statuses="props.zidSubscriptionStatus"
                                :toggle-url="props.zidSubscriptionToggleUrl"
                                :is-connected="Boolean(props.zidConnection)"
                            />
                        </section>

                        <section class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h2 class="text-lg font-semibold text-gray-900">{{ $t('Automations') }}</h2>
                                    <p class="text-sm text-slate-500">
                                        {{ $t('Send templated WhatsApp messages for Zid events and review delivery history.') }}
                                    </p>
                                </div>
                                <Link
                                    :href="props.zidAutomationsUrl"
                                    class="inline-flex items-center gap-2 text-sm font-semibold text-blue-600 hover:text-blue-800"
                                >
                                    {{ $t('Configure') }}
                                </Link>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </div>
    </SettingLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SettingLayout from "./Layout.vue";
import ZidIntegrationCard from "./ZidIntegrationCard.vue";
import ZidSubscriptionManager from "./ZidSubscriptionManager.vue";

const props = defineProps({
    modules: {
        type: Array,
        default: () => []
    },
    zidConnection: {
        type: Object,
        default: null
    },
    zidSubscriptionEvents: {
        type: Object,
        default: () => ({})
    },
    zidSubscriptionStatus: {
        type: Object,
        default: () => ({})
    },
    zidSubscriptionToggleUrl: {
        type: String,
        default: ''
    },
    zidConnectUrl: {
        type: String,
        default: ''
    },
    zidDisconnectUrl: {
        type: String,
        default: ''
    },
    zidAutomationsUrl: {
        type: String,
        default: ''
    }
});

const { t } = useI18n();

const isZidConnected = computed(() => props.zidConnection && props.zidConnection.connection_status === 'connected');
const zidStatusLabel = computed(() => {
    if (isZidConnected.value) {
        return t('Connected');
    }

    if (props.zidConnection && props.zidConnection.connection_status === 'disconnected') {
        return t('Disconnected');
    }

    return t('Not Connected');
});

const zidStatusPillClass = computed(() => {
    if (isZidConnected.value) {
        return 'text-emerald-600';
    }

    if (props.zidConnection && props.zidConnection.connection_status === 'disconnected') {
        return 'text-rose-600';
    }

    return 'text-slate-500';
});
</script>
