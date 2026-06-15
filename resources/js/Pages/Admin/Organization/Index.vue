<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-10 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] h-full md:overflow-y-auto">
            <div class="flex flex-col gap-4 sm:flex-row sm:justify-between">
                <div>
                    <h1 class="text-xl mb-1">{{ $t('Organizations') }}</h1>
                    <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ml-1 mt-1">{{ $t('Manage organization accounts') }}</span>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                    <button
                        type="button"
                        :disabled="isSyncing"
                        class="inline-flex items-center gap-2 rounded-md bg-emerald-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-emerald-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 disabled:cursor-not-allowed disabled:opacity-60"
                        @click="syncWhatsappNumberStatuses"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            width="16"
                            height="16"
                            viewBox="0 0 24 24"
                            :class="{ 'animate-spin': isSyncing }"
                        >
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 11a8.1 8.1 0 0 0-15.5-2M4 4v5h5m-5 4a8.1 8.1 0 0 0 15.5 2m.5 5v-5h-5"/>
                        </svg>
                        {{ $t(isSyncing ? 'Queuing sync...' : 'Sync WhatsApp statuses') }}
                    </button>
                    <Link href="/admin/organizations/create" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Add organization') }}</Link>
                </div>
            </div>
            <OrganizationTable :rows="props.rows" :filters="props.filters" :type="'user'"/>
        </div>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./../Layout/App.vue";
    import { Link, router } from "@inertiajs/vue3";
    import { ref } from 'vue';
    import OrganizationTable from '@/Components/Tables/OrganizationTable.vue';

    const props = defineProps({ 
        allowCreate: {
            type: Boolean,
            default: true
        }, 
        title: String, 
        rows: Object, 
        filters: Object
    });

    const isSyncing = ref(false);

    const syncWhatsappNumberStatuses = () => {
        router.post('/admin/organizations/sync-whatsapp-number-statuses', {}, {
            preserveScroll: true,
            onStart: () => isSyncing.value = true,
            onFinish: () => isSyncing.value = false,
        });
    };
</script>
