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

            <div class="flex flex-wrap items-center gap-2 mt-4">
                <div class="relative">
                    <select
                        v-model="selectedRange"
                        @change="onRangeChange"
                        class="h-10 pl-3 pr-8 rounded-md border border-slate-200 bg-[#f6f7f9] text-sm text-slate-700 appearance-none"
                    >
                        <option value="last30">{{ $t('Last 30 days') }}</option>
                        <option value="last7">{{ $t('Last 7 days') }}</option>
                        <option value="today">{{ $t('Today') }}</option>
                        <option value="custom">{{ $t('Custom range') }}</option>
                    </select>
                    <svg class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-slate-500" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                        <path fill="currentColor" d="m7 10l5 5l5-5z"/>
                    </svg>
                </div>

                <div class="relative">
                    <svg class="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-slate-500" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                        <path fill="currentColor" d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-2 .89-2 2v13a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6c0-1.11-.89-2-2-2m0 15H5V10h14z"/>
                    </svg>
                    <input
                        v-model="startDate"
                        @change="onCustomDateChange"
                        type="date"
                        class="h-10 w-[170px] pl-8 pr-3 rounded-md border border-slate-200 bg-[#f6f7f9] text-sm text-slate-700"
                    />
                </div>

                <div class="relative">
                    <svg class="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-slate-500" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                        <path fill="currentColor" d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-2 .89-2 2v13a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6c0-1.11-.89-2-2-2m0 15H5V10h14z"/>
                    </svg>
                    <input
                        v-model="endDate"
                        @change="onCustomDateChange"
                        type="date"
                        class="h-10 w-[170px] pl-8 pr-3 rounded-md border border-slate-200 bg-[#f6f7f9] text-sm text-slate-700"
                    />
                </div>

                <div class="relative">
                    <select
                        v-model="selectedExportStatus"
                        class="h-10 pl-3 pr-8 rounded-md border border-slate-200 bg-[#f6f7f9] text-sm text-slate-700 appearance-none"
                    >
                        <option value="">{{ $t('All statuses') }}</option>
                        <option value="sent">{{ $t('Sent') }}</option>
                        <option value="delivered">{{ $t('Delivered') }}</option>
                        <option value="read">{{ $t('Read') }}</option>
                        <option value="failed">{{ $t('Failed') }}</option>
                        <option value="processing">{{ $t('Processing') }}</option>
                        <option value="queued">{{ $t('Queued') }}</option>
                    </select>
                    <svg class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-slate-500" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                        <path fill="currentColor" d="m7 10l5 5l5-5z"/>
                    </svg>
                </div>
                <button @click="exportCampaignAnalytics" class="h-10 px-4 rounded-md border border-slate-200 bg-white text-sm text-slate-700">
                    {{ $t('Export') }}
                </button>

                <button
                    @click="resetDateFilter"
                    :title="$t('Reset to last 30 days')"
                    class="h-10 w-10 inline-flex items-center justify-center rounded-md border border-slate-200 bg-white text-slate-600"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                        <path fill="currentColor" d="M12 6V3L8 7l4 4V8c2.76 0 5 2.24 5 5a5 5 0 1 1-9.9-1h-2.02A7 7 0 1 0 12 6"/>
                    </svg>
                </button>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 my-6">
                <div v-for="metric in campaignMetrics" :key="metric.key" class="bg-gray-100 p-4 rounded">
                    <div class="flex items-center justify-between">
                        <h2 class="text-2xl font-bold text-slate-800">{{ metric.value.toLocaleString() }}</h2>
                        <span class="h-8 w-8 inline-flex items-center justify-center rounded-full" :style="{ backgroundColor: metric.iconBg }">
                            <svg v-if="metric.icon === 'check'" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" :style="{ color: metric.iconColor }">
                                <path fill="currentColor" d="m10 15.17l-3.88-3.88L4.7 12.71L10 18l10-10l-1.41-1.41z"/>
                            </svg>
                            <svg v-else-if="metric.icon === 'double-check'" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" :style="{ color: metric.iconColor }">
                                <path fill="currentColor" d="M1.12 12.29L5 16.17l1.41-1.41l-3.88-3.88zm6.29 2.47L13 20.34L24.34 9l-1.41-1.41l-9.93 9.93l-4.17-4.17zm15.81-5.75L21.81 7.6l-6.36 6.36l1.41 1.41z"/>
                            </svg>
                            <svg v-else-if="metric.icon === 'eye'" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" :style="{ color: metric.iconColor }">
                                <path fill="currentColor" d="M12 6a10 10 0 0 1 9.63 7A10 10 0 0 1 12 20a10 10 0 0 1-9.63-7A10 10 0 0 1 12 6m0 2a8 8 0 0 0-7.6 5a8 8 0 0 0 15.2 0A8 8 0 0 0 12 8m0 1.5A3.5 3.5 0 1 1 8.5 13A3.5 3.5 0 0 1 12 9.5"/>
                            </svg>
                            <svg v-else-if="metric.icon === 'failed'" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" :style="{ color: metric.iconColor }">
                                <path fill="currentColor" d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2m3.59 12.17L14.17 15.6L12 13.41L9.83 15.6l-1.42-1.43L10.59 12L8.41 9.83L9.83 8.4L12 10.59l2.17-2.19l1.42 1.43L13.41 12z"/>
                            </svg>
                            <svg v-else-if="metric.icon === 'processing'" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" :style="{ color: metric.iconColor }">
                                <path fill="currentColor" d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6a6 6 0 0 1-6 6a6 6 0 0 1-5.65-4H4.26A8 8 0 0 0 12 20a8 8 0 0 0 0-16"/>
                            </svg>
                            <svg v-else xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" :style="{ color: metric.iconColor }">
                                <path fill="currentColor" d="M12 1a11 11 0 1 0 11 11A11 11 0 0 0 12 1m1 11.41V6h-2v8h7v-2z"/>
                            </svg>
                        </span>
                    </div>
                    <div class="mt-2 flex items-center gap-1">
                        <p class="text-sm text-gray-600">{{ $t(metric.label) }}</p>
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" class="text-slate-400">
                            <path fill="currentColor" d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2m1 15h-2v-6h2zm0-8h-2V7h2z"/>
                        </svg>
                    </div>
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
    import { Link, router } from "@inertiajs/vue3";
    import CampaignTable from '@/Components/Tables/CampaignTable.vue';

    const props = defineProps(['rows', 'filters', 'role', 'overall_delivery_count', 'campaign_messages_limit', 'trial', 'campaign_analytics', 'initial_start_date', 'initial_end_date']);

    const formatDate = (d) => d.toISOString().slice(0, 10);
    const today = new Date();
    const last30 = new Date();
    last30.setDate(today.getDate() - 29);

    const startDate = ref(props.initial_start_date || formatDate(last30));
    const endDate = ref(props.initial_end_date || formatDate(today));
    const selectedRange = ref('last30');
    const selectedExportStatus = ref('');

    const inferSelectedRange = () => {
        const todayStr = formatDate(today);
        const d7 = new Date();
        d7.setDate(today.getDate() - 6);
        const d30 = new Date();
        d30.setDate(today.getDate() - 29);

        if (startDate.value === todayStr && endDate.value === todayStr) {
            selectedRange.value = 'today';
            return;
        }

        if (startDate.value === formatDate(d7) && endDate.value === todayStr) {
            selectedRange.value = 'last7';
            return;
        }

        if (startDate.value === formatDate(d30) && endDate.value === todayStr) {
            selectedRange.value = 'last30';
            return;
        }

        selectedRange.value = 'custom';
    };

    inferSelectedRange();

    const applyDateFilter = () => {
        const params = {
            start_date: startDate.value,
            end_date: endDate.value,
        };

        if (props.filters?.search) {
            params.search = props.filters.search;
        }

        router.get('/campaigns', params, { preserveState: false, replace: true });
    };

    const onRangeChange = () => {
        const current = new Date();

        if (selectedRange.value === 'today') {
            const t = formatDate(current);
            startDate.value = t;
            endDate.value = t;
        } else if (selectedRange.value === 'last7') {
            const d = new Date();
            d.setDate(current.getDate() - 6);
            startDate.value = formatDate(d);
            endDate.value = formatDate(current);
        } else if (selectedRange.value === 'last30') {
            const d = new Date();
            d.setDate(current.getDate() - 29);
            startDate.value = formatDate(d);
            endDate.value = formatDate(current);
        }

        applyDateFilter();
    };

    const onCustomDateChange = () => {
        selectedRange.value = 'custom';
        applyDateFilter();
    };

    const resetDateFilter = () => {
        selectedRange.value = 'last30';
        onRangeChange();
    };

    const exportCampaignAnalytics = () => {
        const params = new URLSearchParams();
        if (startDate.value) params.set('start_date', startDate.value);
        if (endDate.value) params.set('end_date', endDate.value);
        if (props.filters?.search) params.set('search', props.filters.search);
        if (selectedExportStatus.value) params.set('status', selectedExportStatus.value);
        const query = params.toString();
        window.location.href = query
            ? `/campaigns/reports/export-statuses?${query}`
            : '/campaigns/reports/export-statuses';
    };

    const campaignMetrics = computed(() => ([
        { key: 'sent', label: 'Sent', value: Number(props.campaign_analytics?.sent || 0), icon: 'check', iconColor: '#22c55e', iconBg: '#dcfce7' },
        { key: 'delivered', label: 'Delivered', value: Number(props.campaign_analytics?.delivered || 0), icon: 'double-check', iconColor: '#22c55e', iconBg: '#dcfce7' },
        { key: 'read', label: 'Read', value: Number(props.campaign_analytics?.read || 0), icon: 'eye', iconColor: '#16a34a', iconBg: '#dcfce7' },
        { key: 'failed', label: 'Failed', value: Number(props.campaign_analytics?.failed || 0), icon: 'failed', iconColor: '#dc2626', iconBg: '#fee2e2' },
        { key: 'processing', label: 'Processing', value: Number(props.campaign_analytics?.processing || 0), icon: 'processing', iconColor: '#d97706', iconBg: '#fef3c7' },
        { key: 'queued', label: 'Queued', value: Number(props.campaign_analytics?.queued || 0), icon: 'queued', iconColor: '#4f46e5', iconBg: '#e0e7ff' },
    ]));

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
