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
                    <template v-if="canCreateCampaign">
                        <Link
                            href="/campaigns/create"
                            class="rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 md:block hidden"
                        >
                            {{ $t('Create campaign') }}
                        </Link>
                    </template>
                    <template v-else>
                        <button
                            type="button"
                            @click="onTrialLimitClick"
                            title="You have exceeded your trial campaign messages limit. Please upgrade your plan."
                            class="rounded-md bg-gray-400 px-3 py-2 text-sm text-gray-700 shadow-sm md:block hidden"
                        >
                            {{ $t('Create campaign') }}
                        </button>
                    </template>
                
                </div>
            </div>

            <!-- stats (only show during trial) -->
            <div v-if="props.trial" class="grid grid-cols-1 sm:grid-cols-3 gap-4 my-6">
                <div class="bg-gray-100 p-4 rounded">
                    <h2 class="text-2xl font-bold">{{ props.campaign_messages_limit }}</h2>
                    <p class="text-sm text-gray-600">{{ $t('Campaign messages limit') }}</p>
                </div>
                <div class="bg-gray-100 p-4 rounded">
                    <h2 class="text-2xl font-bold">{{ props.overall_delivery_count }}</h2>
                    <p class="text-sm text-gray-600">{{ $t('Delivered messages') }}</p>
                </div>
                <div class="bg-gray-100 p-4 rounded">
                    <h2 class="text-2xl font-bold">{{ props.campaign_messages_limit - props.overall_delivery_count }}</h2>
                    <p class="text-sm text-gray-600">{{ $t('Remaining balance') }}</p>
                </div>
            </div>
            <div v-if="props.trial" class="mb-6">
                <div class="flex items-baseline justify-between mb-1">
                    <p class="text-sm font-medium text-gray-700">{{ $t('Progress') }}</p>
                    <p class="text-sm font-semibold text-gray-700">{{ progressPercentage }}%</p>
                </div>
                <div class="h-2 w-full bg-gray-200 rounded-full overflow-hidden">
                    <div :style="{ width: progressPercentage + '%' }" class="h-full bg-primary"></div>
                </div>
            </div>

            <!-- Table Component-->
            <CampaignTable :rows="props.rows" :filters="props.filters" :role="props.role"/>
        </div>
    </AppLayout>
</template>
<script setup>
    import { computed, ref } from 'vue';
    import AppLayout from "./../Layout/App.vue";
    import { Link } from "@inertiajs/vue3";
    import CampaignTable from '@/Components/Tables/CampaignTable.vue';

    const props = defineProps(['rows', 'filters', 'role', 'overall_delivery_count', 'campaign_messages_limit', 'trial']);

    const canCreateCampaign = computed(() => {
        if (!props.trial) {
            return true;
        }

        const limit = Number(props.campaign_messages_limit || 0);
        const delivered = Number(props.overall_delivery_count || 0);
        if (limit <= 0) {
            return true;
        }

        return delivered < limit;
    });

    const showTrialLimitError = ref(false);

    const onTrialLimitClick = () => {
        showTrialLimitError.value = true;
        alert('You have exceeded your trial campaign messages limit. Please upgrade your plan.');
    };

    const progressPercentage = computed(() => {
        if (!props.campaign_messages_limit || props.campaign_messages_limit <= 0) {
            return 0;
        }

        const percent = (props.overall_delivery_count / props.campaign_messages_limit) * 100;
        return Math.min(100, Math.max(0, Math.round(percent)));
    });
</script>