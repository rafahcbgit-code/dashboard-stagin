<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { Link, router, usePage } from '@inertiajs/vue3';
import debounce from 'lodash/debounce';
import Modal from '@/Components/Modal.vue';
import ContactImportWizardModal from '@/Components/ContactManagement/ContactImportWizardModal.vue';
import fallbackCountryDialCodes from '@/Data/countryDialCodes.json';

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
const contactConstants = computed(() => page.props.contactConstants || { countries: [], staticCustomAttributes: [], countryDialCodes: [] });
const NO_GROUP_OPTION = '__no_group__';
const NO_LABEL_OPTION = '__no_label__';
const selectionStorageKey = computed(() => {
    const organizationId = page.props?.organization?.id ?? 'default';
    return `advanced-contact-selected-ids-${organizationId}`;
});

const query = reactive({
    search: props.filters?.search ?? '',
    group_ids: parseMultiFilterValue(props.filters?.group_ids ?? props.filters?.group_id, props.filters?.no_group, NO_GROUP_OPTION),
    label_ids: parseMultiFilterValue(props.filters?.labels ?? props.filters?.label_id, props.filters?.no_label, NO_LABEL_OPTION),
    country: props.filters?.country ?? '',
    country_dial_code: props.filters?.country_dial_code ?? '',
    subscription_status: props.filters?.subscription_status ?? '',
    contact_source: props.filters?.contact_source ?? '',
    blocked_status: props.filters?.blocked_status ?? '',
    created_from: props.filters?.created_from ?? '',
    created_to: props.filters?.created_to ?? '',
    custom_attribute_key: props.filters?.custom_attribute_key ?? '',
    custom_attribute_value: props.filters?.custom_attribute_value ?? '',
    page: Number(props.filters?.page ?? 1),
});

function parseMultiFilterValue(value, includeNoOption = false, noOptionValue = '') {
    const values = Array.isArray(value)
        ? value
        : (typeof value === 'string'
            ? value.split(',')
            : (value !== null && value !== undefined && value !== '' ? [value] : []));

    const normalized = values
        .map((item) => String(item).trim())
        .filter((item) => item !== '');

    if (includeNoOption === true && noOptionValue && !normalized.includes(noOptionValue)) {
        normalized.push(noOptionValue);
    }

    return normalized;
}

const selectedGroupIdsForFilter = computed(() => query.group_ids.filter((id) => id !== NO_GROUP_OPTION));
const selectedLabelIdsForFilter = computed(() => query.label_ids.filter((id) => id !== NO_LABEL_OPTION));
const hasNoGroupFilter = computed(() => query.group_ids.includes(NO_GROUP_OPTION));
const hasNoLabelFilter = computed(() => query.label_ids.includes(NO_LABEL_OPTION));

const isFilterModalOpen = ref(false);
const isGroupsModalOpen = ref(false);
const isColumnsMenuOpen = ref(false);
const isImportWizardOpen = ref(false);
const columnsMenuRef = ref(null);
const selectAllCheckboxRef = ref(null);
const selectedIds = ref([]);
const deselectedIds = ref([]);
const allFilteredSelected = ref(false);
const bulkActionModal = ref('');
const bulkForm = reactive({
    group_ids: [],
    label_ids: [],
    subscription_status: '1',
});
const groups = ref([]);
const createGroupForm = reactive({ name: '' });
const isCreatingGroup = ref(false);
const createGroupError = ref('');
const deletingGroupUuid = ref('');
const selectedGroupUuidsForDeletion = ref([]);
const isDeletingGroups = ref(false);
const selectedGroupDeletionCount = computed(() => selectedGroupUuidsForDeletion.value.length);

const defaultColumns = {
    name: true,
    phone: true,
    email: true,
    groups: true,
    labels: true,
    subscription: true,
    contact_source: true,
    country: true,
    created_at: true,
};

const customColumnKey = (field) => `custom_attr_${field.uuid ?? field.name}`;

const buildDefaultColumns = () => ({
    ...defaultColumns,
    ...Object.fromEntries((props.fields || []).map((field) => [customColumnKey(field), true])),
});

const columns = ref(loadColumns());

function loadColumns() {
    const defaults = buildDefaultColumns();

    if (typeof window === 'undefined') {
        return defaults;
    }

    try {
        const cached = localStorage.getItem('advanced-contact-columns');
        if (!cached) {
            return defaults;
        }

        const parsed = JSON.parse(cached);
        const merged = { ...defaults, ...parsed };
        const shouldHideCustomColumns = parsed?.custom_attributes === false;

        if (shouldHideCustomColumns) {
            Object.keys(merged)
                .filter((key) => key.startsWith('custom_attr_'))
                .forEach((key) => {
                    merged[key] = false;
                });
        }

        return merged;
    } catch (error) {
        return defaults;
    }
}

watch(
    () => props.fields,
    () => {
        const keys = (props.fields || []).map((field) => customColumnKey(field));
        const nextColumns = { ...columns.value };

        keys.forEach((key) => {
            if (typeof nextColumns[key] === 'undefined') {
                nextColumns[key] = true;
            }
        });

        Object.keys(nextColumns)
            .filter((key) => key.startsWith('custom_attr_') && !keys.includes(key))
            .forEach((key) => {
                delete nextColumns[key];
            });

        columns.value = nextColumns;
    },
    { immediate: true, deep: true }
);

watch(columns, (value) => {
    if (typeof window === 'undefined') {
        return;
    }

    localStorage.setItem('advanced-contact-columns', JSON.stringify(value));
}, { deep: true });

watch(
    () => props.contactGroups,
    (value) => {
        groups.value = Array.isArray(value) ? [...value] : [];
    },
    { immediate: true }
);

const loadSelectedIds = () => {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        const cached = localStorage.getItem(selectionStorageKey.value);
        if (!cached) {
            selectedIds.value = [];
            deselectedIds.value = [];
            allFilteredSelected.value = false;
            return;
        }

        const parsed = JSON.parse(cached);
        if (Array.isArray(parsed)) {
            selectedIds.value = parsed;
            deselectedIds.value = [];
            allFilteredSelected.value = false;
            return;
        }

        selectedIds.value = Array.isArray(parsed.selectedIds) ? parsed.selectedIds : [];
        deselectedIds.value = Array.isArray(parsed.deselectedIds) ? parsed.deselectedIds : [];
        allFilteredSelected.value = parsed.allFilteredSelected === true;
    } catch (error) {
        selectedIds.value = [];
        deselectedIds.value = [];
        allFilteredSelected.value = false;
    }
};

watch([selectedIds, deselectedIds, allFilteredSelected], () => {
    if (typeof window === 'undefined') {
        return;
    }

    localStorage.setItem(selectionStorageKey.value, JSON.stringify({
        selectedIds: selectedIds.value,
        deselectedIds: deselectedIds.value,
        allFilteredSelected: allFilteredSelected.value,
    }));
}, { deep: true });

const idsOnPage = computed(() => props.rows.data.map((contact) => contact.id));
const isRowSelected = (id) => (allFilteredSelected.value ? !deselectedIds.value.includes(id) : selectedIds.value.includes(id));
const selectedCount = computed(() => {
    if (!allFilteredSelected.value) {
        return selectedIds.value.length;
    }

    return Math.max((Number(props.filteredCount) || 0) - deselectedIds.value.length, 0);
});
const allOnPageSelected = computed(() => idsOnPage.value.length > 0 && idsOnPage.value.every((id) => isRowSelected(id)));
const someOnPageSelected = computed(() => !allOnPageSelected.value && idsOnPage.value.some((id) => isRowSelected(id)));
const totalPages = computed(() => props.rows.meta?.last_page ?? 1);
const currentPage = computed(() => props.rows.meta?.current_page ?? 1);

const availableCustomAttributes = computed(() => {
    const hiddenAttributeValues = new Set(['source', 'lifecycle_stage', 'preferred_language']);
    const fieldKeys = props.fields.map((field) => ({
        value: field.name,
        label: field.name,
    }));

    return [...contactConstants.value.staticCustomAttributes, ...fieldKeys]
        .filter((attribute) => !hiddenAttributeValues.has(String(attribute?.value ?? '').toLowerCase()));
});

const countryDialCodeOptions = computed(() => {
    const options = Array.isArray(contactConstants.value.countryDialCodes) ? contactConstants.value.countryDialCodes : [];
    return options.length ? options : fallbackCountryDialCodes;
});

const activeFilterCount = computed(() => {
    let count = 0;

    if ((query.search || '').trim() !== '') count += 1;
    if (selectedGroupIdsForFilter.value.length > 0) count += 1;
    if (hasNoGroupFilter.value) count += 1;
    if (selectedLabelIdsForFilter.value.length > 0) count += 1;
    if (hasNoLabelFilter.value) count += 1;
    if ((query.country_dial_code || '').trim() !== '') count += 1;
    if ((query.subscription_status || '').trim() !== '') count += 1;
    if ((query.contact_source || '').trim() !== '') count += 1;
    if ((query.blocked_status || '').trim() !== '') count += 1;
    if ((query.created_from || '').trim() !== '' || (query.created_to || '').trim() !== '') count += 1;
    if ((query.custom_attribute_key || '').trim() !== '' && (query.custom_attribute_value || '').trim() !== '') count += 1;

    return count;
});

const contactSourceOptions = [
    { value: 'platform', label: 'Platform' },
    { value: 'imported', label: 'Imported' },
    { value: 'whatsapp', label: 'WhatsApp' },
    { value: 'api', label: 'API' },
];

const runFilterRequest = (extra = {}) => {
    const payload = {
        search: query.search || undefined,
        group_ids: selectedGroupIdsForFilter.value.length ? selectedGroupIdsForFilter.value : undefined,
        labels: selectedLabelIdsForFilter.value.length ? selectedLabelIdsForFilter.value : undefined,
        subscription_status: query.subscription_status || undefined,
        contact_source: query.contact_source || undefined,
        blocked_status: query.blocked_status || undefined,
        country: query.country || undefined,
        country_dial_code: query.country_dial_code || undefined,
        no_group: hasNoGroupFilter.value ? 1 : undefined,
        no_label: hasNoLabelFilter.value ? 1 : undefined,
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

const currentFilterPayload = () => ({
    search: query.search || undefined,
    group_ids: selectedGroupIdsForFilter.value.length ? selectedGroupIdsForFilter.value : undefined,
    labels: selectedLabelIdsForFilter.value.length ? selectedLabelIdsForFilter.value : undefined,
    subscription_status: query.subscription_status || undefined,
    contact_source: query.contact_source || undefined,
    blocked_status: query.blocked_status || undefined,
    country: query.country || undefined,
    country_dial_code: query.country_dial_code || undefined,
    no_group: hasNoGroupFilter.value ? 1 : undefined,
    no_label: hasNoLabelFilter.value ? 1 : undefined,
    created_from: query.created_from || undefined,
    created_to: query.created_to || undefined,
    custom_attribute_key: query.custom_attribute_key || undefined,
    custom_attribute_value: query.custom_attribute_value || undefined,
});

const toggleSelectAll = () => {
    if (allFilteredSelected.value) {
        if (allOnPageSelected.value) {
            deselectedIds.value = Array.from(new Set([...deselectedIds.value, ...idsOnPage.value]));
            return;
        }

        deselectedIds.value = deselectedIds.value.filter((id) => !idsOnPage.value.includes(id));
        return;
    }

    if (allOnPageSelected.value) {
        selectedIds.value = selectedIds.value.filter((id) => !idsOnPage.value.includes(id));
        return;
    }

    selectedIds.value = Array.from(new Set([...selectedIds.value, ...idsOnPage.value]));
};

const selectAllFilteredRows = () => {
    allFilteredSelected.value = true;
    selectedIds.value = [];
    deselectedIds.value = [];
};

const clearSelection = () => {
    selectedIds.value = [];
    deselectedIds.value = [];
    allFilteredSelected.value = false;
};

const clearSelectionAndFilters = () => {
    clearSelection();
    clearFilters();
};

const applyQuickCountryDialCodeFilter = () => {
    query.page = 1;
    runFilterRequest({ page: undefined });
};

watch([allOnPageSelected, someOnPageSelected], () => {
    if (!selectAllCheckboxRef.value) {
        return;
    }

    selectAllCheckboxRef.value.indeterminate = someOnPageSelected.value;
});

const toggleRow = (id) => {
    if (allFilteredSelected.value) {
        if (deselectedIds.value.includes(id)) {
            deselectedIds.value = deselectedIds.value.filter((selectedId) => selectedId !== id);
            return;
        }

        deselectedIds.value.push(id);
        return;
    }

    if (selectedIds.value.includes(id)) {
        selectedIds.value = selectedIds.value.filter((selectedId) => selectedId !== id);
        return;
    }

    selectedIds.value.push(id);
};

const clearFilters = () => {
    Object.assign(query, {
        search: '',
        group_ids: [],
        label_ids: [],
        subscription_status: '',
        contact_source: '',
        blocked_status: '',
        country: '',
        country_dial_code: '',
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

const clearGroupFilterSelection = () => {
    query.group_ids = [];
};

const clearGroupDeletionSelection = () => {
    selectedGroupUuidsForDeletion.value = [];
};

const selectAllGroupsForDeletion = () => {
    selectedGroupUuidsForDeletion.value = groups.value
        .map((group) => String(group.uuid || ''))
        .filter(Boolean);
};

const toggleGroupFilterSelection = (groupId) => {
    const value = String(groupId);
    if (query.group_ids.includes(value)) {
        query.group_ids = query.group_ids.filter((id) => id !== value);
        return;
    }

    query.group_ids = [...query.group_ids, value];
};

const toggleGroupDeletionSelection = (groupUuid) => {
    const value = String(groupUuid || '');
    if (!value) {
        return;
    }

    if (selectedGroupUuidsForDeletion.value.includes(value)) {
        selectedGroupUuidsForDeletion.value = selectedGroupUuidsForDeletion.value.filter((uuid) => uuid !== value);
        return;
    }

    selectedGroupUuidsForDeletion.value = [...selectedGroupUuidsForDeletion.value, value];
};

const applyGroupFilters = () => {
    query.page = 1;
    runFilterRequest({ page: undefined });
    isGroupsModalOpen.value = false;
};

const createGroup = async () => {
    createGroupError.value = '';
    const name = (createGroupForm.name || '').trim();

    if (!name) {
        createGroupError.value = 'Group name is required.';
        return;
    }

    isCreatingGroup.value = true;

    try {
        const response = await axios.post('/contact-groups', { name }, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const payload = response?.data ?? {};
        const newGroup = payload?.data?.data
            || payload?.data
            || payload?.group
            || payload?.contact_group
            || null;
        const newGroupId = newGroup?.id ?? newGroup?.attributes?.id ?? null;
        const newGroupUuid = newGroup?.uuid ?? newGroup?.attributes?.uuid ?? null;
        const newGroupName = newGroup?.name ?? newGroup?.attributes?.name ?? name;

        createGroupForm.name = '';

        if (newGroupId) {
            if (!groups.value.some((group) => Number(group.id) === Number(newGroupId))) {
                groups.value.unshift({
                    id: newGroupId,
                    uuid: newGroupUuid,
                    name: newGroupName,
                });
            }

            toggleGroupFilterSelection(newGroupId);
            applyGroupFilters();
        } else if (payload?.success === true || response?.status === 200 || response?.status === 201) {
            // Fallback: creation succeeded but API returned an unexpected shape.
            query.page = 1;
            runFilterRequest({ page: undefined });
            isGroupsModalOpen.value = false;
        } else {
            throw new Error('Failed to create group.');
        }
    } catch (error) {
        const errors = error?.response?.data?.errors;
        const firstError = errors && typeof errors === 'object'
            ? Object.values(errors).flat()[0]
            : null;
        const rawResponse = error?.response?.data;
        const statusCode = error?.response?.status;

        createGroupError.value = firstError
            || error?.response?.data?.message
            || error?.message
            || (statusCode ? `Request failed (${statusCode}).` : null)
            || (typeof rawResponse === 'string' && rawResponse.trim() ? 'Unexpected server response.' : null)
            || 'Failed to create group.';
    } finally {
        isCreatingGroup.value = false;
    }
};

const deleteGroup = async (group) => {
    if (!group?.uuid) {
        return;
    }

    if (!confirm(`Delete group "${group.name}"?`)) {
        return;
    }

    createGroupError.value = '';
    deletingGroupUuid.value = String(group.uuid);

    try {
        await axios.delete(`/contact-groups/${group.uuid}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        groups.value = groups.value.filter((item) => String(item.uuid) !== String(group.uuid));
        bulkForm.group_ids = bulkForm.group_ids.filter((id) => Number(id) !== Number(group.id));
        selectedGroupUuidsForDeletion.value = selectedGroupUuidsForDeletion.value.filter((uuid) => uuid !== String(group.uuid));

        if (query.group_ids.includes(String(group.id))) {
            query.group_ids = query.group_ids.filter((id) => id !== String(group.id));
            query.page = 1;
            runFilterRequest({ page: undefined });
        }
    } catch (error) {
        createGroupError.value = error?.response?.data?.message || 'Failed to delete group.';
    } finally {
        deletingGroupUuid.value = '';
    }
};

const deleteSelectedGroups = async () => {
    const uuids = selectedGroupUuidsForDeletion.value.filter(Boolean);
    if (!uuids.length) {
        return;
    }

    if (!confirm(`Delete ${uuids.length} selected groups?`)) {
        return;
    }

    createGroupError.value = '';
    isDeletingGroups.value = true;

    try {
        await axios.delete('/contact-groups', {
            data: { uuids },
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const deletedUuidSet = new Set(uuids.map((uuid) => String(uuid)));
        const deletedIdSet = new Set(
            groups.value
                .filter((group) => deletedUuidSet.has(String(group.uuid)))
                .map((group) => String(group.id))
        );

        groups.value = groups.value.filter((group) => !deletedUuidSet.has(String(group.uuid)));
        bulkForm.group_ids = bulkForm.group_ids.filter((id) => !deletedIdSet.has(String(id)));
        selectedGroupUuidsForDeletion.value = [];

        const previousGroupFilterCount = query.group_ids.length;
        query.group_ids = query.group_ids.filter((id) => !deletedIdSet.has(String(id)));
        if (query.group_ids.length !== previousGroupFilterCount) {
            query.page = 1;
            runFilterRequest({ page: undefined });
        }
    } catch (error) {
        createGroupError.value = error?.response?.data?.message || 'Failed to delete selected groups.';
    } finally {
        isDeletingGroups.value = false;
    }
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

const metadataCache = new WeakMap();

const getContactMetadata = (contact) => {
    if (!contact || typeof contact !== 'object') {
        return {};
    }

    if (metadataCache.has(contact)) {
        return metadataCache.get(contact);
    }

    const parsed = parseMetadata(contact);
    metadataCache.set(contact, parsed);
    return parsed;
};

const formatCustomAttributeValue = (value) => {
    if (value === null || typeof value === 'undefined' || value === '') {
        return '-';
    }

    if (Array.isArray(value)) {
        const items = value.map((item) => String(item).trim()).filter((item) => item !== '');
        return items.length ? items.join(', ') : '-';
    }

    if (typeof value === 'object') {
        try {
            return JSON.stringify(value);
        } catch (error) {
            return '-';
        }
    }

    const normalized = String(value).trim();
    return normalized !== '' ? normalized : '-';
};

const getCustomAttributeDisplay = (contact, fieldName) => {
    const metadata = getContactMetadata(contact);
    return formatCustomAttributeValue(metadata?.[fieldName]);
};

const runBulkDelete = () => {
    if (selectedCount.value === 0 || !confirm('Delete selected contacts?')) {
        return;
    }

    const payload = {
        ...currentFilterPayload(),
        select_all_filtered: allFilteredSelected.value ? 1 : 0,
        except_ids: allFilteredSelected.value ? deselectedIds.value : [],
    };
    if (!allFilteredSelected.value) {
        payload.ids = selectedIds.value;
    }

    router.post('/contacts/bulk-delete', payload, {
        preserveScroll: true,
        onSuccess: () => {
            clearSelection();
            localStorage.removeItem(selectionStorageKey.value);
        },
    });
};

const runBulkAction = () => {
    if (selectedCount.value === 0 || !bulkActionModal.value) {
        return;
    }

    const payload = {
        ...currentFilterPayload(),
        action: bulkActionModal.value,
        select_all_filtered: allFilteredSelected.value ? 1 : 0,
        except_ids: allFilteredSelected.value ? deselectedIds.value : [],
    };
    if (!allFilteredSelected.value) {
        payload.ids = selectedIds.value;
    }

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
            clearSelection();
            localStorage.removeItem(selectionStorageKey.value);
        },
    });
};

const runExport = () => {
    const params = new URLSearchParams();

    if (allFilteredSelected.value) {
        params.append('select_all_filtered', '1');
        deselectedIds.value.forEach((id) => params.append('except_ids[]', id));
        Object.entries(currentFilterPayload()).forEach(([key, value]) => {
            if (value !== undefined && value !== null && value !== '') {
                params.append(key, String(value));
            }
        });
    } else {
        selectedIds.value.forEach((id) => params.append('ids[]', id));
    }

    window.open(`/contacts/export?${params.toString()}`, '_blank');
};

const runExportAll = () => {
    window.open('/contacts/export?scope=all', '_blank');
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

const onImportCompleted = () => {
    runFilterRequest();
};
</script>

<template>
    <div class="space-y-5">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-slate-900">{{ $t('Contact Management') }}</h1>
                    <p class="mt-1 text-sm text-slate-500">{{ $t('Manage contacts, filters, groups, and bulk actions from one place.') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Link href="/contacts/add" class="inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-2 text-sm font-medium text-white">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd" /></svg>
                        {{ $t('Add Contact') }}
                    </Link>
                    <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700" @click="isImportWizardOpen = true">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 13a1 1 0 011 1v2h12v-2a1 1 0 112 0v3a1 1 0 01-1 1H3a1 1 0 01-1-1v-3a1 1 0 011-1zm7-11a1 1 0 011 1v7.586l2.293-2.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L9 10.586V3a1 1 0 011-1z" clip-rule="evenodd" /></svg>
                        {{ $t('Import') }}
                    </button>
                    <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700" @click="runExportAll">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 3a1 1 0 011-1h12a1 1 0 011 1v8a1 1 0 11-2 0V4H5v7a1 1 0 11-2 0V3zm7 3a1 1 0 011 1v5.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 011.414-1.414L9 12.586V7a1 1 0 011-1zm-6 11a1 1 0 100-2h12a1 1 0 100 2H4z" clip-rule="evenodd" /></svg>
                        {{ $t('Export All') }}
                    </button>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <p class="text-xs font-medium text-slate-500">{{ $t('Total Contacts') }}</p>
                    <p class="mt-1 text-lg font-semibold text-slate-900">{{ rowCount }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <p class="text-xs font-medium text-slate-500">{{ $t('Filtered Contacts') }}</p>
                    <p class="mt-1 text-lg font-semibold text-slate-900">{{ filteredCount }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <p class="text-xs font-medium text-slate-500">{{ $t('Active Filters') }}</p>
                    <p class="mt-1 text-lg font-semibold text-slate-900">{{ activeFilterCount }}</p>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex w-full flex-col gap-2 lg:max-w-4xl lg:flex-row">
                    <input v-model="query.search" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none ring-primary/20 focus:border-primary focus:ring" :placeholder="$t('Search name, phone, country, or email')" @input="debouncedSearch">
                    <select v-model="query.country_dial_code" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 outline-none ring-primary/20 focus:border-primary focus:ring lg:max-w-xs" @change="applyQuickCountryDialCodeFilter">
                        <option value="">{{ $t('All Countries') }}</option>
                        <option v-for="option in countryDialCodeOptions" :key="`quick-${option.region}-${option.value}`" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700" @click="isGroupsModalOpen = true">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M7 4a3 3 0 11-6 0 3 3 0 016 0zM19 4a3 3 0 11-6 0 3 3 0 016 0zM7 14a3 3 0 11-6 0 3 3 0 016 0zM19 14a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                        {{ $t('Groups') }}
                    </button>
                    <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700" @click="isFilterModalOpen = true">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 010 2h-1.586l-3.707 3.707A1 1 0 0010 9.414V14a1 1 0 01-.553.894l-2 1A1 1 0 016 15v-5.586a1 1 0 00-.293-.707L2 5H4a1 1 0 01-1-1z" clip-rule="evenodd" /></svg>
                        {{ $t('Advanced Filters') }}
                    </button>
                    <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700" @click="selectAllFilteredRows">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-7.5 7.5a1 1 0 01-1.414 0l-3.5-3.5a1 1 0 111.414-1.414l2.793 2.793 6.793-6.793a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                        {{ $t('Select All Results') }}
                    </button>
                    <div ref="columnsMenuRef" class="relative">
                        <button type="button" class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700" @click.stop="toggleColumnsMenu">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 4a1 1 0 011-1h4a1 1 0 011 1v12a1 1 0 01-1 1H4a1 1 0 01-1-1V4zm8 0a1 1 0 011-1h4a1 1 0 011 1v5a1 1 0 01-1 1h-4a1 1 0 01-1-1V4zm1 8a1 1 0 00-1 1v3a1 1 0 001 1h4a1 1 0 001-1v-3a1 1 0 00-1-1h-4z" /></svg>
                            {{ $t('Columns') }}
                        </button>
                        <div v-if="isColumnsMenuOpen" class="absolute right-0 z-20 mt-2 w-60 rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                            <label class="mb-2 flex items-center gap-2 text-sm text-slate-700"><input v-model="columns.name" type="checkbox"> {{ $t('Name') }}</label>
                            <label class="mb-2 flex items-center gap-2 text-sm text-slate-700"><input v-model="columns.phone" type="checkbox"> {{ $t('Phone') }}</label>
                            <label class="mb-2 flex items-center gap-2 text-sm text-slate-700"><input v-model="columns.email" type="checkbox"> {{ $t('Email') }}</label>
                            <label class="mb-2 flex items-center gap-2 text-sm text-slate-700"><input v-model="columns.groups" type="checkbox"> {{ $t('Groups') }}</label>
                            <label class="mb-2 flex items-center gap-2 text-sm text-slate-700"><input v-model="columns.labels" type="checkbox"> {{ $t('Labels') }}</label>
                            <label class="mb-2 flex items-center gap-2 text-sm text-slate-700"><input v-model="columns.subscription" type="checkbox"> {{ $t('Subscription') }}</label>
                            <label class="mb-2 flex items-center gap-2 text-sm text-slate-700"><input v-model="columns.contact_source" type="checkbox"> {{ $t('Contact Source') }}</label>
                            <label class="mb-2 flex items-center gap-2 text-sm text-slate-700"><input v-model="columns.country" type="checkbox"> {{ $t('Country') }}</label>
                            <div v-if="fields.length" class="mb-2 rounded-md border border-slate-200 p-2">
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $t('Custom Attributes') }}</p>
                                <label
                                    v-for="field in fields"
                                    :key="`col-custom-${field.uuid ?? field.name}`"
                                    class="mb-1 flex items-center gap-2 text-sm text-slate-700 last:mb-0"
                                >
                                    <input v-model="columns[customColumnKey(field)]" type="checkbox">
                                    {{ field.name }}
                                </label>
                            </div>
                            <label class="flex items-center gap-2 text-sm text-slate-700"><input v-model="columns.created_at" type="checkbox"> {{ $t('Created At') }}</label>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="activeFilterCount > 0" class="mt-3 flex flex-wrap items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                <span class="text-xs font-medium text-amber-700">{{ activeFilterCount }} {{ $t('filters active') }}</span>
                <button type="button" class="rounded-md bg-white px-2.5 py-1 text-xs text-slate-700 ring-1 ring-slate-200" @click="clearFilters">{{ $t('Reset Filters') }}</button>
            </div>
        </div>

        <div v-if="selectedCount > 0" class="sticky top-16 z-20 flex flex-wrap items-center gap-2 rounded-xl border border-blue-200 bg-blue-50 p-3 shadow-sm">
            <span class="text-sm font-medium text-blue-700">{{ selectedCount }} {{ $t('selected') }}</span>
            <span v-if="allFilteredSelected" class="text-xs text-blue-700">{{ $t('All filtered results selected') }}</span>
            <button type="button" class="rounded-md bg-white px-3 py-1.5 text-sm text-slate-700 ring-1 ring-slate-200" @click="clearSelectionAndFilters">{{ $t('Clear Selection') }}</button>
            <button v-if="role !== 'agent'" type="button" class="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white" @click="runBulkDelete">{{ $t('Delete') }}</button>
            <button type="button" class="rounded-md bg-white px-3 py-1.5 text-sm text-slate-700 ring-1 ring-slate-200" @click="bulkActionModal = 'add_group'">{{ $t('Add Group') }}</button>
            <button type="button" class="rounded-md bg-white px-3 py-1.5 text-sm text-slate-700 ring-1 ring-slate-200" @click="bulkActionModal = 'remove_group'">{{ $t('Remove Group') }}</button>
            <button type="button" class="rounded-md bg-white px-3 py-1.5 text-sm text-slate-700 ring-1 ring-slate-200" @click="bulkActionModal = 'add_label'">{{ $t('Add Labels') }}</button>
            <button type="button" class="rounded-md bg-white px-3 py-1.5 text-sm text-slate-700 ring-1 ring-slate-200" @click="bulkActionModal = 'remove_label'">{{ $t('Remove Labels') }}</button>
            <button type="button" class="rounded-md bg-white px-3 py-1.5 text-sm text-slate-700 ring-1 ring-slate-200" @click="bulkActionModal = 'change_subscription_status'">{{ $t('Change Subscription') }}</button>
            <button type="button" class="inline-flex items-center gap-2 rounded-md bg-white px-3 py-1.5 text-sm text-slate-700 ring-1 ring-slate-200" @click="runExport">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 3a1 1 0 011-1h12a1 1 0 011 1v8a1 1 0 11-2 0V4H5v7a1 1 0 11-2 0V3zm7 3a1 1 0 011 1v5.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 011.414-1.414L9 12.586V7a1 1 0 011-1zm-6 11a1 1 0 100-2h12a1 1 0 100 2H4z" clip-rule="evenodd" /></svg>
                {{ $t('Export Selected') }}
            </button>
        </div>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50/70 px-4 py-3">
                <h2 class="text-sm font-semibold text-slate-800">{{ $t('Contacts List') }}</h2>
                <p class="text-xs text-slate-500">{{ rows.meta?.from || 0 }}-{{ rows.meta?.to || 0 }} / {{ rows.meta?.total || 0 }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-4 py-3">
                                <input ref="selectAllCheckboxRef" type="checkbox" :checked="allOnPageSelected" :aria-label="$t('Select all rows')" @change="toggleSelectAll">
                            </th>
                            <th v-if="columns.name" class="px-4 py-3">{{ $t('Name') }}</th>
                            <th v-if="columns.phone" class="px-4 py-3">{{ $t('Phone') }}</th>
                            <th v-if="columns.email" class="px-4 py-3">{{ $t('Email') }}</th>
                            <th v-if="columns.groups" class="px-4 py-3">{{ $t('Groups') }}</th>
                            <th v-if="columns.labels" class="px-4 py-3">{{ $t('Labels') }}</th>
                            <th v-if="columns.subscription" class="px-4 py-3">{{ $t('Subscription') }}</th>
                            <th v-if="columns.contact_source" class="px-4 py-3">{{ $t('Contact Source') }}</th>
                            <th v-if="columns.country" class="px-4 py-3">{{ $t('Country') }}</th>
                            <th
                                v-for="field in fields"
                                v-show="columns[customColumnKey(field)]"
                                :key="`head-custom-${field.uuid ?? field.name}`"
                                class="px-4 py-3"
                            >
                                {{ field.name }}
                            </th>
                            <th v-if="columns.created_at" class="px-4 py-3">{{ $t('Created At') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <tr v-for="contact in rows.data" :key="contact.id" class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <input type="checkbox" :checked="isRowSelected(contact.id)" @change="toggleRow(contact.id)">
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
                            <td v-if="columns.contact_source" class="px-4 py-3">
                                <span class="rounded-full px-2 py-1 text-xs bg-slate-100 text-slate-700">
                                    {{ $t(String(contact.contact_source || 'platform').toLowerCase()) }}
                                </span>
                            </td>
                            <td v-if="columns.country" class="px-4 py-3">{{ parseAddress(contact).country || '-' }}</td>
                            <td
                                v-for="field in fields"
                                v-show="columns[customColumnKey(field)]"
                                :key="`row-custom-${contact.id}-${field.uuid ?? field.name}`"
                                class="px-4 py-3"
                            >
                                <span class="line-clamp-2">{{ getCustomAttributeDisplay(contact, field.name) }}</span>
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
        <div class="space-y-3 pt-4">
            <div class="rounded-lg border border-slate-200 p-3">
               <div class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $t('Group') }}</label>
                        <select v-model="query.group_ids" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" multiple>
                            <option :value="NO_GROUP_OPTION">{{ $t('No Group') }}</option>
                            <option v-for="group in groups" :key="group.id" :value="String(group.id)">{{ group.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $t('Label') }}</label>
                        <select v-model="query.label_ids" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" multiple>
                            <option :value="NO_LABEL_OPTION">{{ $t('No Label') }}</option>
                            <option v-for="label in labels" :key="label.id" :value="String(label.id)">{{ label.title }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200 p-3">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $t('Status') }}</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $t('Subscription Status') }}</label>
                        <select v-model="query.subscription_status" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                            <option value="">{{ $t('All Statuses') }}</option>
                            <option value="1">{{ $t('Subscribed') }}</option>
                            <option value="0">{{ $t('Unsubscribed') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $t('Contact Source') }}</label>
                        <select v-model="query.contact_source" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                            <option value="">{{ $t('All Sources') }}</option>
                            <option v-for="source in contactSourceOptions" :key="source.value" :value="source.value">{{ $t(source.label) }}</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $t('Block Status') }}</label>
                        <select v-model="query.blocked_status" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                            <option value="">{{ $t('All') }}</option>
                            <option value="1">{{ $t('Blocked') }}</option>
                            <option value="0">{{ $t('Not blocked') }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200 p-3">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $t('Date & Attributes') }}</p>
                <div class="grid gap-3">
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">{{ $t('Created From') }}</label>
                            <input v-model="query.created_from" type="date" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">{{ $t('Created To') }}</label>
                            <input v-model="query.created_to" type="date" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ $t('Custom Attribute') }}</label>
                        <select v-model="query.custom_attribute_key" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                            <option value="">{{ $t('Select Attribute') }}</option>
                            <option v-for="attribute in availableCustomAttributes" :key="attribute.value" :value="attribute.value">{{ attribute.label }}</option>
                        </select>
                    </div>
                    <input v-model="query.custom_attribute_value" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" :placeholder="$t('Contains value')">
                </div>
            </div>

            <div class="mt-2 flex gap-2">
                <button type="button" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" @click="clearFilters">{{ $t('Reset') }}</button>
                <button type="button" class="w-full rounded-md bg-primary px-3 py-2 text-sm text-white" @click="applyFilters">{{ $t('Apply') }}</button>
            </div>
        </div>
    </Modal>

    <Modal :label="$t('Groups')" :isOpen="isGroupsModalOpen" :closeBtn="true" @close="isGroupsModalOpen = false">
        <div class="flex max-h-[75vh] flex-col pt-4">
            <div class="rounded-md border border-slate-200 p-3">
                <label class="mb-1 block text-xs font-medium text-slate-500">{{ $t('New Group Name') }}</label>
                <div class="flex gap-2">
                    <input
                        v-model="createGroupForm.name"
                        type="text"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                        :placeholder="$t('Enter group name')"
                        @keyup.enter="createGroup"
                    >
                    <button
                        type="button"
                        class="whitespace-nowrap rounded-md bg-primary px-3 py-2 text-sm text-white disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="isCreatingGroup"
                        @click="createGroup"
                    >
                        {{ isCreatingGroup ? $t('Saving...') : $t('Add Group') }}
                    </button>
                </div>
                <p v-if="createGroupError" class="mt-2 text-xs text-rose-600">{{ createGroupError }}</p>
            </div>

            <div v-if="groups.length" class="mt-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xs text-slate-600">
                        {{ $t('Selected for deletion') }}: {{ selectedGroupDeletionCount }}
                    </span>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" class="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs text-slate-700 disabled:cursor-not-allowed disabled:opacity-60" :disabled="isDeletingGroups" @click="selectAllGroupsForDeletion">
                            {{ $t('Select All') }}
                        </button>
                        <button type="button" class="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs text-slate-700 disabled:cursor-not-allowed disabled:opacity-60" :disabled="isDeletingGroups || !selectedGroupDeletionCount" @click="clearGroupDeletionSelection">
                            {{ $t('Clear') }}
                        </button>
                        <button type="button" class="rounded-md border border-rose-200 bg-white px-2.5 py-1 text-xs text-rose-600 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60" :disabled="isDeletingGroups || !selectedGroupDeletionCount" @click="deleteSelectedGroups">
                            {{ isDeletingGroups ? $t('Deleting...') : $t('Delete Selected') }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="mt-2 flex-1 space-y-2 overflow-y-auto pr-1">
                <button
                    type="button"
                    class="flex w-full items-center justify-between rounded-md border px-3 py-2 text-sm"
                    :class="query.group_ids.length ? 'border-slate-300 text-slate-700' : 'border-primary bg-primary/5 text-primary'"
                    @click="clearGroupFilterSelection"
                >
                    <span>{{ $t('All Groups') }}</span>
                    <span v-if="!query.group_ids.length" class="text-xs">{{ $t('Selected') }}</span>
                </button>

                <div
                    v-for="group in groups"
                    :key="group.id"
                    class="flex w-full items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm"
                    :class="query.group_ids.includes(String(group.id)) ? 'border-primary bg-primary/5 text-primary' : 'border-slate-300 text-slate-700'"
                >
                    <input
                        type="checkbox"
                        class="h-4 w-4 shrink-0 rounded border-slate-300 text-primary focus:ring-primary"
                        :checked="selectedGroupUuidsForDeletion.includes(String(group.uuid))"
                        :aria-label="`${$t('Select')} ${group.name}`"
                        @change="toggleGroupDeletionSelection(group.uuid)"
                    >
                    <button type="button" class="min-w-0 flex-1 text-left" @click="toggleGroupFilterSelection(group.id)">
                        <span class="truncate">{{ group.name }}</span>
                        <span v-if="query.group_ids.includes(String(group.id))" class="ml-2 text-xs">{{ $t('Selected') }}</span>
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-rose-200 px-2 py-1 text-xs text-rose-600 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="deletingGroupUuid === String(group.uuid)"
                        @click="deleteGroup(group)"
                    >
                        {{ deletingGroupUuid === String(group.uuid) ? $t('Deleting...') : $t('Delete') }}
                    </button>
                </div>
            </div>

            <div class="mt-3 border-t border-slate-200 bg-white pt-3">
                <div class="flex gap-2">
                <button type="button" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" @click="clearGroupFilterSelection">{{ $t('Reset') }}</button>
                <button type="button" class="w-full rounded-md bg-primary px-3 py-2 text-sm text-white" @click="applyGroupFilters">{{ $t('Apply') }}</button>
                </div>
            </div>
        </div>
    </Modal>

    <Modal :label="$t('Bulk Action')" :isOpen="!!bulkActionModal" :closeBtn="true" @close="bulkActionModal = ''">
        <div class="grid gap-3 pt-4">
            <template v-if="bulkActionModal === 'add_group' || bulkActionModal === 'remove_group'">
                <label class="text-xs font-medium text-slate-500">{{ $t('Select Groups') }}</label>
                <select v-model="bulkForm.group_ids" class="rounded-md border border-slate-300 px-3 py-2 text-sm" multiple>
                    <option v-for="group in groups" :key="group.id" :value="group.id">{{ group.name }}</option>
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

    <ContactImportWizardModal
        v-model="isImportWizardOpen"
        :contactGroups="groups"
        :fields="fields"
        :countries="contactConstants.countries"
        @completed="onImportCompleted"
    />
</template>
