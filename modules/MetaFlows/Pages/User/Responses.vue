<template>
    <AppLayout>

        <div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] overflow-y-scroll">

        <!-- ── Page header ─────────────────────────────────────────────────── -->
        <div class="flex items-center justify-between mb-6 mt-8 md:mt-0 flex-wrap gap-3">
            <div class="flex items-center gap-3">
                <a :href="'/automation/meta-flows/' + flow.uuid"
                   class="w-8 h-8 flex items-center justify-center rounded-lg border border-slate-200 text-slate-400 hover:text-slate-700 hover:border-slate-300 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                </a>
                <div>
                    <div class="flex items-center gap-2">
                        <h4 class="text-[15px] font-semibold text-slate-800">{{ flow.name }}</h4>
                        <span class="text-slate-400 text-sm">/</span>
                        <span class="text-sm text-slate-500">{{ $t('Responses') }}</span>
                    </div>
                    <p class="text-xs text-slate-400 mt-0.5">
                        {{ responses.total }} {{ $t('total submissions') }}
                    </p>
                </div>
            </div>

            <!-- Export CSV button -->
            <button
                @click="exportCsv"
                :disabled="!responses.data?.length"
                class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-40 transition-colors flex items-center gap-1.5"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7,10 12,15 17,10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                {{ $t('Export CSV') }}
            </button>
        </div>

        <!-- ── Stats strip ──────────────────────────────────────────────────── -->
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-5">
            <div class="bg-white border border-slate-200 rounded-xl p-4">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wide mb-1">{{ $t('Total Responses') }}</p>
                <p class="text-2xl font-bold text-slate-800">{{ responses.total }}</p>
            </div>
            <div class="bg-white border border-slate-200 rounded-xl p-4">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wide mb-1">{{ $t('Flow Status') }}</p>
                <span :class="['inline-flex items-center gap-1.5 text-sm font-medium capitalize', statusColor(flow.status)]">
                    <span :class="['w-2 h-2 rounded-full', statusDot(flow.status)]"></span>
                    {{ flow.status }}
                </span>
            </div>
            <div class="bg-white border border-slate-200 rounded-xl p-4 hidden md:block">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wide mb-1">{{ $t('Categories') }}</p>
                <div class="flex flex-wrap gap-1 mt-1">
                    <span v-for="cat in (flow.categories || [])" :key="cat"
                          class="text-xs px-2 py-0.5 rounded bg-slate-100 text-slate-600">
                        {{ cat.replace(/_/g, ' ') }}
                    </span>
                    <span v-if="!flow.categories?.length" class="text-slate-400 text-sm">—</span>
                </div>
            </div>
        </div>

        <!-- ── Responses table ──────────────────────────────────────────────── -->
        <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">

            <!-- Empty state -->
            <div v-if="!responses.data?.length" class="flex flex-col items-center justify-center py-20 text-center">
                <div class="w-14 h-14 rounded-2xl bg-slate-100 flex items-center justify-center mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </div>
                <p class="text-sm font-medium text-slate-600 mb-1">{{ $t('No responses yet') }}</p>
                <p class="text-xs text-slate-400 max-w-xs">{{ $t('Responses will appear here once contacts submit this flow.') }}</p>
            </div>

            <!-- Table -->
            <table v-else class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50">
                        <th class="text-start text-xs font-semibold text-slate-500 px-4 py-3 uppercase tracking-wide">#</th>
                        <th class="text-start text-xs font-semibold text-slate-500 px-4 py-3 uppercase tracking-wide">{{ $t('Contact') }}</th>
                        <th class="text-start text-xs font-semibold text-slate-500 px-4 py-3 uppercase tracking-wide hidden md:table-cell">{{ $t('Flow Token') }}</th>
                        <th class="text-start text-xs font-semibold text-slate-500 px-4 py-3 uppercase tracking-wide hidden sm:table-cell">{{ $t('Submitted') }}</th>
                        <th class="text-start text-xs font-semibold text-slate-500 px-4 py-3 uppercase tracking-wide">{{ $t('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="(response, index) in responses.data" :key="response.uuid">
                        <!-- Row -->
                        <tr
                            class="border-b border-slate-100 last:border-0 hover:bg-slate-50 transition-colors cursor-pointer"
                            @click="toggleExpand(response.uuid)"
                        >
                            <td class="px-4 py-3 text-slate-400 text-xs">
                                {{ (responses.current_page - 1) * responses.per_page + index + 1 }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-700">{{ response.contact_id ?? '—' }}</span>
                            </td>
                            <td class="px-4 py-3 hidden md:table-cell">
                                <span class="font-mono text-xs text-slate-400 truncate max-w-[120px] inline-block">
                                    {{ response.flow_token ? response.flow_token.substring(0, 12) + '...' : '—' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 hidden sm:table-cell text-slate-500 text-xs">
                                {{ response.created_at }}
                            </td>
                            <td class="px-4 py-3">
                                <button
                                    @click.stop="toggleExpand(response.uuid)"
                                    :class="['flex items-center gap-1 text-xs font-medium transition-colors', expanded === response.uuid ? 'text-primary' : 'text-slate-400 hover:text-slate-700']"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" :class="expanded === response.uuid ? 'rotate-180' : ''" class="transition-transform"><path d="m6 9 6 6 6-6"/></svg>
                                    {{ expanded === response.uuid ? $t('Hide') : $t('View') }}
                                </button>
                            </td>
                        </tr>

                        <!-- Expanded response data -->
                        <tr v-if="expanded === response.uuid" :key="'exp-' + response.uuid">
                            <td colspan="5" class="bg-slate-50 border-b border-slate-200 px-4 py-4">
                                <div class="max-w-2xl">
                                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-3">{{ $t('Submitted Data') }}</p>

                                    <div v-if="parsedResponse(response.response_json)">
                                        <div
                                            v-for="(value, key) in parsedResponse(response.response_json)"
                                            :key="key"
                                            class="flex gap-3 py-1.5 border-b border-slate-200 last:border-0"
                                        >
                                            <span class="text-xs font-medium text-slate-500 w-40 shrink-0 pt-0.5">{{ formatKey(key) }}</span>
                                            <span class="text-sm text-slate-800 break-all">{{ formatValue(value) }}</span>
                                        </div>
                                    </div>

                                    <div v-else class="text-xs text-slate-400">{{ $t('No structured data available.') }}</div>

                                    <!-- Raw JSON toggle -->
                                    <button
                                        @click="showingRaw === response.uuid ? showingRaw = null : showingRaw = response.uuid"
                                        class="mt-3 text-xs text-slate-400 hover:text-slate-600 underline"
                                    >
                                        {{ showingRaw === response.uuid ? $t('Hide raw JSON') : $t('Show raw JSON') }}
                                    </button>
                                    <pre v-if="showingRaw === response.uuid"
                                         class="mt-2 text-xs bg-slate-800 text-green-400 rounded-lg p-4 overflow-x-auto leading-relaxed">{{ prettyJson(response.response_json) }}</pre>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <!-- Pagination -->
            <div v-if="responses.last_page > 1" class="px-4 py-3 border-t border-slate-200 flex items-center justify-between text-sm text-slate-500">
                <span class="text-xs">
                    {{ $t('Showing') }} {{ responses.from }}–{{ responses.to }} {{ $t('of') }} {{ responses.total }}
                </span>
                <div class="flex gap-1">
                    <a
                        v-for="link in responses.links"
                        :key="link.label"
                        :href="link.url ?? '#'"
                        :class="[
                            'px-3 py-1 rounded-md text-xs font-medium transition-colors',
                            link.active ? 'bg-primary text-white' : 'border border-slate-200 text-slate-500 hover:bg-slate-50',
                            !link.url ? 'opacity-40 pointer-events-none' : '',
                        ]"
                        v-html="link.label"
                    ></a>
                </div>
            </div>
        </div>

        </div><!-- end page wrapper -->
    </AppLayout>
</template>

<script setup>
    import AppLayout from '@/Pages/User/Layout/App.vue';
    import { ref }   from 'vue';

    const props = defineProps(['flow', 'responses']);

    const expanded   = ref(null);
    const showingRaw = ref(null);

    const toggleExpand = (uuid) => {
        expanded.value   = expanded.value === uuid ? null : uuid;
        showingRaw.value = null;
    };

    const statusColor = (status) => {
        const map = { published: 'text-green-600', draft: 'text-slate-500', deprecated: 'text-orange-500', blocked: 'text-red-500' };
        return map[status] ?? 'text-slate-500';
    };

    const statusDot = (status) => {
        const map = { published: 'bg-green-500', draft: 'bg-slate-400', deprecated: 'bg-orange-500', blocked: 'bg-red-500' };
        return map[status] ?? 'bg-slate-400';
    };

    // Parse response_json — it can be a string or already an object
    const parsedResponse = (raw) => {
        try {
            const obj = typeof raw === 'string' ? JSON.parse(raw) : raw;
            // WhatsApp Flow responses are nested; try to find the data payload
            return obj?.response_payload?.data ?? obj?.data ?? (typeof obj === 'object' ? obj : null);
        } catch {
            return null;
        }
    };

    const prettyJson = (raw) => {
        try {
            const obj = typeof raw === 'string' ? JSON.parse(raw) : raw;
            return JSON.stringify(obj, null, 2);
        } catch {
            return raw;
        }
    };

    const formatKey   = (key) => key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    const formatValue = (val) => Array.isArray(val) ? val.join(', ') : String(val ?? '');

    // ── Export CSV ────────────────────────────────────────────────────────────
    const exportCsv = () => {
        if (!props.responses.data?.length) return;

        // Collect all unique keys across all responses
        const allKeys = new Set();
        props.responses.data.forEach(r => {
            const data = parsedResponse(r.response_json);
            if (data) Object.keys(data).forEach(k => allKeys.add(k));
        });

        const headers = ['Submitted At', 'Contact', 'Flow Token', ...allKeys];
        const rows = props.responses.data.map(r => {
            const data = parsedResponse(r.response_json) ?? {};
            return [
                r.created_at,
                r.contact_id ?? '',
                r.flow_token ?? '',
                ...[...allKeys].map(k => formatValue(data[k])),
            ].map(v => `"${String(v).replace(/"/g, '""')}"`).join(',');
        });

        const csv  = [headers.join(','), ...rows].join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = `${props.flow.name}_responses.csv`;
        a.click();
        URL.revokeObjectURL(url);
    };
</script>
