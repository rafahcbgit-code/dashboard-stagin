<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import debounce from 'lodash/debounce';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    rows: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    rowCount: { type: Number, default: 0 },
    filteredCount: { type: Number, default: 0 },
    contactGroups: { type: Array, default: () => [] },
    labels: { type: Array, default: () => [] },
    fields: { type: Array, default: () => [] },
    role: { type: String, default: 'owner' },
});

const page = usePage();
const contactConstants = computed(() => page.props.contactConstants || { countries: [], staticCustomAttributes: [] });
const selectionStorageKey = computed(() => {
    const organizationId = page.props?.organization?.id ?? 'default';
    return `advanced-contact-selected-ids-${organizationId}`;
});

const query = reactive({
    search: props.filters?.search ?? '',
    group_id: props.filters?.group_id ?? '',
    label_id: props.filters?.label_id ?? '',
    subscription_status: props.filters?.subscription_status ?? '',
    no_group: props.filters?.no_group === '1' || props.filters?.no_group === true,
    no_label: props.filters?.no_label === '1' || props.filters?.no_label === true,
    created_from: props.filters?.created_from ?? '',
    created_to: props.filters?.created_to ?? '',
    custom_attribute_key: props.filters?.custom_attribute_key ?? '',
    custom_attribute_value: props.filters?.custom_attribute_value ?? '',
    page: Number(props.filters?.page ?? 1),
});

const isFilterModalOpen = ref(false);
const isColumnsMenuOpen = ref(false);
const columnsMenuRef = ref(null);
const selectedIds = ref([]);
const bulkActionModal = ref('');
const bulkForm = reactive({
    group_ids: [],
    label_ids: [],
    subscription_status: '1',
});

const defaultColumns = {
    name: true,
    phone: true,
    email: true,
    groups: true,
    labels: true,
    subscription: true,
    country: true,
    custom_attributes: true,
    created_at: true,
};

const columns = ref(loadColumns());

function loadColumns() {
    if (typeof window === 'undefined') {
        return { ...defaultColumns };
    }

    try {
        const cached = localStorage.getItem('advanced-contact-columns');
        if (!cached) {
            return { ...defaultColumns };
        }

        return { ...defaultColumns, ...JSON.parse(cached) };
    } catch (error) {
        return { ...defaultColumns };
    }
}

watch(columns, (value) => {
    if (typeof window === 'undefined') {
        return;
    }

    localStorage.setItem('advanced-contact-columns', JSON.stringify(value));
}, { deep: true });

const loadSelectedIds = () => {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        const cached = localStorage.getItem(selectionStorageKey.value);
        selectedIds.value = cached ? JSON.parse(cached) : [];
    } catch (error) {
        selectedIds.value = [];
    }
};

watch(selectedIds, (value) => {
    if (typeof window === 'undefined') {
        return;
    }

    localStorage.setItem(selectionStorageKey.value, JSON.stringify(value));
}, { deep: true });

const idsOnPage = computed(() => props.rows.data.map((contact) => contact.id));
const selectedCount = computed(() => selectedIds.value.length);
const allOnPageSelected = computed(() => idsOnPage.value.length > 0 && idsOnPage.value.every((id) => selectedIds.value.includes(id)));
const totalPages = computed(() => props.rows.meta?.last_page ?? 1);
const currentPage = computed(() => props.rows.meta?.current_page ?? 1);

const availableCustomAttributes = computed(() => {
    const fieldKeys = props.fields.map((field) => ({
        value: field.name,
        label: field.name,
    }));

    return [...contactConstants.value.staticCustomAttributes, ...fieldKeys];
});

const runFilterRequest = (extra = {}) => {
    const payload = {
        search: query.search || undefined,
        group_id: query.group_id || undefined,
        label_id: query.label_id || undefined,
        subscription_status: query.subscription_status || undefined,
        country: query.country || undefined,
        no_group: query.no_group ? 1 : undefined,
        no_label: query.no_label ? 1 : undefined,
        created_from: query.created_from || undefined,
        created_to: query.created_to || undefined,
        custom_attribute_key: query.custom_attribute_key || undefined,
        custom_attribute_value: query.custom_attribute_value || undefined,
        page: query.page > 1 ? query.page : undefined,
        ...extra,
    };

    router.get('/contacts', payload, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const debouncedSearch = debounce(() => {
    query.page = 1;
    runFilterRequest();
}, 500);

const toggleSelectAll = () => {
    if (allOnPageSelected.value) {
        selectedIds.value = selectedIds.value.filter((id) => !idsOnPage.value.includes(id));
        return;
    }

    selectedIds.value = Array.from(new Set([...selectedIds.value, ...idsOnPage.value]));
};

const toggleRow = (id) => {
    if (selectedIds.value.includes(id)) {
        selectedIds.value = selectedIds.value.filter((selectedId) => selectedId !== id);
        return;
    }

    selectedIds.value.push(id);
};

const clearFilters = () => {
    Object.assign(query, {
        search: '',
        group_id: '',
        label_id: '',
        subscription_status: '',
        country: '',
        no_group: false,
        no_label: false,
        created_from: '',
        created_to: '',
        custom_attribute_key: '',
        custom_attribute_value: '',
        page: 1,
    });

    runFilterRequest({ page: undefined });
};

const goToPage = (pageNumber) => {
    if (pageNumber < 1 || pageNumber > totalPages.value) {
        return;
    }

    query.page = pageNumber;
    runFilterRequest();
};

const applyFilters = () => {
    query.page = 1;
    runFilterRequest({ page: undefined });
    isFilterModalOpen.value = false;
};

const parseAddress = (contact) => {
    if (!contact.address) {
        return {};
    }

    if (typeof contact.address === 'object') {
        return contact.address;
    }

    try {
        return JSON.parse(contact.address);
    } catch (error) {
        return {};
    }
};

const parseMetadata = (contact) => {
    if (!contact.metadata) {
        return {};
    }

    if (typeof contact.metadata === 'object') {
        return contact.metadata;
    }

    try {
        return JSON.parse(contact.metadata);
    } catch (error) {
        return {};
    }
};

const runBulkDelete = () => {
    if (selectedCount.value === 0 || !confirm('Delete selected contacts?')) {
        return;
    }

    router.post('/contacts/bulk-delete', { ids: selectedIds.value }, {
        preserveScroll: true,
        onSuccess: () => {
            selectedIds.value = [];
            localStorage.removeItem(selectionStorageKey.value);
        },
    });
};

const runBulkAction = () => {
    if (selectedCount.value === 0 || !bulkActionModal.value) {
        return;
    }

    const payload = { ids: selectedIds.value, action: bulkActionModal.value };

    if (bulkActionModal.value === 'add_group' || bulkActionModal.value === 'remove_group') {
        payload.group_ids = bulkForm.group_ids;
    }

    if (bulkActionModal.value === 'add_label' || bulkActionModal.value === 'remove_label') {
        payload.label_ids = bulkForm.label_ids;
    }

    if (bulkActionModal.value === 'change_subscription_status') {
        payload.subscription_status = bulkForm.subscription_status;
    }

    router.post('/contacts/bulk-update-attribute', payload, {
        preserveScroll: true,
        onSuccess: () => {
            bulkActionModal.value = '';
            bulkForm.group_ids = [];
            bulkForm.label_ids = [];
            selectedIds.value = [];
            localStorage.removeItem(selectionStorageKey.value);
        },
    });
};

const runExport = () => {
    const params = new URLSearchParams();
    selectedIds.value.forEach((id) => params.append('ids[]', id));
    window.open(`/contacts/export?${params.toString()}`, '_blank');
};

const toggleColumnsMenu = () => {
    isColumnsMenuOpen.value = !isColumnsMenuOpen.value;
};

const closeColumnsMenu = () => {
    isColumnsMenuOpen.value = false;
};

const handleOutsideClick = (event) => {
    if (!isColumnsMenuOpen.value) {
        return;
    }

    if (columnsMenuRef.value && !columnsMenuRef.value.contains(event.target)) {
        closeColumnsMenu();
    }
};

onMounted(() => {
    loadSelectedIds();
    document.addEventListener('click', handleOutsideClick);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleOutsideClick);
});
</script>

<template>
    <div class="space-y-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-slate-900">{{ $t('Contact Management') }}</h1>
                    <p class="text-sm text-slate-500">{{ filteredCount }} / {{ rowCount }} {{ $t('contacts') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Link href="/contacts/add" class="rounded-lg bg-primary px-3 py-2 text-sm font-medium text-white">{{ $t('Add Contact') }}</Link>
                    <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700" @click="isFilterModalOpen = true">{{ $t('Advanced Filters') }}</button>
                </div>
            </div>
            <div class="mt-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <input v-model="query.search" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none ring-primary/20 focus:border-primary focus:ring lg:max-w-md" :placeholder="$t('Search name, phone, country, or email')" @input="debouncedSearch">
                <div ref="columnsMenuRef" class="relative">
                    <button type="button" class="cursor-pointer rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700" @click.stop="toggleColumnsMenu">{{ $t('Columns') }}</button>
                    <div v-if="isColumnsMenuOpen" class="absolute right-0 z-20 mt-2 w-60 rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                        <label class="mb-2 flex items-center gap-2 text-sm text-slate-700"><input v-model="columns.name" type="checkbox"> {{ $t('Name') }}</label>
                        <label class="mb-2 flex items-center gap-2 text-sm text-slate-700"><input v-model="columns.phone" type="checkbox"> {{ $t('Phone') }}</label>
                        <label class="mb-2 flex items-center gap-2 text-sm text-slate-700"><input v-model="columns.email" type="checkbox"> {{ $t('Email') }}</label>
                        <label class="mb-2 flex items-center gap-2 text-sm text-slate-700"><input v-model="columns.groups" type="checkbox"> {{ $t('Groups') }}</label>
                        <label class="mb-2 flex items-center gap-2 text-sm text-slate-700"><input v-model="columns.labels" type="checkbox"> {{ $t('Labels') }}</label>
                        <label class="mb-2 flex items-center gap-2 text-sm text-slate-700"><input v-model="columns.subscription" type="checkbox"> {{ $t('Subscription') }}</label>
                        <label class="mb-2 flex items-center gap-2 text-sm text-slate-700"><input v-model="columns.country" type="checkbox"> {{ $t('Country') }}</label>
                        <label class="mb-2 flex items-center gap-2 text-sm text-slate-700"><input v-model="columns.custom_attributes" type="checkbox"> {{ $t('Custom Attributes') }}</label>
                        <label class="flex items-center gap-2 text-sm text-slate-700"><input v-model="columns.created_at" type="checkbox"> {{ $t('Created At') }}</label>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="selectedCount > 0" class="sticky top-16 z-20 flex flex-wrap items-center gap-2 rounded-xl border border-blue-200 bg-blue-50 p-3 shadow-sm">
            <span class="text-sm font-medium text-blue-700">{{ selectedCount }} {{ $t('selected') }}</span>
            <button type="button" class="rounded-md bg-white px-3 py-1.5 text-sm text-slate-700 ring-1 ring-slate-200" @click="selectedIds = []">{{ $t('Clear Selection') }}</button>
            <button v-if="role !== 'agent'" type="button" class="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white" @click="runBulkDelete">{{ $t('Delete') }}</button>
            <button type="button" class="rounded-md bg-white px-3 py-1.5 text-sm text-slate-700 ring-1 ring-slate-200" @click="bulkActionModal = 'add_group'">{{ $t('Add Group') }}</button>
            <button type="button" class="rounded-md bg-white px-3 py-1.5 text-sm text-slate-700 ring-1 ring-slate-200" @click="bulkActionModal = 'remove_group'">{{ $t('Remove Group') }}</button>
            <button type="button" class="rounded-md bg-white px-3 py-1.5 text-sm text-slate-700 ring-1 ring-slate-200" @click="bulkActionModal = 'add_label'">{{ $t('Add Labels') }}</button>
            <button type="button" class="rounded-md bg-white px-3 py-1.5 text-sm text-slate-700 ring-1 ring-slate-200" @click="bulkActionModal = 'remove_label'">{{ $t('Remove Labels') }}</button>
            <button type="button" class="rounded-md bg-white px-3 py-1.5 text-sm text-slate-700 ring-1 ring-slate-200" @click="bulkActionModal = 'change_subscription_status'">{{ $t('Change Subscription') }}</button>
            <button type="button" class="rounded-md bg-white px-3 py-1.5 text-sm text-slate-700 ring-1 ring-slate-200" @click="runExport">{{ $t('Export') }}</button>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-4 py-3">
                                <input type="checkbox" :checked="allOnPageSelected" @change="toggleSelectAll">
                            </th>
                            <th v-if="columns.name" class="px-4 py-3">{{ $t('Name') }}</th>
                            <th v-if="columns.phone" class="px-4 py-3">{{ $t('Phone') }}</th>
                            <th v-if="columns.email" class="px-4 py-3">{{ $t('Email') }}</th>
                            <th v-if="columns.groups" class="px-4 py-3">{{ $t('Groups') }}</th>
                            <th v-if="columns.labels" class="px-4 py-3">{{ $t('Labels') }}</th>
                            <th v-if="columns.subscription" class="px-4 py-3">{{ $t('Subscription') }}</th>
                            <th v-if="columns.country" class="px-4 py-3">{{ $t('Country') }}</th>
                            <th v-if="columns.custom_attributes" class="px-4 py-3">{{ $t('Custom Attributes') }}</th>
                            <th v-if="columns.created_at" class="px-4 py-3">{{ $t('Created At') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <tr v-for="contact in rows.data" :key="contact.id" class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <input type="checkbox" :checked="selectedIds.includes(contact.id)" @change="toggleRow(contact.id)">
                            </td>
                            <td v-if="columns.name" class="px-4 py-3">
                                <Link :href="`/contacts?id=${contact.uuid}`" class="font-medium text-slate-900 hover:text-primary">{{ contact.full_name }}</Link>
                            </td>
                            <td v-if="columns.phone" class="px-4 py-3">{{ contact.formatted_phone_number || '-' }}</td>
                            <td v-if="columns.email" class="px-4 py-3">{{ contact.email || '-' }}</td>
                            <td v-if="columns.groups" class="px-4 py-3">{{ contact.contact_groups?.map((group) => group.name).join(', ') || '-' }}</td>
                            <td v-if="columns.labels" class="px-4 py-3">{{ contact.labels?.map((label) => label.title).join(', ') || '-' }}</td>
                            <td v-if="columns.subscription" class="px-4 py-3">
                                <span class="rounded-full px-2 py-1 text-xs" :class="contact.is_subscribed ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'">
                                    {{ contact.is_subscribed ? $t('Subscribed') : $t('Unsubscribed') }}
                                </span>
                            </td>
                            <td v-if="columns.country" class="px-4 py-3">{{ parseAddress(contact).country || '-' }}</td>
                            <td v-if="columns.custom_attributes" class="px-4 py-3">
                                <span class="line-clamp-2">{{ Object.entries(parseMetadata(contact)).slice(0, 3).map(([key, value]) => `${key}: ${value}`).join(' | ') || '-' }}</span>
                            </td>
                            <td v-if="columns.created_at" class="px-4 py-3">{{ contact.created_at || '-' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-sm">
                <p class="text-slate-500">{{ rows.meta?.from || 0 }}-{{ rows.meta?.to || 0 }} / {{ rows.meta?.total || 0 }}</p>
                <div class="flex items-center gap-2">
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1 disabled:opacity-50" :disabled="currentPage <= 1" @click="goToPage(currentPage - 1)">{{ $t('Previous') }}</button>
                    <span class="text-slate-600">{{ currentPage }} / {{ totalPages }}</span>
                    <button type="button" class="rounded-md border border-slate-300 px-3 py-1 disabled:opacity-50" :disabled="currentPage >= totalPages" @click="goToPage(currentPage + 1)">{{ $t('Next') }}</button>
                </div>
            </div>
        </div>
    </div>

    <Modal :label="$t('Advanced Filters')" :isOpen="isFilterModalOpen" :closeBtn="true" @close="isFilterModalOpen = false">
        <div class="grid gap-3 pt-4">
            <label class="text-xs font-medium text-slate-500">{{ $t('Group') }}</label>
            <select v-model="query.group_id" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option value="">{{ $t('All Groups') }}</option>
                <option v-for="group in contactGroups" :key="group.id" :value="group.id">{{ group.name }}</option>
            </select>

            <label class="text-xs font-medium text-slate-500">{{ $t('Subscription Status') }}</label>
            <select v-model="query.subscription_status" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option value="">{{ $t('All Statuses') }}</option>
                <option value="1">{{ $t('Subscribed') }}</option>
                <option value="0">{{ $t('Unsubscribed') }}</option>
            </select>

            <label class="text-xs font-medium text-slate-500">{{ $t('Label') }}</label>
            <select v-model="query.label_id" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option value="">{{ $t('All Labels') }}</option>
                <option v-for="label in labels" :key="label.id" :value="label.id">{{ label.title }}</option>
            </select>


            <div class="flex items-center justify-between rounded-md bg-slate-50 px-3 py-2">
                <label class="text-sm text-slate-700">{{ $t('No Group') }}</label>
                <input v-model="query.no_group" type="checkbox">
            </div>

            <div class="flex items-center justify-between rounded-md bg-slate-50 px-3 py-2">
                <label class="text-sm text-slate-700">{{ $t('No Label') }}</label>
                <input v-model="query.no_label" type="checkbox">
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">{{ $t('Created From') }}</label>
                    <input v-model="query.created_from" type="date" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">{{ $t('Created To') }}</label>
                    <input v-model="query.created_to" type="date" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>

            <label class="text-xs font-medium text-slate-500">{{ $t('Custom Attribute') }}</label>
            <select v-model="query.custom_attribute_key" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option value="">{{ $t('Select Attribute') }}</option>
                <option v-for="attribute in availableCustomAttributes" :key="attribute.value" :value="attribute.value">{{ attribute.label }}</option>
            </select>
            <input v-model="query.custom_attribute_value" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" :placeholder="$t('Contains value')">

            <div class="mt-2 flex gap-2">
                <button type="button" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" @click="clearFilters">{{ $t('Reset') }}</button>
                <button type="button" class="w-full rounded-md bg-primary px-3 py-2 text-sm text-white" @click="applyFilters">{{ $t('Apply') }}</button>
            </div>
        </div>
    </Modal>

    <Modal :label="$t('Bulk Action')" :isOpen="!!bulkActionModal" :closeBtn="true" @close="bulkActionModal = ''">
        <div class="grid gap-3 pt-4">
            <template v-if="bulkActionModal === 'add_group' || bulkActionModal === 'remove_group'">
                <label class="text-xs font-medium text-slate-500">{{ $t('Select Groups') }}</label>
                <select v-model="bulkForm.group_ids" class="rounded-md border border-slate-300 px-3 py-2 text-sm" multiple>
                    <option v-for="group in contactGroups" :key="group.id" :value="group.id">{{ group.name }}</option>
                </select>
            </template>

            <template v-if="bulkActionModal === 'add_label' || bulkActionModal === 'remove_label'">
                <label class="text-xs font-medium text-slate-500">{{ $t('Select Labels') }}</label>
                <select v-model="bulkForm.label_ids" class="rounded-md border border-slate-300 px-3 py-2 text-sm" multiple>
                    <option v-for="label in labels" :key="label.id" :value="label.id">{{ label.title }}</option>
                </select>
            </template>

            <template v-if="bulkActionModal === 'change_subscription_status'">
                <label class="text-xs font-medium text-slate-500">{{ $t('Subscription Status') }}</label>
                <select v-model="bulkForm.subscription_status" class="rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="1">{{ $t('Subscribed') }}</option>
                    <option value="0">{{ $t('Unsubscribed') }}</option>
                </select>
            </template>

            <button type="button" class="mt-2 rounded-md bg-primary px-3 py-2 text-sm text-white" @click="runBulkAction">{{ $t('Apply Action') }}</button>
        </div>
    </Modal>
</template>
