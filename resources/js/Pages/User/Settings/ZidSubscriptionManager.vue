<template>
    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">
        <div class="flex justify-between items-center">
            <div>
                <h3 class="text-lg font-semibold text-gray-900">{{ $t('Zid Subscriptions') }}</h3>
                <p class="text-sm text-slate-500">{{ $t('Enable the Zid events you want to receive.') }}</p>
            </div>
            <span class="text-xs text-slate-400">
                {{ $t('Available events:') }} {{ eventCount }}
            </span>
        </div>

        <div v-if="errorMessage" class="text-sm text-rose-600">
            {{ errorMessage }}
        </div>

        <div class="space-y-3">
            <div v-for="event in eventList" :key="event.name" class="flex items-center justify-between gap-4 border rounded-lg p-4">
                <div>
                    <div class="font-semibold text-gray-900">{{ event.label }}</div>
                    <p class="text-xs text-slate-500">{{ event.description }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <span v-if="statuses[event.name]?.enabled" class="text-xs font-semibold text-emerald-600">
                        {{ $t('Enabled') }}
                    </span>
                    <span v-else class="text-xs font-semibold text-slate-400">
                        {{ $t('Disabled') }}
                    </span>
                    <button
                        type="button"
                        :disabled="isToggling === event.name || !isConnected"
                        @click="toggle(event.name)"
                        class="px-4 py-2 text-sm font-semibold rounded-lg transition"
                        :class="statuses[event.name]?.enabled ? 'bg-amber-600 text-white hover:bg-amber-700' : 'bg-blue-600 text-white hover:bg-blue-700'"
                    >
                        <span v-if="isToggling === event.name" class="animate-spin inline-block w-4 h-4 border-2 border-white border-t-transparent rounded-full mr-1"></span>
                        {{ statuses[event.name]?.enabled ? $t('Disable') : $t('Enable') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    events: {
        type: Object,
        required: true,
    },
    statuses: {
        type: Object,
        required: true,
    },
    toggleUrl: {
        type: String,
        required: true,
    },
    isConnected: {
        type: Boolean,
        default: false,
    },
});

const { t } = useI18n();
const isToggling = ref(null);
const errorMessage = ref('');
const statusState = reactive({ ...props.statuses });

const eventList = computed(() => {
    return Object.entries(props.events).map(([name, meta]) => ({
        name,
        label: meta.label || name,
        description: meta.description || '',
    }));
});

const eventCount = computed(() => eventList.value.length);

const toggle = async (eventName) => {
    const current = statusState[eventName];
    if (!current) {
        errorMessage.value = t('Subscription data unavailable.');
        return;
    }

    isToggling.value = eventName;
    errorMessage.value = '';

    try {
        await axios.post(props.toggleUrl, {
            event: eventName,
            enabled: !current.enabled,
        });
        statusState[eventName].enabled = !current.enabled;
    } catch (error) {
        errorMessage.value = t('Unable to update subscription. Try again later.');
    } finally {
        isToggling.value = null;
    }
};
</script>
