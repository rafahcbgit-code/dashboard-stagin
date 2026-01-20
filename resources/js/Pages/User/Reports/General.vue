<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit p-4 md:p-8 rounded-[5px] text-[#000] h-full overflow-y-auto capitalize">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-semibold">{{ $t('General Reports') }}</h2>
                    <p class="text-sm text-slate-600">{{ $t('Analytics & insights for your contacts') }}</p>
                </div>

                <div class="flex items-center gap-2">
                    <input v-model="startDate" type="date" class="border rounded p-2 text-sm" />
                    <span class="text-sm text-slate-400">—</span>
                    <input v-model="endDate" type="date" class="border rounded p-2 text-sm" />
                    <button @click="applyFilter" class="bg-primary text-white px-3 py-2 rounded ml-2 text-sm">{{ $t('Apply') }}</button>
                    <button @click="setToday" class="ml-2 text-sm px-3 py-2 border rounded">{{ $t('Today') }}</button>
                    <button @click="setLast7Days" class="ml-2 text-sm px-3 py-2 border rounded">{{ $t('Last 7 Days') }}</button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-6">
                <div class="col-span-1 md:col-span-2 bg-gradient-to-r from-slate-50 to-white rounded-lg p-6 shadow-sm border">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm text-slate-500">{{ $t('Total contacts') }}</h3>
                            <div class="text-3xl font-bold text-slate-800">{{ props.total_contacts }}</div>
                            <div class="text-sm text-slate-400 mt-1">{{ $t('All contacts in the selected range') }}</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="w-12 h-12 text-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5V4H2v16h5m10 0v-6a4 4 0 10-8 0v6"/></svg>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg p-4 shadow-sm flex flex-col items-center">
                    <h4 class="text-sm text-slate-500">{{ $t('Subscribed vs Unsubscribed') }}</h4>
                    <div class="w-full flex justify-center mt-3">
                        <apexchart type="donut" :options="donutOptions" :series="donutSeries" width="220"></apexchart>
                    </div>
                    <div class="w-full mt-3 flex justify-around text-sm">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-emerald-500 inline-block"></span>
                            <div>
                                <div class="font-semibold">{{ props.subscribed.count }}</div>
                                <div class="text-slate-400">{{ props.subscribed.percentage }}%</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-rose-500 inline-block"></span>
                            <div>
                                <div class="font-semibold">{{ props.unsubscribed.count }}</div>
                                <div class="text-slate-400">{{ props.unsubscribed.percentage }}%</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg p-4 shadow-sm md:col-span-1">
                    <h4 class="text-sm text-slate-500">{{ $t('New contacts (range)') }}</h4>
                    <div class="text-3xl font-bold text-slate-800 mt-3">{{ props.new_contacts }}</div>
                    <p class="text-sm text-slate-400 mt-2">{{ $t('Created during the selected period') }}</p>
                    <div class="mt-4">
                        <div class="w-full bg-slate-100 rounded h-2 overflow-hidden">
                            <div class="bg-primary h-2" :style="{ width: newContactsPct + '%' }"></div>
                        </div>
                        <div class="text-xs text-slate-400 mt-2">{{ newContactsPct }}% {{ $t('of total contacts') }}</div>
                    </div>
                </div>
            </div>

            <div class="mt-6 bg-white rounded-lg p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm text-slate-600">{{ $t('Trends') }}</h4>
                    <div class="text-xs text-slate-400">{{ $t('New contacts over time') }}</div>
                </div>
                <div class="mt-3">
                    <!-- Fallback: simple area chart placeholder; frontend can later feed series data -->
                    <apexchart type="area" height="220" :options="areaOptions" :series="areaSeries"></apexchart>
                </div>
            </div>

            <div class="mt-6 bg-white rounded-lg p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm text-slate-600">{{ $t('Labels distribution') }}</h4>
                    <div class="text-xs text-slate-400">{{ $t('Contacts per label') }}</div>
                </div>
                <div class="mt-3">
                    <apexchart type="bar" height="240" :options="barOptions" :series="barSeries"></apexchart>
                </div>
            </div>

            <div class="mt-6 bg-white rounded-lg p-4 shadow-sm overflow-x-auto">
                <h4 class="text-sm text-slate-600 mb-3">{{ $t('Labels') }}</h4>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500">
                            <th class="p-2">{{ $t('Label') }}</th>
                            <th class="p-2">{{ $t('Count') }}</th>
                            <th class="p-2">{{ $t('Percentage') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="label in props.labels" :key="label.id" class="border-t">
                            <td class="p-2 flex items-center gap-2">
                                <span :style="{ backgroundColor: label.color }" class="w-3 h-3 rounded-full inline-block"></span>
                                <span>{{ label.title }}</span>
                            </td>
                            <td class="p-2">{{ label.count }}</td>
                            <td class="p-2">{{ label.percentage }}%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from "../Layout/App.vue";
import { Link, router } from "@inertiajs/vue3";
import { ref, computed } from 'vue';

const props = defineProps({
    total_contacts: { type: Number, default: 0 },
    new_contacts: { type: Number, default: 0 },
    subscribed: { type: Object, default: () => ({ count: 0, percentage: 0 }) },
    unsubscribed: { type: Object, default: () => ({ count: 0, percentage: 0 }) },
    initial_start_date: { type: String, default: '' },
    initial_end_date: { type: String, default: '' },
    labels: { type: Array, default: () => [] },
    area_series: { type: Array, default: () => [] },
});

const formatDate = (d) => {
    return d.toISOString().slice(0,10);
};

const today = new Date();
const last7 = new Date();
last7.setDate(today.getDate() - 6);

const startDate = ref(props.initial_start_date || formatDate(last7));
const endDate = ref(props.initial_end_date || formatDate(today));

const applyFilter = () => {
    const params = {};
    if (startDate.value) params.start_date = startDate.value;
    if (endDate.value) params.end_date = endDate.value;
    router.get('/reports/general', params, { preserveState: true, replace: true });
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

const donutSeries = computed(() => [props.subscribed.count || 0, props.unsubscribed.count || 0]);

const donutOptions = {
    labels: [ 'Subscribed', 'Unsubscribed' ],
    colors: ['#16a34a', '#ef4444'],
    legend: { position: 'bottom' },
    dataLabels: { enabled: false },
};

const newContactsPct = computed(() => {
    const total = props.total_contacts || 0;
    if (total === 0) return 0;
    return Math.round((props.new_contacts / total) * 100);
});

// Area chart: use datetime points when provided, otherwise fallback to placeholder
const areaSeries = computed(() => {
    if (props.area_series && props.area_series.length) {
        return [{ name: 'New contacts', data: props.area_series }];
    }
    return [{ name: 'New contacts', data: [10, 20, 12, 25, 18, props.new_contacts || 0] }];
});

const areaOptions = {
    chart: { toolbar: { show: false }, zoom: { enabled: false } },
    stroke: { curve: 'smooth' },
    colors: ['#0ea5a4'],
    xaxis: { type: 'datetime' },
    tooltip: { x: { format: 'dd MMM' } },
};

const barSeries = computed(() => {
    const counts = (props.labels || []).map(l => l.count || 0);
    return [{ name: 'Contacts', data: counts }];
});

const barOptions = computed(() => {
    const labels = (props.labels || []).map(l => l.title || '');
    const colors = (props.labels || []).map(l => l.color || '#6b7280');
    return {
        chart: { toolbar: { show: false } },
        plotOptions: { bar: { distributed: true, borderRadius: 4, columnWidth: '10%' } },
        dataLabels: { enabled: false },
        colors: colors,
        xaxis: { categories: labels },
        legend: { show: false },
        tooltip: { y: { formatter: (val) => `${val}` } },
    };
});
</script>
