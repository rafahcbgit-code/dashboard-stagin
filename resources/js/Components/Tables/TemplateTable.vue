<template>
    <section aria-live="polite">
        <div v-if="rows.data?.length" class="grid gap-4 lg:grid-cols-2">
            <article v-for="item in rows.data" :key="item.uuid" class="cb-hover-lift flex min-w-0 flex-col rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-slate-300 hover:shadow-md">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2"><h2 class="truncate font-bold text-slate-900" dir="auto">{{ item.name }}</h2><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusClass(item.status)">{{ statusLabel(item.status) }}</span></div>
                        <p class="mt-2 text-xs text-slate-500">{{ categoryLabel(item.category) }} · {{ languageLabel(item.language) }}</p>
                    </div>
                    <Dropdown :align="locale === 'ar' ? 'left' : 'right'" :portal="true">
                        <button type="button" :aria-label="$t('More options')" class="flex size-9 shrink-0 items-center justify-center rounded-full text-slate-600 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">•••</button>
                        <template #items><DropdownItemGroup>
                            <DropdownItem :href="`/templates/${item.uuid}/show`">{{ $t('View details') }}</DropdownItem>
                            <DropdownItem v-if="capabilities.copy && connection.connected" :href="`/templates/${item.uuid}/copy`">{{ $t('Copy template') }}</DropdownItem>
                            <DropdownItem v-if="canEdit(item)" :href="`/templates/${item.uuid}`">{{ $t('Edit and resubmit') }}</DropdownItem>
                            <DropdownItem v-if="capabilities.delete" as="button" @click="openAlert(item.uuid)">{{ $t('Delete') }}</DropdownItem>
                        </DropdownItemGroup></template>
                    </Dropdown>
                </div>
                <div class="mt-4 rounded-lg border border-slate-100 bg-slate-50 p-3"><p class="line-clamp-3 min-h-[3.75rem] whitespace-pre-wrap text-sm leading-5 text-slate-700" dir="auto">{{ item.preview_text || $t('No message preview is available.') }}</p></div>
                <div v-if="item.category === 'MARKETING'" class="mt-3 flex items-start gap-2 rounded-lg px-3 py-2 text-xs leading-5" :class="item.has_unsubscribe_action ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-900'"><span aria-hidden="true">{{ item.has_unsubscribe_action ? '✓' : '!' }}</span><span>{{ item.has_unsubscribe_action ? $t('The unsubscribe action is included in this marketing template.') : $t('This marketing template needs an unsubscribe button before it can be used in a new campaign.') }}</span></div>
                <div v-if="item.last_rejection_reason && item.status === 'REJECTED'" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs leading-5 text-red-800"><strong>{{ $t('Rejection reason') }}:</strong> {{ item.last_rejection_reason }}</div>
                <footer class="mt-auto flex items-center justify-between gap-3 border-t border-slate-100 pt-4 text-xs text-slate-500"><span>{{ $t('Last updated') }}: {{ formatDate(item.updated_at) }}</span><Link :href="`/templates/${item.uuid}/show`" class="font-semibold text-emerald-800 hover:underline">{{ actionLabel(item.status) }}</Link></footer>
            </article>
        </div>
        <div v-else class="rounded-xl border border-dashed border-slate-300 bg-white px-5 py-12 text-center"><div class="mx-auto flex size-14 items-center justify-center rounded-full bg-slate-100 text-2xl" aria-hidden="true">▤</div><h2 class="mt-4 text-lg font-bold text-slate-900">{{ $t('No templates match your filters') }}</h2><p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-600">{{ $t('Change the search or filters, or create a new template when your WhatsApp number is connected.') }}</p><Link v-if="capabilities.create && connection.connected" href="/templates/create" class="mt-5 inline-flex min-h-10 items-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white">{{ $t('Create template') }}</Link></div>
        <nav v-if="rows.meta?.links?.length > 3" class="mt-5 flex flex-wrap justify-center gap-2" :aria-label="$t('Pagination')"><template v-for="link in rows.meta.links" :key="link.label"><Link v-if="link.url" :href="link.url" preserve-state preserve-scroll class="inline-flex min-h-9 min-w-9 items-center justify-center rounded-lg border px-3 text-sm" :class="link.active ? 'border-primary bg-primary text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'">{{ pageLabel(link.label) }}</Link><span v-else class="inline-flex min-h-9 min-w-9 items-center justify-center rounded-lg border border-slate-200 px-3 text-sm text-slate-400">{{ pageLabel(link.label) }}</span></template></nav>
        <AlertModal v-model="isOpenAlert" @confirm="() => confirmAlert(deleteAction)" :label="$t('Delete template')" :description="$t('Delete this template from Meta and this account? This action cannot be undone.')" />
    </section>
</template>

<script setup>
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AlertModal from '@/Components/AlertModal.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownItemGroup from '@/Components/DropdownItemGroup.vue';
import DropdownItem from '@/Components/DropdownItem.vue';
import { useAlertModal } from '@/Composables/useAlertModal';
import { toast } from 'vue3-toastify';
const props = defineProps({ rows: { type: Object, required: true }, capabilities: { type: Object, default: () => ({}) }, connection: { type: Object, default: () => ({ connected: false }) } });
const emit = defineEmits(['deleted']);
const { t, locale } = useI18n();
const { isOpenAlert, openAlert, confirmAlert } = useAlertModal();
const statusLabel = value => t({ PENDING: 'Under Meta review', APPROVED: 'Ready to use', REJECTED: 'Rejected by Meta', PAUSED: 'Temporarily paused', DISABLED: 'Disabled by Meta', IN_APPEAL: 'Appeal under review' }[value] || 'Unknown status');
const categoryLabel = value => t({ MARKETING: 'Marketing category', UTILITY: 'Utility category', AUTHENTICATION: 'Authentication category' }[value] || value);
const languageLabel = value => {
    const [language, region] = String(value || '').split(/[-_]/);
    if (!language) return t('Unknown language');
    try {
        const displayLocale = locale.value === 'ar' ? 'ar' : 'en';
        const languageName = new Intl.DisplayNames([displayLocale], { type: 'language' }).of(language);
        const regionName = region ? new Intl.DisplayNames([displayLocale], { type: 'region' }).of(region) : '';
        return regionName ? `${languageName} (${regionName})` : languageName;
    } catch {
        return String(value).replace('_', '-');
    }
};
const formatDate = value => {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
};
const pageLabel = value => {
    const label = String(value).replace(/<[^>]*>/g, '').replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').trim();
    if (/previous/i.test(label)) return t('Previous page');
    if (/next/i.test(label)) return t('Next page');
    return label;
};
const statusClass = value => ({ APPROVED: 'bg-emerald-100 text-emerald-800', PENDING: 'bg-amber-100 text-amber-800', REJECTED: 'bg-red-100 text-red-800', PAUSED: 'bg-orange-100 text-orange-900', DISABLED: 'bg-slate-200 text-slate-800', IN_APPEAL: 'bg-blue-100 text-blue-800' }[value] || 'bg-slate-100 text-slate-700');
const actionLabel = status => ['REJECTED', 'PAUSED', 'DISABLED'].includes(status) ? t('See what to do') : t('View details');
const canEdit = item => props.capabilities.update && props.connection.connected && ['APPROVED', 'REJECTED', 'PAUSED'].includes(item.status);
const deleteAction = async uuid => { try { const { data } = await axios.delete(`/templates/${uuid}`); toast(data.success ? t('Template deleted successfully') : t('Something went wrong. Refresh the page and try again'), { autoClose: 3000, type: data.success ? 'success' : 'error' }); if (data.success) emit('deleted'); } catch { toast(t('Something went wrong. Refresh the page and try again'), { type: 'error' }); } };
</script>
