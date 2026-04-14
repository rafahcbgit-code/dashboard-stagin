<template>
    <SettingLayout :modules="props.modules">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center mb-8">
                <div class="md:w-[60em]">
                    <div class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4 pb-2">
                        <div class="flex px-4 pt-2 pb-4">
                            <div>
                                <h2 class="text-[17px]">{{ $t('Plugins Section') }}</h2>
                                <span class="flex items-center mt-1">
                                    <svg class="mr-2" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    {{ $t('Enable or disable plugins to enhance your business operations.') }}
                                </span>
                            </div>
                        </div>
                        <div class="max-w-7xl mx-auto">
                            <div class="overflow-hidden sm:rounded-lg">
                                <div class="p-6">
                                    <Link href="/settings/salla" class="flex justify-between border rounded-lg p-4 mb-6 hover:shadow-md transition-shadow">
                                        <div class="flex items-center gap-3">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                                fill="currentColor">
                                                <path
                                                    d="M21.9 8.89l-1.05-4.37c-.22-.9-1-1.52-1.91-1.52H5.05c-.9 0-1.69.63-1.9 1.52L2.1 8.89c-.24 1.02-.02 2.06.62 2.88.08.11.19.19.28.29V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2v-6.94c.09-.09.2-.18.28-.28.64-.82.87-1.87.62-2.89zm-2.99-3.9l1.05 4.37c.1.42.01.84-.25 1.17-.14.18-.44.47-.94.47-.61 0-1.14-.49-1.21-1.14L16.98 5l1.93-.01zM13 5h1.96l.54 4.52c.05.39-.07.78-.33 1.07-.22.26-.54.41-.95.41-.67 0-1.22-.59-1.22-1.31V5zM8.49 5h1.96v4.69c0 .72-.55 1.31-1.22 1.31-.41 0-.73-.15-.95-.41-.26-.29-.38-.68-.33-1.07L8.49 5h.02.02zm-4.45 0h1.93l-.58 4.86c-.07.65-.6 1.14-1.21 1.14-.49 0-.8-.29-.93-.47-.27-.32-.36-.75-.26-1.17l1.05-4.36zM5 19v-6.03c.08.01.15.03.23.03.87 0 1.66-.36 2.24-.95.6.6 1.4.95 2.31.95.87 0 1.65-.36 2.23-.93.59.57 1.39.93 2.29.93.84 0 1.64-.35 2.24-.95.58.59 1.37.95 2.24.95.08 0 .15-.02.23-.03V19H5z" />
                                            </svg>
                                            <span>{{ $t('Salla Integration') }}</span>
                                        </div>
                                        <div>
                                            <svg class="float-right" xmlns="http://www.w3.org/2000/svg" width="1.5em" height="1.5em"
                                                viewBox="0 0 256 256">
                                                <path fill="currentColor"
                                                    d="m180.24 132.24l-80 80a6 6 0 0 1-8.48-8.48L167.51 128L91.76 52.24a6 6 0 0 1 8.48-8.48l80 80a6 6 0 0 1 0 8.48" />
                                            </svg>
                                        </div>
                                    </Link>
                                    <div class="mb-6">
                                        <div class="border rounded-lg bg-white p-4 hover:shadow-md transition-shadow">
                                            <div class="flex items-start justify-between gap-6">
                                                <div class="max-w-[28rem] space-y-2">
                                                    <h3 class="text-lg font-semibold text-gray-900">{{ $t('Zid Integration') }}</h3>
                                                    <p class="text-sm text-slate-500">
                                                        {{ $t('Connect your Zid store, manage subscriptions, and access automations from one place.') }}
                                                    </p>
                                                    <div v-if="props.zidConnection" class="text-sm text-slate-600 space-y-1">
                                                        <div>
                                                            <span class="font-semibold text-slate-800">{{ $t('Store') }}:</span>
                                                            <span>{{ props.zidConnection.store_name || $t('Unnamed Zid Store') }}</span>
                                                        </div>
                                                        <div>
                                                            <span class="font-semibold text-slate-800">{{ $t('Store ID') }}:</span>
                                                            <span>{{ props.zidConnection.store_id }}</span>
                                                        </div>
                                                    </div>
                                                    <div v-else class="text-sm text-slate-600">
                                                        {{ $t('A Zid store is not connected yet. Click the arrow to continue the secure authorization.') }}
                                                    </div>
                                                </div>
                                                <div class="flex items-center gap-3">
                                                    <span
                                                        class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide rounded-full bg-slate-100 px-3 py-1"
                                                    >
                                                        <span :class="zidStatusPillClass" class="text-xs font-semibold leading-none">
                                                            •
                                                        </span>
                                                        {{ zidStatusLabel }}
                                                    </span>
                                                    <Link
                                                        :href="props.zidSettingsUrl"
                                                        class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 text-slate-500 hover:border-slate-300 hover:text-slate-700 transition-colors"
                                                    >
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 256 256" fill="none" stroke="currentColor" stroke-width="12" stroke-linecap="round" stroke-linejoin="round">
                                                            <path d="M92 48l72 80-72 80" />
                                                        </svg>
                                                    </Link>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="border rounded-lg p-4 mb-6 hover:shadow-md transition-shadow">
                                        <div class="flex justify-between items-center mb-2">
                                            <div>
                                                <div class="font-semibold text-base">{{ $t('Shopify Integration') }}</div>
                                                <div class="text-sm text-slate-500">{{ $t('Connect your Shopify store to use automation and sync.') }}</div>
                                            </div>
                                        </div>
                                        <div class="mt-3">
                                            <div v-if="props.shopifyAccessAllowed" class="grid gap-2 sm:grid-cols-[1fr_auto]">
                                                <input
                                                    v-model="shopDomain"
                                                    type="text"
                                                    placeholder="shop-name.myshopify.com"
                                                    class="w-full border rounded-lg px-3 py-2"
                                                />
                                                <button
                                                    type="button"
                                                    @click="connectShopify"
                                                    class="bg-blue-600 text-white rounded-lg px-4 py-2 hover:bg-blue-700 transition"
                                                >
                                                    {{ $t('Connect with Shopify') }}
                                                </button>
                                            </div>
                                            <div v-else class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900 space-y-2">
                                                <p>{{ $t('Shopify integration is available for eligible plans only.') }}</p>
                                                <p class="text-xs text-slate-700">
                                                    {{ $t('Current plan') }}: <strong>{{ props.currentPlanName || $t('No plan assigned') }}</strong>
                                                </p>
                                                <Link href="/billing" class="inline-flex items-center gap-1 text-xs font-semibold text-rose-700 hover:text-rose-900">
                                                    {{ $t('Upgrade your plan to unlock Shopify integration') }}
                                                </Link>
                                            </div>
                                        </div>
                                        <p v-if="shopError && props.shopifyAccessAllowed" class="text-sm text-red-600 mt-1">{{ shopError }}</p>
                                        <p v-if="props.shopifyAccessAllowed" class="text-xs text-slate-500 mt-2">
                                            {{ $t('After authorizing, Shopify will redirect to /shopify/callback.') }}
                                        </p>
                                        <Link v-if="props.shopifyAccessAllowed" href="/settings/shopify/automations" class="text-xs text-slate-500 mt-1 inline-block hover:text-blue-600">
                                            {{ $t('Configure Shopify Automations') }}
                                        </Link>
                                    </div>
                                    <div class="mb-6">
                                        <WooCommerceIntegration />
                                    </div>
                                    <div v-if="plugins.length === 0" class="text-center py-12">
                                        <div class="mx-auto w-24 h-24 text-gray-300 mb-4">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z" />
                                            </svg>
                                        </div>
                                        <!-- <h3 class="text-lg font-medium text-gray-900 mb-2">{{ $t('No Plugins Available') }}</h3>
                                        <p class="text-gray-500 max-w-sm mx-auto">
                                            {{ $t('There are currently no plugins available for your account. Check back later for new integrations.') }}
                                        </p> -->
                                    </div>
                                    
                                    <div v-else class="space-y-6">
                                        <div v-for="plugin in plugins" :key="plugin.uuid" 
                                            class="border rounded-lg p-4 hover:shadow-md transition-shadow">
                                            <component 
                                                :is="getPluginComponent(plugin.name)"
                                                :plugin="plugin"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </SettingLayout>
</template>

<script setup>
import { ref, computed, defineAsyncComponent } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SettingLayout from "./../Layout.vue";
import WooCommerceIntegration from './WooCommerceIntegrationCard.vue';

const props = defineProps({
    modules: {
        type: Array,
        default: () => []
    },
    plugins: {
        type: Array,
        default: () => []
    },
    shopifyAccessAllowed: {
        type: Boolean,
        default: true
    },
    currentPlanName: {
        type: String,
        default: ''
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
    zidAccessAllowed: {
        type: Boolean,
        default: true
    },
    zidSettingsUrl: {
        type: String,
        default: ''
    }
});
const shopDomain = ref('');
const shopError = ref('');
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

const connectShopify = () => {
    if (!props.shopifyAccessAllowed) {
        return;
    }
    const shop = shopDomain.value.trim();
    if (!shop) {
        shopError.value = 'يرجى إدخال رابط متجر Shopify';
        return;
    }
    shopError.value = '';
    window.location.href = `/new-install?shop=${encodeURIComponent(shop)}`;
};

const getPluginComponent = (pluginName) => {
    try {
        return defineAsyncComponent(() => 
            import(`../../../../../../modules/${pluginName}/Pages/User/Index.vue`)
        );
    } catch (error) {
        console.warn(`Plugin component not found for ${pluginName}`);
        return null;
    }
};
</script> 
