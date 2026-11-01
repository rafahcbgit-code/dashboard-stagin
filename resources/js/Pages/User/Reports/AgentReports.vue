<template>
    <AppLayout>
        <div class="cb-page-shell h-full capitalize">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-semibold">{{ $t('Agent Reports') }}</h2>
                    <p class="text-sm text-slate-600">{{ $t('Performance metrics for your team members') }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-4">
                    <div class="flex items-center gap-2">
                        <input v-model="startDate" type="date" class="border rounded p-2 text-sm" />
                        <span class="text-sm text-slate-400">—</span>
                        <input v-model="endDate" type="date" class="border rounded p-2 text-sm" />
                    </div>

                    <!-- Business Hours Toggle - clickable box -->
                    <div @click="toggleBusinessHours"
                        class="flex items-center gap-3 bg-white border border-slate-200 p-1.5 rounded-lg px-3 cursor-pointer select-none hover:border-primary/50 transition-colors"
                        :class="isRTL ? 'flex-row-reverse' : ''">
                        <label class="text-sm font-medium text-slate-700 whitespace-nowrap cursor-pointer">{{
                            $t('Business Hours') }}</label>
                        <button type="button" :class="includeBusinessHours ? 'bg-primary' : 'bg-slate-200'"
                            class="relative inline-flex h-5 w-10 items-center rounded-full transition-colors focus:outline-none pointer-events-none">
                            <span :class="[
                                includeBusinessHours ? (isRTL ? '-translate-x-5' : 'translate-x-5') : (isRTL ? '-translate-x-1' : 'translate-x-1'),
                                'inline-block h-3 w-3 transform rounded-full bg-white transition-transform'
                            ]" />
                        </button>
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="button" @click="applyFilter"
                            class="bg-primary text-white px-4 py-2 rounded text-sm font-medium hover:bg-primary/90 transition-colors">{{
                                $t('Apply') }}</button>
                        <button type="button" @click="setToday"
                            class="text-sm px-3 py-2 border border-slate-200 rounded bg-white hover:bg-slate-50 transition-colors">{{
                                $t('Today') }}</button>
                        <button type="button" @click="setLast7Days"
                            class="text-sm px-3 py-2 border border-slate-200 rounded bg-white hover:bg-slate-50 transition-colors">{{
                                $t('Last 7 Days') }}</button>
                    </div>
                </div>
            </div>

            <div class="mt-8 bg-white rounded-lg shadow-sm border overflow-hidden">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 border-b">
                        <tr>
                            <th class="px-6 py-4 font-semibold text-slate-700">{{ $t('Agent Name') }}</th>
                            <th class="px-6 py-4 font-semibold text-slate-700 text-center">{{ $t('Open Conversations')
                            }}</th>
                            <th class="px-6 py-4 font-semibold text-slate-700 text-center">{{ $t('Closed Conversations')
                            }}</th>
                            <th class="px-6 py-4 font-semibold text-slate-700 text-center">{{ $t('Avg. First Response Time') }}</th>
                            <th class="px-6 py-4 font-semibold text-slate-700 text-center">{{ $t('Avg. Waiting Time') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y text-slate-600">
                        <tr v-for="(agent, index) in props.agent_reports" :key="index"
                            class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div
                                        class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-medium">
                                        {{ agent.agent_name.charAt(0) }}
                                    </div>
                                    <span class="font-medium text-slate-900">{{ agent.agent_name }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    {{ agent.open_conversations_count }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    {{ agent.closed_conversations_count }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center font-mono">
                                {{ agent.avg_first_response_time }}
                            </td>
                            <td class="px-6 py-4 text-center font-mono">
                                {{ agent.avg_customer_waiting_time }}
                            </td>
                        </tr>
                        <tr v-if="props.agent_reports.length === 0">
                            <td colspan="5" class="px-6 py-12 text-center text-slate-500">
                                <div class="flex flex-col items-center gap-2">
                                    <svg class="w-12 h-12 text-slate-300" xmlns="http://www.w3.org/2000/svg" fill="none"
                                        viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                            d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                    </svg>
                                    <p>{{ $t('No agent data found for the selected period') }}</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from "../Layout/App.vue";
import { router, usePage } from "@inertiajs/vue3";
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { locale } = useI18n();
const isRTL = computed(() => locale.value === 'ar');

const props = defineProps({
    agent_reports: { type: Array, default: () => [] },
    initial_start_date: { type: String, default: '' },
    initial_end_date: { type: String, default: '' },
    business_hours: { type: Boolean, default: false },
});

const formatDate = (d) => {
    return d.toISOString().slice(0, 10);
};

const today = new Date();
const last7 = new Date();
last7.setDate(today.getDate() - 6);

const startDate = ref(props.initial_start_date || formatDate(last7));
const endDate = ref(props.initial_end_date || formatDate(today));
const includeBusinessHours = ref(props.business_hours);

const toggleBusinessHours = () => {
    includeBusinessHours.value = !includeBusinessHours.value;
    applyFilter();
};

const applyFilter = () => {
    const params = {};
    if (startDate.value) params.start_date = startDate.value;
    if (endDate.value) params.end_date = endDate.value;
    params.business_hours = includeBusinessHours.value;
    router.get('/reports/agent', params, { preserveState: false, replace: true });
};

const setToday = () => {
    const t = formatDate(new Date());
    startDate.value = t;
    endDate.value = t;
    applyFilter();
};

const setLast7Days = () => {
    startDate.value = formatDate(last7);
    endDate.value = formatDate(today);
    applyFilter();
};
</script>
