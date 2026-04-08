<template>
    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
            <div>
                <h3 class="text-lg font-semibold text-gray-900">{{ $t('Zid Integration') }}</h3>
                <p class="text-sm text-slate-500">{{ $t('Connect your Zid store with Chatberry to start building automations later.') }}</p>
            </div>
            <span
                class="inline-flex items-center gap-1 text-xs font-semibold uppercase tracking-wide px-3 py-1 rounded-full bg-slate-100 text-slate-600"
            >
                <span :class="statePillClass" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold">
                    •
                </span>
                {{ statusLabel }}
            </span>
        </div>

        <div v-if="flash && flash.success" class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-lg text-sm">
            {{ flash.success }}
        </div>
        <div v-if="flash && flash.error" class="bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 rounded-lg text-sm">
            {{ flash.error }}
        </div>

        <div v-if="isConnected" class="space-y-2">
            <div class="flex flex-wrap gap-2 text-sm text-slate-600">
                <div>
                    <span class="font-semibold text-slate-800">{{ $t('Store') }}:</span>
                    <span>{{ connection.store_name || $t('Unnamed Zid Store') }}</span>
                </div>
                <div>
                    <span class="font-semibold text-slate-800">{{ $t('Store ID') }}:</span>
                    <span>{{ connection.store_id }}</span>
                </div>
                <div v-if="connection.token_expires_at">
                    <span class="font-semibold text-slate-800">{{ $t('Token Expires') }}:</span>
                    <span>{{ connection.token_expires_at }}</span>
                </div>
            </div>
        </div>

        <div v-else class="text-sm text-slate-600">
            {{ $t('You have not connected a Zid store yet. Click the button below to start the secure OAuth flow.') }}
        </div>

        <div class="flex flex-wrap gap-3">
            <button
                type="button"
                :disabled="isConnecting"
                @click="handleConnect"
                class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                :class="isConnected ? 'bg-amber-600 hover:bg-amber-700' : 'bg-blue-600 hover:bg-blue-700'"
            >
                <svg v-if="isConnecting" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"></circle>
                    <path class="opacity-75" d="M4 12a8 8 0 014.5-7.1" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path>
                </svg>
                <svg v-else xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 5v14m-7-7h14" />
                </svg>
                {{ buttonLabel }}
            </button>

            <button
                v-if="isConnected"
                type="button"
                @click="handleDisconnect"
                :disabled="isDisconnecting"
                class="inline-flex items-center gap-1 px-4 py-2 rounded-lg text-sm font-semibold text-white bg-rose-600 hover:bg-rose-700 transition-colors disabled:opacity-60"
            >
                <span v-if="isDisconnecting" class="animate-spin h-3 w-3 border-2 border-white border-t-transparent rounded-full"></span>
                {{ $t('Disconnect') }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    connection: {
        type: Object,
        default: null,
    },
    connectUrl: {
        type: String,
        required: true,
    },
    disconnectUrl: {
        type: String,
        required: true,
    },
});

const page = usePage();
// استخدم optional chaining للتأكد من وجود الكائن دائماً
const flash = computed(() => {
    return page.props.flash || { success: null, error: null };
});
const { t } = useI18n();

const isConnecting = ref(false);
const isDisconnecting = ref(false);
const disconnectForm = useForm({});

const isConnected = computed(() => props.connection && props.connection.connection_status === 'connected');

const statusLabel = computed(() => {
    if (isConnected.value) {
        return t('Connected');
    }

    if (props.connection && props.connection.connection_status === 'disconnected') {
        return t('Disconnected');
    }

    return t('Not Connected');
});

const statePillClass = computed(() => {
    if (isConnected.value) {
        return 'text-emerald-600';
    }

    if (props.connection && props.connection.connection_status === 'disconnected') {
        return 'text-rose-600';
    }

    return 'text-slate-500';
});

const buttonLabel = computed(() =>
    isConnected.value ? t('Reconnect with Zid') : t('Connect with Zid')
);

const handleConnect = () => {
console.log('connectUrl:', props.connectUrl);
    console.log('current url:', window.location.href);
    if (!props.connectUrl) {
        return;
    }

    isConnecting.value = true;
    window.location.href = props.connectUrl;
};

const handleDisconnect = () => {
    if (!props.disconnectUrl) {
        return;
    }

    isDisconnecting.value = true;
    disconnectForm.delete(props.disconnectUrl, {
        preserveScroll: true,
        onFinish: () => {
            isDisconnecting.value = false;
        },
    });
};
</script>
