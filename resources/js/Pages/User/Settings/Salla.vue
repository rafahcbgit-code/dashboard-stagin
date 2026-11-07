<template>
    <AppLayout>
        <div v-if="sallaAccessAllowed">
            <div
                class="bg-white md:bg-inherit pt-10 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] h-full md:overflow-y-auto">
            <div class="flex justify-between mb-6">
                <div>
                    <h1 class="text-xl mb-1">{{ $t('Salla Integration') }}</h1>
                    <p class="flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z" />
                        </svg>
                        <span class="ltr:ml-1 rtl:mr-1 mt-1">{{ $t('Connect your Salla store to sync orders and products') }}</span>
                    </p>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <div v-if="$page.props.flash.success"
                class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg mb-4">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.flash.error"
                class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4">
                {{ $page.props.flash.error }}
            </div>

            <!-- Automations -->
            <div class="bg-white border border-slate-200 rounded-lg p-5 mb-6 flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-lg font-medium text-gray-900 mb-1">{{ $t('Automations') }}</h3>
                    <p class="text-sm text-gray-600">{{ $t('Send automatic messages from Salla webhook events like New Order (order.created).') }}</p>
                </div>
                <a href="/settings/salla/automations"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-slate-100 hover:bg-slate-200 text-sm rounded-lg transition-colors">
                    {{ $t('Configure') }}
                </a>
            </div>

            <!-- Customer Sync Button -->
            <div class="bg-white border border-slate-200 rounded-lg p-5 mb-6 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-medium text-gray-900 mb-1">{{ $t('Customer Sync') }}</h3>
                    <p class="text-sm text-gray-600">{{ $t('Sync customers by date range and tag.') }}</p>
                </div>
                <button
                    @click="openCustomerSync"
                    class="cb-primary-button gap-2">
                    {{ $t('Customer Sync') }}
                </button>
            </div>

            <!-- Connect Button -->
            <div v-if="stores.length === 0" class="bg-white border border-slate-200 rounded-lg p-6 text-center mb-6">
                <div class="mb-4">
                    <svg class="mx-auto h-12 w-12 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">{{ $t('No Salla store connected') }}</h3>
                <p class="text-sm text-gray-600 mb-4">
                    {{ $t('Connect your Salla store to start syncing your e-commerce data with WhatsApp messaging.')
                    }}
                </p>
                <a href="/salla-auth"
                    class="cb-primary-button gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                        fill="currentColor">
                        <path
                            d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                    </svg>
                    {{ $t('Connect with Salla') }}
                </a>
            </div>

            <!-- Connected Stores List -->
            <div v-else>
                <!-- <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-medium">{{ $t('Connected Stores') }}</h2>
                    <a href="/salla-auth"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-[#5C3A76] hover:bg-[#4a2f5f] text-white text-sm rounded-lg transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2">
                            <path d="M12 5v14m-7-7h14" />
                        </svg>
                        {{ $t('Add Another Store') }}
                    </a>
                </div> -->

                <div class="space-y-4">
                    <div v-for="store in stores" :key="store.id"
                        class="bg-white border border-slate-200 rounded-lg p-5">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <div class="flex items-center gap-3 mb-3">
                                    <div class="w-12 h-12 bg-[#5C3A76] rounded-lg flex items-center justify-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="white">
                                            <path
                                                d="M21.9 8.89l-1.05-4.37c-.22-.9-1-1.52-1.91-1.52H5.05c-.9 0-1.69.63-1.9 1.52L2.1 8.89c-.24 1.02-.02 2.06.62 2.88.08.11.19.19.28.29V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2v-6.94c.09-.09.2-.18.28-.28.64-.82.87-1.87.62-2.89zm-2.99-3.9l1.05 4.37c.1.42.01.84-.25 1.17-.14.18-.44.47-.94.47-.61 0-1.14-.49-1.21-1.14L16.98 5l1.93-.01zM13 5h1.96l.54 4.52c.05.39-.07.78-.33 1.07-.22.26-.54.41-.95.41-.67 0-1.22-.59-1.22-1.31V5zM8.49 5h1.96v4.69c0 .72-.55 1.31-1.22 1.31-.41 0-.73-.15-.95-.41-.26-.29-.38-.68-.33-1.07L8.49 5h.02.02zm-4.45 0h1.93l-.58 4.86c-.07.65-.6 1.14-1.21 1.14-.49 0-.8-.29-.93-.47-.27-.32-.36-.75-.26-1.17l1.05-4.36zM5 19v-6.03c.08.01.15.03.23.03.87 0 1.66-.36 2.24-.95.6.6 1.4.95 2.31.95.87 0 1.65-.36 2.23-.93.59.57 1.39.93 2.29.93.84 0 1.64-.35 2.24-.95.58.59 1.37.95 2.24.95.08 0 .15-.02.23-.03V19H5z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-900">{{ store.merchant_name ||
                                            $t('Salla Store') }}</h3>
                                        <p class="text-sm text-gray-500">{{ $t('Merchant ID') }}: {{ store.merchant_id
                                        }}</p>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                    <div>
                                        <span class="text-sm text-gray-600">{{ $t('Status') }}:</span>
                                        <span v-if="!isExpired(store.expires_at)"
                                            class="ltr:ml-2 rtl:mr-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            <svg class="ltr:mr-1 rtl:ml-1 h-2 w-2" fill="currentColor"
                                                viewBox="0 0 8 8">
                                                <circle cx="4" cy="4" r="3" />
                                            </svg>
                                            {{ $t('Active') }}
                                        </span>
                                        <span v-else
                                            class="ltr:ml-2 rtl:mr-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                            <svg class="ltr:mr-1 rtl:ml-1 h-2 w-2" fill="currentColor"
                                                viewBox="0 0 8 8">
                                                <circle cx="4" cy="4" r="3" />
                                            </svg>
                                            {{ $t('Expired') }}
                                        </span>
                                    </div>
                                    <div>
                                        <span class="text-sm text-gray-600">{{ $t('Token Expires') }}:</span>
                                        <span class="ltr:ml-2 rtl:mr-2 text-sm font-medium">{{
                                            formatDate(store.expires_at) }}</span>
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <button @click="refreshToken(store.id)"
                                        :disabled="refreshing[store.id]"
                                        class="inline-flex items-center gap-2 px-4 py-2 text-white text-sm rounded-lg transition-colors disabled:opacity-50"
                                        :class="isExpired(store.expires_at) ? 'bg-blue-600 hover:bg-blue-700' : 'bg-green-600 hover:bg-green-700'">
                                        <svg v-if="refreshing[store.id]" class="animate-spin h-4 w-4"
                                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor"
                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                            </path>
                                        </svg>
                                        <svg v-else xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path
                                                d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15" />
                                        </svg>
                                        {{ $t('Refresh Token') }}
                                    </button>

                                    <button @click="confirmDisconnect(store.id)"
                                        class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm rounded-lg transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M18 6L6 18M6 6l12 12" />
                                        </svg>
                                        {{ $t('Disconnect') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            
        </div>

        <!-- Customer Sync Modal -->
        <div v-if="showCustomerSync"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div class="bg-white w-full max-w-md rounded-lg shadow-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">{{ $t('Customer Sync') }}</h3>
                    <button @click="closeCustomerSync" class="text-gray-500 hover:text-gray-700">
                        ✕
                    </button>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">{{ $t('Date From') }}</label>
                        <input v-model="customerSyncForm.date_from" type="date"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">{{ $t('Date To') }}</label>
                        <input v-model="customerSyncForm.date_to" type="date"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
                    </div>
                    <!-- <div>
                        <label class="block text-sm text-gray-700 mb-1">{{ $t('Tag Name') }}</label>
                        <input v-model="customerSyncForm.tag_name" type="text"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                            placeholder="VIP" />
                    </div> -->
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">{{ $t('Group Name') }}</label>
                        <input v-model="customerSyncForm.group_name" type="text"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                            :placeholder="$t('Group Name')" />
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-end gap-2">
                    <button @click="closeCustomerSync"
                        class="px-4 py-2 text-sm rounded-lg bg-slate-100 hover:bg-slate-200">
                        {{ $t('Cancel') }}
                    </button>
                    <button @click="submitCustomerSync" :disabled="customerSyncLoading"
                        class="cb-primary-button disabled:opacity-50">
                        {{ customerSyncLoading ? $t('Saving...') : $t('Save') }}
                    </button>
                </div>
            </div>
        </div>
        </div>
        <div v-else
            class="bg-white border border-slate-200 rounded-lg p-8 text-center mx-auto max-w-2xl shadow-sm">
            <div class="mb-4">
                <svg class="mx-auto h-12 w-12 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none"
                    viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M13 16h-1v-4h1m0-2h-1v-1h1m-1 11a9 9 0 1 0 0-18a9 9 0 0 0 0 18Z" />
                </svg>
            </div>
            <h3 class="text-lg font-semibold mb-2">{{ $t('Salla Integration is available only for eligible plans.') }}</h3>
            <p class="text-sm text-gray-600 mb-2">
                {{ $t('Current plan') }}: <strong>{{ currentPlanName || $t('No plan assigned') }}</strong>
            </p>
            <p class="text-sm text-gray-600 mb-6">{{ $t('Salla integration is currently disabled on this plan.') }}</p>
            <Link href="/billing"
                class="cb-primary-button gap-2">
                {{ $t('Upgrade your package to unlock Salla integration') }}
            </Link>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../Layout/App.vue';

const props = defineProps({
    stores: {
        type: Array,
        default: () => []
    },
    title: String,
    sallaAccessAllowed: {
        type: Boolean,
        default: true
    },
    currentPlanName: {
        type: String,
        default: ''
    }
});

const refreshing = ref({});

const showCustomerSync = ref(false);
const customerSyncLoading = ref(false);
const customerSyncForm = ref({
    date_from: '',
    date_to: '',
    tag_name: '',
    group_name: ''
});

const formatDate = (date) => {
    return new Date(date).toLocaleString();
};

const isExpired = (expiresAt) => {
    return new Date(expiresAt) <= new Date();
};

const refreshToken = (storeId) => {
    refreshing.value[storeId] = true;
    router.post(`/settings/salla/${storeId}/refresh`, {}, {
        preserveScroll: true,
        onFinish: () => {
            refreshing.value[storeId] = false;
        }
    });
};

const confirmDisconnect = (storeId) => {
    if (confirm('Are you sure you want to disconnect this store?')) {
        router.delete(`/settings/salla/${storeId}`, {
            preserveScroll: true
        });
    }
};

const openCustomerSync = () => {
    showCustomerSync.value = true;
};

const closeCustomerSync = () => {
    showCustomerSync.value = false;
};

const submitCustomerSync = () => {
    customerSyncLoading.value = true;
    router.post('/settings/salla/customer-sync', {
        date_from: customerSyncForm.value.date_from,
        date_to: customerSyncForm.value.date_to,
        tag_name: customerSyncForm.value.tag_name,
        group_name: customerSyncForm.value.group_name
    }, {
        preserveScroll: true,
        onFinish: () => {
            customerSyncLoading.value = false;
            showCustomerSync.value = false;
        }
    });
};
</script>
