<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] overflow-y-scroll capitalize">
            <div class="md:flex justify-between mt-8 md:mt-0 hidden">
                <div>
                    <h2 class="text-xl mb-1">{{ $t('Campaigns') }}</h2>
                    <p v-if="props.role !== 'agent'" class="mb-6 md:flex items-center text-sm leading-6 text-gray-600 hidden">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ml-1 mt-1" >{{ $t('Add campaigns') }}</span>
                    </p>
                </div>
                <div v-if="props.role !== 'agent'">
                    <Link href="/campaigns/create" class="rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 md:block hidden">{{ $t('Create campaign') }}</Link>
                </div>
            </div>

            <!-- stats (only show during trial) -->
            <div v-if="props.overall_delivery_count !== null" class="grid grid-cols-1 sm:grid-cols-2 gap-4 my-6">
                <div class="bg-gray-100 p-4 rounded">
                    <h2 class="text-2xl font-bold">{{ props.overall_delivery_count }}</h2>
                    <p class="text-sm text-gray-600">{{ $t('Delivered messages') }}</p>
                </div>
                <div class="bg-gray-100 p-4 rounded">
                    <h2 class="text-2xl font-bold">{{ props.campaign_messages_limit }}</h2>
                    <p class="text-sm text-gray-600">{{ $t('Campaign messages limit') }}</p>
                </div>
            </div>

            <!-- Table Component-->
            <CampaignTable :rows="props.rows" :filters="props.filters" :role="props.role"/>
        </div>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./../Layout/App.vue";
    import { Link } from "@inertiajs/vue3";
    import CampaignTable from '@/Components/Tables/CampaignTable.vue';

    const props = defineProps(['rows', 'filters', 'role', 'overall_delivery_count', 'campaign_messages_limit']);
</script>