<template>
    <AppLayout>
        <div class="cb-page-shell cb-general-reports h-full">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-semibold">{{ $t('General Reports') }}</h2>
                    <p class="text-sm text-slate-600">{{ $t('Analytics & insights for your contacts') }}</p>
                </div>

                <div class="flex items-center gap-2">
                    <input v-model="startDate" type="date" class="border rounded p-2 text-sm" />
                    <span class="text-sm text-slate-400">—</span>
                    <input v-model="endDate" type="date" class="border rounded p-2 text-sm" />
                    <button @click="applyFilter" class="cb-primary-button ml-2">{{
                        $t('Apply') }}</button>
                    <button @click="setToday" class="ml-2 text-sm px-3 py-2 border rounded">{{ $t('Today') }}</button>
                    <button @click="setLast7Days" class="ml-2 text-sm px-3 py-2 border rounded">{{ $t('Last 7 Days')
                    }}</button>
                </div>
            </div>

            <div class="cb-section mt-6 p-4">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm text-slate-600">{{ $t('Conversation activity') }}</h4>
                    <div class="text-xs text-slate-400">{{ $t('Daily conversations created') }}</div>
                </div>
                <div class="mt-3">
                    <apexchart type="bar" height="260" :options="conversationsAreaOptions"
                        :series="conversationsAreaSeries"></apexchart>
                </div>
            </div>

            <div class="cb-section mt-6 p-4">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm text-slate-600">{{ $t('Labels over time') }}</h4>
                    <div class="text-xs text-slate-400">{{ $t('Daily contacts assigned to labels') }}</div>
                </div>
                <div class="mt-3">
                    <apexchart type="bar" height="260" :options="labelsAreaOptions" :series="labelsAreaSeries">
                    </apexchart>
                </div>
            </div>

            <!-- Conversations metrics immediately under the chart -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-6">
                <div class="bg-white rounded-lg p-4 shadow-sm">
                    <h4 class="text-sm text-slate-500">{{ $t('Unassigned conversations') }}</h4>
                    <div class="text-2xl font-bold mt-2">{{ props.conversations ?
                        props.conversations.unassigned_all_time : 0 }}</div>
                    <div class="text-xs text-slate-400">{{ $t('All time') }}</div>
                </div>

                <div class="bg-white rounded-lg p-4 shadow-sm">
                    <h4 class="text-sm text-slate-500">{{ $t('Closed conversations') }}</h4>
                    <div class="text-2xl font-bold mt-2">{{ props.conversations ? props.conversations.closed : 0 }}
                    </div>
                    <div class="text-xs text-slate-400">{{ $t('Status = closed in the range') }}</div>
                </div>

                <div class="bg-white rounded-lg p-4 shadow-sm">
                    <h4 class="text-sm text-slate-500">{{ $t('Open conversations') }}</h4>
                    <div class="text-2xl font-bold mt-2">{{ props.conversations ? props.conversations.open : 0 }}</div>
                    <div class="text-xs text-slate-400">{{ $t('Status = open in the range') }}</div>
                </div>

                <div class="bg-white rounded-lg p-4 shadow-sm">
                    <h4 class="text-sm text-slate-600 mb-3">{{ $t('Messages') }}</h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <div class="text-xs text-slate-500">{{ $t('Sent') }}</div>
                            <div class="text-2xl font-bold text-blue-600 mt-1">{{ props.messages ? props.messages.sent :
                                0 }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-slate-500">{{ $t('Received') }}</div>
                            <div class="text-2xl font-bold text-green-600 mt-1">{{ props.messages ?
                                props.messages.received : 0 }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- End conversations metrics -->

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-6">
                <div
                    class="col-span-1 md:col-span-2 bg-gradient-to-r from-slate-50 to-white rounded-lg p-6 shadow-sm border">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm text-slate-500">{{ $t('Total contacts') }}</h3>
                            <div class="text-3xl font-bold text-slate-800">{{ props.total_contacts }}</div>
                            <div class="text-sm text-slate-400 mt-1">{{ $t('All contacts (all time)') }}
                            </div>
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
                        <div class="text-xs text-slate-400 mt-2">{{ newContactsPct }}% {{ $t('of total contacts') }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Duplicate conversation cards removed (now shown directly under the conversations chart) -->

            <div class="mt-6 bg-white rounded-lg p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm text-slate-600">{{ $t('Trends') }}</h4>
                    <div class="text-xs text-slate-400">{{ $t('New contacts over time') }}</div>
                </div>
                <div class="mt-3">
                    <!-- Fallback: simple area chart placeholder; frontend can later feed series data -->
                    <apexchart type="bar" height="260" :options="areaOptions" :series="areaSeries"></apexchart>
                </div>
            </div>

            <div class="mt-6 bg-white rounded-lg p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm text-slate-600">{{ $t('Labels') }}</h4>
                    <div class="text-xs text-slate-400">{{ $t('Contacts per label') }}</div>
                </div>

                <!-- <div class="mt-3 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <label class="text-sm text-slate-500">{{ $t('Period') }}</label>
                        <select v-model="labelPeriod" class="border rounded p-2 text-sm">
                            <option value="today">{{ $t('Today') }}</option>
                            <option value="yesterday">{{ $t('Yesterday') }}</option>
                            <option value="last7">{{ $t('Last 7 Days') }}</option>
                        </select>

                        <label class="ml-4 text-sm text-slate-500">{{ $t('Label') }}</label>
                        <select v-model="selectedLabelId" class="border rounded p-2 text-sm">
                            <option v-for="l in props.labels" :key="l.id" :value="l.id">{{ l.title }}</option>
                        </select>

                        <button @click="fetchLabelCount" :disabled="loadingLabelCount"
                            class="ml-3 bg-primary text-white px-3 py-2 rounded text-sm">
                            <span v-if="loadingLabelCount">{{ $t('Loading') }}</span>
                            <span v-else>{{ $t('Refresh') }}</span>
                        </button>
                    </div>

                    <div class="text-sm text-slate-400">{{ $t('Count') }}: <span class="font-semibold">{{ labelCount !==
                        null ? labelCount : '-' }}</span></div>
                </div> -->

                <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
                    <div class="overflow-x-auto">
                        <apexchart type="bar" :width="chartWidth" :height="chartHeight" :options="barOptions"
                            :series="barSeries"></apexchart>
                    </div>

                    <div class="overflow-y-auto max-h-72">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-slate-500">
                                    <th class="p-2">{{ $t('Label') }}</th>
                                    <th class="p-2">{{ $t('Count') }}</th>
                                    <th class="p-2">
                                        {{ $t('Percentage') }}
                                        <span class="ml-2 text-xs text-slate-400"
                                            :title="$t('Share of total contacts assigned to this label')">&#9432;</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="label in props.labels" :key="label.id" class="border-t">
                                    <td class="p-2 flex items-center gap-2">
                                        <span :style="{ backgroundColor: label.color }"
                                            class="w-3 h-3 rounded-full inline-block"></span>
                                        <span>{{ label.title }}</span>
                                    </td>
                                    <td class="p-2">{{ label.count }}</td>
                                    <td class="p-2">{{ label.percentage }}%</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from "../Layout/App.vue";
import { Link, router } from "@inertiajs/vue3";
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const props = defineProps({
    total_contacts: { type: Number, default: 0 },
    new_contacts: { type: Number, default: 0 },
    subscribed: { type: Object, default: () => ({ count: 0, percentage: 0 }) },
    unsubscribed: { type: Object, default: () => ({ count: 0, percentage: 0 }) },
    initial_start_date: { type: String, default: '' },
    initial_end_date: { type: String, default: '' },
    labels: { type: Array, default: () => [] },
    area_series: { type: Array, default: () => [] },
    conversations: { type: Object, default: () => ({ total: 0, open: 0, closed: 0 }) },
    conversations_series: { type: Object, default: () => ({ categories: [], data: [] }) },
    chats_series: { type: Object, default: () => ({ categories: [], inbound: [], outbound: [] }) },
    labels_series: { type: Object, default: () => ({ categories: [], series: [] }) },
    messages: { type: Object, default: () => ({ sent: 0, received: 0 }) },
});

const formatDate = (d) => {
    return d.toISOString().slice(0, 10);
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
    router.get('/reports/general', params, { preserveState: false, replace: true });
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

const donutOptions = computed(() => ({
    labels: [t('Subscribed'), t('Unsubscribed')],
    chart: { fontFamily: 'inherit', foreColor: '#526557', toolbar: { show: false } },
    colors: ['#35784b', '#a7c7b2'],
    legend: { position: 'bottom' },
    dataLabels: { enabled: false },
}));

// Label filter UI state
const labelPeriod = ref('last7');
const selectedLabelId = ref((props.labels && props.labels.length) ? props.labels[0].id : null);
const labelCount = ref(null);
const loadingLabelCount = ref(false);

const fetchLabelCount = async () => {
    if (!selectedLabelId.value) {
        labelCount.value = 0;
        return;
    }
    loadingLabelCount.value = true;
    try {
        const resp = await axios.get('/reports/label-count', { params: { label_id: selectedLabelId.value, period: labelPeriod.value } });
        labelCount.value = resp.data.count ?? 0;
    } catch (e) {
        console.error('fetchLabelCount error', e);
        labelCount.value = 0;
    } finally {
        loadingLabelCount.value = false;
    }
};

watch(() => props.labels, (newVal) => {
    if (newVal && newVal.length && !selectedLabelId.value) selectedLabelId.value = newVal[0].id;
}, { immediate: true });

onMounted(() => {
    if (selectedLabelId.value) fetchLabelCount();
});

// Auto-refresh when period or selected label changes to give immediate feedback
watch([labelPeriod, selectedLabelId], ([newPeriod, newLabel], [oldPeriod, oldLabel]) => {
    if (!newLabel) return;
    // avoid duplicate immediate call if onMounted already triggered
    fetchLabelCount();
});

const newContactsPct = computed(() => {
    const total = props.total_contacts || 0;
    if (total === 0) return 0;
    return Math.round((props.new_contacts / total) * 100);
});

// Area chart: use datetime points when provided, otherwise fallback to placeholder
const areaSeries = computed(() => {
    if (props.area_series && props.area_series.length) {
        return [{ name: t('New contacts'), data: props.area_series }];
    }
    return [{ name: t('New contacts'), data: [] }];
});


const barSeries = computed(() => {
    const counts = (props.labels || []).map(l => l.count || 0);
    return [{ name: t('Contacts'), data: counts }];
});
const maxLabelCount = computed(() => {
    const counts = (props.labels || []).map(l => l.count || 0);
    return counts.length ? Math.max(...counts) : 0;
});

const barOptions = computed(() => {
    const labels = (props.labels || []).map(l => l.title || '');
    const colors = (props.labels || []).map(l => l.color || '#6b7280');

    const count = Math.max(1, (props.labels || []).length);

    // choose a sensible columnWidth percent depending on number of labels
    let columnWidthPercent = '20%';
    if (count <= 8) columnWidthPercent = '40%';
    else if (count <= 20) columnWidthPercent = '25%';
    else if (count <= 50) columnWidthPercent = '12%';
    else columnWidthPercent = '6%';

    const xMax = Math.ceil((maxLabelCount.value || 1) * 1.05);

    return {
        ...threadChartOptions(),
        chart: { ...threadChartOptions().chart, type: 'bar' },
        plotOptions: { bar: { distributed: true, borderRadius: 4, columnWidth: columnWidthPercent, horizontal: true } },
        dataLabels: { enabled: true, formatter: (val) => `${val}`, style: { fontSize: '12px' } },
        colors: ['#35784b'],
        xaxis: {
            ...threadChartOptions().xaxis,
            categories: labels,
            // for horizontal charts xaxis is the numeric axis
            labels: {
                formatter: (val) => `${val}`
            },
            max: xMax
        },
        xaxisCategories: labels,
        yaxis: {
            // categories will appear on the y-axis for horizontal bars
            labels: {
                style: { fontSize: '12px' }
            }
        },
        legend: { show: false },
        tooltip: { y: { formatter: (val) => `${val}` } },
    };
});

// Dynamic chart height based on label count
const chartHeight = computed(() => {
    const perLabel = 36; // vertical px per label
    const minHeight = 360;
    const count = (props.labels || []).length || 1;
    return Math.max(minHeight, count * perLabel);
});

// Dynamic chart width based on label count (px per label, with a minimum width)
const chartWidth = computed(() => {
    const perLabel = 28; // px per label
    const minWidth = 800; // minimum width in px
    const count = (props.labels || []).length;
    const calculated = Math.max(minWidth, count * perLabel);
    return calculated;
});

// Mirror dashboard's messages area chart style and show inbound/outbound series when available
const conversationsAreaSeries = computed(() => {
    // if chats_series provided (inbound/outbound arrays), use two series similar to dashboard
    if (props.chats_series && props.chats_series.categories?.length) {
        return [
            { name: t('Inbound chats'), data: props.chats_series.inbound || [] },
            { name: t('Outbound chats'), data: props.chats_series.outbound || [] },
        ];
    }

    // fallback: use single conversations_series as datetime points
    if (props.conversations_series && Array.isArray(props.conversations_series.categories) && Array.isArray(props.conversations_series.data)) {
        const data = props.conversations_series.categories.map((d, i) => ({ x: d, y: props.conversations_series.data[i] || 0 }));
        return [{ name: t('Conversations'), data }];
    }

    return [{ name: t('Conversations'), data: [] }];
});


const labelsAreaSeries = computed(() => {
    if (props.labels_series && Array.isArray(props.labels_series.series)) {
        return props.labels_series.series;
    }
    return [];
});

// The Thread: solid paired columns, quiet axes, horizontal grid and two brand tones.
const threadChartOptions = () => ({
    chart: { type: 'bar', fontFamily: 'inherit', foreColor: '#526557', toolbar: { show: false }, zoom: { enabled: false }, parentHeightOffset: 0 },
    colors: ['#a7c7b2', '#35784b', '#8fb99a', '#234f32'],
    plotOptions: { bar: { columnWidth: '54%', borderRadius: 4, borderRadiusApplication: 'end' } },
    fill: { type: 'solid', opacity: 1 },
    stroke: { show: true, width: 3, colors: ['transparent'] },
    dataLabels: { enabled: false },
    grid: { borderColor: '#e6ede8', strokeDashArray: 0, xaxis: { lines: { show: false } }, yaxis: { lines: { show: true } } },
    xaxis: { type: 'datetime', axisBorder: { show: false }, axisTicks: { show: false }, labels: { style: { fontSize: '12px', colors: '#687b6d' }, datetimeUTC: false } },
    yaxis: { min: 0, forceNiceScale: true, decimalsInFloat: 0, tickAmount: 3, labels: { style: { fontSize: '12px', colors: '#687b6d' } } },
    legend: { position: 'top', horizontalAlign: 'right', fontSize: '13px', markers: { width: 9, height: 9, radius: 2 }, itemMargin: { horizontal: 10, vertical: 8 } },
    tooltip: { shared: true, intersect: false, x: { format: 'dd/MM/yyyy' } },
    noData: { text: t('No data available') },
});
const areaOptions = computed(() => ({ ...threadChartOptions(), colors: ['#35784b'] }));
const conversationsAreaOptions = computed(() => ({
    ...threadChartOptions(),
    xaxis: { ...threadChartOptions().xaxis, categories: props.chats_series?.categories?.length ? props.chats_series.categories : props.conversations_series.categories },
}));
const labelsAreaOptions = computed(() => ({
    ...threadChartOptions(),
    xaxis: { ...threadChartOptions().xaxis, categories: props.labels_series?.categories || [] },
}));
</script>

<style scoped>
.cb-general-reports :deep(.apexcharts-canvas) { direction: ltr; }
.cb-general-reports :deep(.apexcharts-tooltip) { direction: rtl; text-align: start; border-radius: 12px; border-color: #dce5de; box-shadow: 0 6px 20px #1f272114; }
.cb-general-reports .cb-section { background: #fff; border: 1px solid #e0e8e2; border-radius: 15px; padding: 20px; box-shadow: none; }
.cb-general-reports .cb-section h4 { font-size: 19px; font-weight: 700; color: #1f2721; }
</style>
