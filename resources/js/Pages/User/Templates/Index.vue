<template>
    <AppLayout>
        <main class="cb-page-shell">
            <div class="cb-page-inner">
                <header class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <h1 class="text-xl font-bold sm:text-2xl">{{ $t('Message templates') }}</h1>
                        <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{{ $t('Create reusable WhatsApp messages, follow Meta review, and know which templates are ready to send.') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button v-if="capabilities.sync" type="button" :disabled="syncing || !connection.connected" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 disabled:cursor-not-allowed disabled:opacity-50" @click="syncTemplates">
                            <svg class="size-4" :class="syncing ? 'animate-spin' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 11a8 8 0 0 0-15-3l-2 3m1-7v7h7M4 13a8 8 0 0 0 15 3l2-3m-1 7v-7h-7"/></svg>
                            {{ syncing ? $t('Synchronizing templates') : $t('Sync templates') }}
                        </button>
                        <Link v-if="capabilities.create && connection.connected" href="/templates/create" class="cb-primary-button">{{ $t('Create template') }}</Link>
                    </div>
                </header>

                <section v-if="!connection.connected" class="rounded-xl border border-red-200 bg-red-50 p-4" aria-live="polite">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-700" aria-hidden="true">!</span>
                            <div>
                                <h2 class="font-semibold text-red-950">{{ $t('WhatsApp number is not connected') }}</h2>
                                <p class="mt-1 text-sm leading-6 text-red-800">{{ $t('Reconnect the number before creating, editing, or synchronizing templates. You can still view the saved templates.') }}</p>
                            </div>
                        </div>
                        <Link v-if="capabilities.update" :href="connection.settings_url" class="inline-flex min-h-10 shrink-0 items-center justify-center rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">{{ $t('Reconnect number') }}</Link>
                    </div>
                </section>

                <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" :aria-label="$t('Template summary')">
                    <article v-for="item in summaryCards" :key="item.key" class="cb-stat-card group rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-sm font-medium text-slate-500">{{ item.label }}</p>
                        <p class="mt-2 text-2xl font-bold" :class="item.color">{{ item.value }}</p>
                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ item.help }}</p>
                    </article>
                </section>

                <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="template-filters-title">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-end">
                        <div class="min-w-0 flex-1">
                            <label id="template-filters-title" for="template-search" class="block text-sm font-semibold text-slate-800">{{ $t('Search templates') }}</label>
                            <div class="relative mt-2">
                                <svg class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                                <input id="template-search" v-model="form.search" type="search" autocomplete="off" :placeholder="$t('Search by template name')" class="block min-h-11 w-full rounded-lg border border-slate-300 bg-white py-2 pe-3 ps-10 text-start text-sm outline-none focus:border-emerald-700 focus:ring-2 focus:ring-emerald-700/15" @input="scheduleFilter" />
                            </div>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2 xl:w-[34rem]">
                            <FormSelect
                                v-model="form.category"
                                :name="$t('Category')"
                                :options="categoryOptions"
                                :placeholder="$t('All categories')"
                                :class-name="'min-w-0'"
                                @update:model-value="applyFilters"
                            />
                            <FormSelect
                                v-model="form.status"
                                :name="$t('Status')"
                                :options="statusOptions"
                                :placeholder="$t('All statuses')"
                                :class-name="'min-w-0'"
                                @update:model-value="applyFilters"
                            />
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                        <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                            <input v-model="form.needs_action" type="checkbox" class="size-4 rounded border-slate-300 text-primary focus:ring-primary" @change="applyFilters" />
                            {{ $t('Show templates that need action only') }}
                        </label>
                        <button v-if="hasFilters" type="button" class="text-sm font-semibold text-emerald-800 hover:underline" @click="clearFilters">{{ $t('Clear filters') }}</button>
                    </div>
                </section>

                <div v-if="syncMessage" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm leading-6 text-emerald-900" role="status">{{ syncMessage }}</div>
                <TemplateTable :rows="rows" :capabilities="capabilities" :connection="connection" @deleted="reloadRows" />
            </div>
        </main>
    </AppLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import AppLayout from './../Layout/App.vue';
import TemplateTable from '@/Components/Tables/TemplateTable.vue';
import FormSelect from '@/Components/FormSelect.vue';

const props = defineProps({ rows: { type: Object, required: true }, filters: { type: Object, default: () => ({}) }, stats: { type: Object, default: () => ({}) }, capabilities: { type: Object, default: () => ({}) }, connection: { type: Object, default: () => ({ connected: false, settings_url: '/settings/whatsapp' }) } });
const { t } = useI18n();
const statuses = ['PENDING', 'APPROVED', 'REJECTED', 'PAUSED', 'DISABLED', 'IN_APPEAL'];
const form = reactive({ search: props.filters.search || '', category: props.filters.category || '', status: props.filters.status || '', needs_action: Boolean(props.filters.needsAction || props.filters.needs_action) });
const syncing = ref(false);
const syncMessage = ref('');
let filterTimer;
let syncTimer;

const statusLabel = (status) => t({ PENDING: 'Under Meta review', APPROVED: 'Ready to use', REJECTED: 'Rejected by Meta', PAUSED: 'Temporarily paused', DISABLED: 'Disabled by Meta', IN_APPEAL: 'Appeal under review' }[status] || 'Unknown status');
const categoryOptions = computed(() => [
    { value: '', label: t('All categories') },
    { value: 'MARKETING', label: t('Marketing category') },
    { value: 'UTILITY', label: t('Utility category') },
    { value: 'AUTHENTICATION', label: t('Authentication category') },
]);
const statusOptions = computed(() => [
    { value: '', label: t('All statuses') },
    ...statuses.map((status) => ({ value: status, label: statusLabel(status) })),
]);
const summaryCards = computed(() => [
    { key: 'total', label: t('All templates'), value: props.stats.total || 0, help: t('All templates saved for this WhatsApp account.'), color: 'text-slate-900' },
    { key: 'approved', label: t('Ready to use'), value: props.stats.approved || 0, help: t('Approved templates that can be selected for sending.'), color: 'text-emerald-700' },
    { key: 'pending', label: t('Under Meta review'), value: props.stats.pending || 0, help: t('Meta is reviewing these templates now.'), color: 'text-amber-700' },
    { key: 'action', label: t('Needs action'), value: props.stats.needs_action || 0, help: t('Rejected, paused, disabled, or missing unsubscribe setup.'), color: 'text-red-700' },
]);
const hasFilters = computed(() => Boolean(form.search || form.category || form.status || form.needs_action));
const query = () => Object.fromEntries(Object.entries(form).filter(([, value]) => value !== '' && value !== false));
const applyFilters = () => router.get('/templates', query(), { preserveState: true, preserveScroll: true, replace: true, only: ['rows', 'filters', 'stats'] });
const scheduleFilter = () => { clearTimeout(filterTimer); filterTimer = setTimeout(applyFilters, 300); };
const clearFilters = () => { Object.assign(form, { search: '', category: '', status: '', needs_action: false }); applyFilters(); };
const reloadRows = () => router.reload({ only: ['rows', 'stats'] });
const pollSync = async () => {
    try {
        const { data } = await axios.get('/templates/sync/status');
        if (['queued', 'running'].includes(data.status)) { syncing.value = true; syncTimer = setTimeout(pollSync, 1800); return; }
        syncing.value = false;
        if (data.status === 'complete') {
            const counts = data.counts || {};
            syncMessage.value = t('Template sync result', { created: counts.created || 0, updated: counts.updated || 0, changed: counts.status_changed || 0, unchanged: counts.unchanged || 0 });
            router.reload({ only: ['rows', 'stats'] });
        } else if (data.status === 'failed') syncMessage.value = data.message || t('Template synchronization failed. Please try again.');
    } catch { syncing.value = false; syncMessage.value = t('Template synchronization failed. Please try again.'); }
};
const syncTemplates = async () => {
    syncing.value = true; syncMessage.value = '';
    try { await axios.post('/templates/sync'); await pollSync(); }
    catch (error) { syncing.value = false; syncMessage.value = error.response?.data?.message || t('Template synchronization failed. Please try again.'); }
};
onBeforeUnmount(() => { clearTimeout(filterTimer); clearTimeout(syncTimer); });
</script>
