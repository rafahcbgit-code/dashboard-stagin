<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { Link, router, usePage } from '@inertiajs/vue3';
import debounce from 'lodash/debounce';
import { useI18n } from 'vue-i18n';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    Download,
    Filter,
    Grid2X2,
    Import,
    List,
    MessageCircle,
    Minus,
    Search,
    Columns3,
    Tags,
    Table2,
    Trash2,
    UserPlus,
    Users,
    X,
} from 'lucide-vue-next';
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
const { t, locale } = useI18n();
const isRtl = computed(() => Boolean(page.props.isRtl) || locale.value === 'ar');
const uiDirection = computed(() => (isRtl.value ? 'rtl' : 'ltr'));
const uiTextAlignmentClass = computed(() => (isRtl.value ? 'text-right' : 'text-left'));
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
const viewMode = ref('grid');
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
const selectedContactsOnPage = computed(() => props.rows.data.filter((contact) => isRowSelected(contact.id)));

const avatarPalette = [
    'bg-emerald-100 text-emerald-700 ring-emerald-200',
    'bg-sky-100 text-sky-700 ring-sky-200',
    'bg-amber-100 text-amber-700 ring-amber-200',
    'bg-rose-100 text-rose-700 ring-rose-200',
    'bg-indigo-100 text-indigo-700 ring-indigo-200',
    'bg-teal-100 text-teal-700 ring-teal-200',
    'bg-fuchsia-100 text-fuchsia-700 ring-fuchsia-200',
    'bg-lime-100 text-lime-700 ring-lime-200',
];

const contactName = (contact) => {
    const name = contact.full_name || [contact.first_name, contact.last_name].filter(Boolean).join(' ');
    return String(name || contact.name || contact.formatted_phone_number || contact.phone || 'Contact').trim();
};

const contactInitial = (contact) => {
    const firstCharacter = Array.from(contactName(contact)).find((character) => character.trim() !== '');
    return firstCharacter ? firstCharacter.toLocaleUpperCase() : '#';
};

const avatarClass = (contact) => {
    const initial = contactInitial(contact);
    const code = initial.codePointAt(0) || 0;
    return avatarPalette[code % avatarPalette.length];
};

const contactPhone = (contact) => contact.formatted_phone_number || contact.phone || '-';

const contactGroupNames = (contact) => (Array.isArray(contact.contact_groups) ? contact.contact_groups : [])
    .map((group) => group?.name)
    .filter(Boolean);

const visibleGroupNames = (contact) => contactGroupNames(contact).slice(0, 3);
const remainingGroupCount = (contact) => Math.max(contactGroupNames(contact).length - 3, 0);

const contactLabels = (contact) => (Array.isArray(contact.labels) ? contact.labels : [])
    .map((label) => label?.title || label?.name)
    .filter(Boolean);

const visibleLabels = (contact) => contactLabels(contact).slice(0, 3);
const remainingLabelCount = (contact) => Math.max(contactLabels(contact).length - 3, 0);

const contactCustomFields = (contact) => (props.fields || [])
    .map((field) => ({
        key: field.uuid ?? field.name,
        name: field.name,
        value: getCustomAttributeDisplay(contact, field.name),
    }))
    .filter((field) => field.value !== '-');

const visibleCustomFields = (contact) => contactCustomFields(contact).slice(0, 3);
const remainingCustomFieldCount = (contact) => Math.max(contactCustomFields(contact).length - 3, 0);

const formatDate = (value) => {
    if (!value) {
        return '-';
    }

    try {
        return new Intl.DateTimeFormat(undefined, {
            year: 'numeric',
            month: 'short',
            day: '2-digit',
        }).format(new Date(value));
    } catch (error) {
        return String(value);
    }
};

const tableColumnDefinitions = computed(() => [
    { key: 'name', label: 'Name' },
    { key: 'phone', label: 'Phone' },
    { key: 'email', label: 'Email' },
    { key: 'groups', label: 'Groups' },
    { key: 'labels', label: 'Labels' },
    { key: 'subscription', label: 'Subscription' },
    { key: 'contact_source', label: 'Source' },
    { key: 'country', label: 'Country' },
    { key: 'created_at', label: 'Created At' },
    ...(props.fields || []).map((field) => ({
        key: customColumnKey(field),
        label: field.name,
        fieldName: field.name,
        isCustom: true,
    })),
]);

const visibleTableColumns = computed(() => tableColumnDefinitions.value.filter((column) => columns.value[column.key] !== false));

const resetTableColumns = () => {
    columns.value = buildDefaultColumns();
};

const quickWhatsappUrl = (contact) => {
    const rawPhone = String(contact.phone || contact.formatted_phone_number || '').replace(/[^\d]/g, '');
    return rawPhone ? `https://wa.me/${rawPhone}` : '#';
};

const openQuickWhatsapp = (contact) => {
    const url = quickWhatsappUrl(contact);
    if (url === '#') {
        return;
    }

    window.open(url, '_blank', 'noopener,noreferrer');
};

const messageSelectedContacts = () => {
    if (selectedContactsOnPage.value.length === 1 && selectedCount.value === 1) {
        openQuickWhatsapp(selectedContactsOnPage.value[0]);
        return;
    }

    alert(t('Select one visible contact to open WhatsApp.'));
};

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

const groupContactsCount = (group) => Number(group?.contacts_count ?? group?.contact_count ?? 0);

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
        createGroupError.value = t('Audince name is required.');
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
                    contacts_count: 0,
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
            throw new Error(t('Failed to create audince.'));
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
            || t('Failed to create audince.');
    } finally {
        isCreatingGroup.value = false;
    }
};

const deleteGroup = async (group) => {
    if (!group?.uuid) {
        return;
    }

    if (!confirm(t('Delete group :name?', { name: group.name }))) {
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
        createGroupError.value = error?.response?.data?.message || t('Failed to delete audince.');
    } finally {
        deletingGroupUuid.value = '';
    }
};

const deleteSelectedGroups = async () => {
    const uuids = selectedGroupUuidsForDeletion.value.filter(Boolean);
    if (!uuids.length) {
        return;
    }

    if (!confirm(t('Delete :count selected groups?', { count: uuids.length }))) {
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
        createGroupError.value = error?.response?.data?.message || t('Failed to delete selected audince.');
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
    if (selectedCount.value === 0 || !confirm(t('Delete selected contacts?'))) {
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
    <div :dir="uiDirection" class="-m-4 min-h-screen bg-[#F8FAFC] px-4 py-5 text-slate-950 md:-m-6 md:px-6 md:py-6" :class="uiTextAlignmentClass">
        <div class="mx-auto max-w-[1800px] space-y-5 pb-24">
            <section class="rounded-[2rem] border border-white bg-white/90 p-5 shadow-sm shadow-slate-200/70">
                <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                    <div class="max-w-2xl">
                        <div class="mb-3 inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                            {{ $t('WhatsApp CRM') }}
                        </div>
                        <h1 class="text-3xl font-extrabold tracking-tight text-slate-950 md:text-4xl">{{ $t('Contact Management') }}</h1>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ $t('Manage contacts, filters, groups, and bulk actions from one place.') }}</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md" @click="isImportWizardOpen = true">
                            <Import class="h-4 w-4" />
                            {{ $t('Import') }}
                        </button>
                        <button type="button" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md" @click="runExportAll">
                            <Download class="h-4 w-4" />
                            {{ $t('Export All') }}
                        </button>
                        <Link href="/contacts/add" class="inline-flex items-center gap-2 rounded-full bg-slate-950 px-4 py-2.5 text-sm font-bold text-white shadow-lg shadow-slate-300 transition-all duration-300 hover:-translate-y-0.5 hover:bg-slate-800">
                            <UserPlus class="h-4 w-4" />
                            {{ $t('Add Contact') }}
                        </Link>
                    </div>
                </div>

                <div class="mt-5 grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div class="rounded-[1.5rem] border border-slate-100 bg-[#F8FAFC] p-4">
                        <p class="text-xs font-bold text-slate-500">{{ $t('Total Contacts') }}</p>
                        <p class="mt-2 text-2xl font-extrabold tracking-tight text-slate-950">{{ rowCount }}</p>
                    </div>
                    <div class="rounded-[1.5rem] border border-slate-100 bg-[#F8FAFC] p-4">
                        <p class="text-xs font-bold text-slate-500">{{ $t('Filtered Contacts') }}</p>
                        <p class="mt-2 text-2xl font-extrabold tracking-tight text-slate-950">{{ filteredCount }}</p>
                    </div>
                    <div class="rounded-[1.5rem] border border-slate-100 bg-[#F8FAFC] p-4">
                        <p class="text-xs font-bold text-slate-500">{{ $t('Active Filters') }}</p>
                        <p class="mt-2 text-2xl font-extrabold tracking-tight text-slate-950">{{ activeFilterCount }}</p>
                    </div>
                </div>
            </section>

            <section class="rounded-[2rem] border border-white bg-white/95 p-4 shadow-sm shadow-slate-200/70">
                <div class="grid grid-cols-1 gap-3 xl:grid-cols-[minmax(0,1fr)_auto] xl:items-center">
                    <div class="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,1fr)_18rem]">
                        <label class="relative block">
                            <Search class="pointer-events-none absolute top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" :class="isRtl ? 'right-4' : 'left-4'" />
                            <input v-model="query.search" type="text" class="h-12 w-full rounded-full border border-slate-200 bg-[#F8FAFC] text-sm font-medium outline-none ring-primary/20 transition-all duration-300 placeholder:text-slate-400 focus:border-primary focus:bg-white focus:ring" :class="isRtl ? 'pr-11 pl-4' : 'pl-11 pr-4'" :placeholder="$t('Search name, phone, country, or email')" @input="debouncedSearch">
                        </label>
                        <select v-model="query.country_dial_code" class="h-12 w-full rounded-full border border-slate-200 bg-[#F8FAFC] px-4 text-sm font-medium text-slate-700 outline-none ring-primary/20 transition-all duration-300 focus:border-primary focus:bg-white focus:ring" @change="applyQuickCountryDialCodeFilter">
                            <option value="">{{ $t('All Countries') }}</option>
                            <option v-for="option in countryDialCodeOptions" :key="`quick-${option.region}-${option.value}`" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <div class="inline-flex rounded-full bg-slate-100 p-1">
                            <button type="button" class="inline-flex items-center gap-2 rounded-full px-3 py-2 text-sm font-bold transition-all duration-300" :class="viewMode === 'grid' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-800'" @click="viewMode = 'grid'">
                                <Grid2X2 class="h-4 w-4" />
                                {{ $t('Grid') }}
                            </button>
                            <button type="button" class="inline-flex items-center gap-2 rounded-full px-3 py-2 text-sm font-bold transition-all duration-300" :class="viewMode === 'list' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-800'" @click="viewMode = 'list'">
                                <List class="h-4 w-4" />
                                {{ $t('List') }}
                            </button>
                            <button type="button" class="inline-flex items-center gap-2 rounded-full px-3 py-2 text-sm font-bold transition-all duration-300" :class="viewMode === 'table' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-800'" @click="viewMode = 'table'">
                                <Table2 class="h-4 w-4" />
                                {{ $t('Table') }}
                            </button>
                        </div>
                        <div v-if="viewMode === 'table'" ref="columnsMenuRef" class="relative">
                            <button type="button" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition-all duration-300 hover:border-indigo-200 hover:text-indigo-700" @click.stop="toggleColumnsMenu">
                                <Columns3 class="h-4 w-4" />
                                {{ $t('Columns') }}
                            </button>
                            <Transition
                                enter-active-class="transition duration-200 ease-out"
                                enter-from-class="-translate-y-2 opacity-0"
                                enter-to-class="translate-y-0 opacity-100"
                                leave-active-class="transition duration-150 ease-in"
                                leave-from-class="translate-y-0 opacity-100"
                                leave-to-class="-translate-y-2 opacity-0"
                            >
                                <div v-if="isColumnsMenuOpen" class="absolute z-30 mt-2 w-72 rounded-[1.5rem] border border-slate-100 bg-white p-3 shadow-2xl shadow-slate-200" :class="isRtl ? 'left-0' : 'right-0'">
                                    <div class="mb-2 flex items-center justify-between gap-3 border-b border-slate-100 pb-2">
                                        <p class="text-sm font-extrabold tracking-tight text-slate-950">{{ $t('Table Columns') }}</p>
                                        <button type="button" class="rounded-full px-2 py-1 text-xs font-bold text-slate-500 transition-all duration-300 hover:bg-slate-100 hover:text-slate-900" @click="resetTableColumns">
                                            {{ $t('Reset') }}
                                        </button>
                                    </div>
                                    <div class="max-h-80 space-y-1 overflow-y-auto pr-1">
                                        <label v-for="column in tableColumnDefinitions" :key="`column-${column.key}`" class="flex cursor-pointer items-center justify-between gap-3 rounded-2xl px-3 py-2 text-sm font-bold text-slate-700 transition-all duration-300 hover:bg-[#F8FAFC]">
                                            <span class="truncate">{{ $t(column.label) }}</span>
                                            <input v-model="columns[column.key]" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                        </label>
                                    </div>
                                </div>
                            </Transition>
                        </div>
                        <button type="button" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition-all duration-300 hover:border-emerald-200 hover:text-emerald-700" @click="isGroupsModalOpen = true">
                            <Users class="h-4 w-4" />
                            {{ $t('Groups') }}
                        </button>
                        <button type="button" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition-all duration-300 hover:border-sky-200 hover:text-sky-700" @click="isFilterModalOpen = true">
                            <Filter class="h-4 w-4" />
                            {{ $t('Advanced Filters') }}
                            <span v-if="activeFilterCount > 0" class="rounded-full bg-sky-100 px-2 py-0.5 text-xs text-sky-700">{{ activeFilterCount }}</span>
                        </button>
                        <button type="button" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition-all duration-300 hover:border-blue-200 hover:text-blue-700" @click="selectAllFilteredRows">
                            <Check class="h-4 w-4" />
                            {{ $t('Select All Results') }}
                        </button>
                    </div>
                </div>

                <div v-if="activeFilterCount > 0" class="mt-3 flex flex-wrap items-center gap-2 rounded-full border border-amber-100 bg-amber-50 px-4 py-2">
                    <span class="text-xs font-bold text-amber-700">{{ activeFilterCount }} {{ $t('filters active') }}</span>
                    <button type="button" class="rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-700 ring-1 ring-amber-100 transition-all duration-300 hover:text-amber-700" @click="clearFilters">{{ $t('Reset Filters') }}</button>
                </div>
            </section>

            <section class="space-y-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-2xl font-extrabold tracking-tight text-slate-950">{{ $t('Contacts') }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ rows.meta?.from || 0 }}-{{ rows.meta?.to || 0 }} / {{ rows.meta?.total || 0 }}</p>
                    </div>
                    <button type="button" class="inline-flex items-center justify-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 transition-all duration-300 hover:border-blue-200 hover:text-blue-700" @click="toggleSelectAll">
                        <Check class="h-4 w-4" />
                        {{ allOnPageSelected ? $t('Unselect Page') : $t('Select Page') }}
                    </button>
                </div>

                <div v-if="rows.data.length === 0" class="rounded-[2rem] border border-dashed border-slate-200 bg-white p-10 text-center">
                    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                        <Users class="h-7 w-7" />
                    </div>
                    <p class="mt-4 text-lg font-extrabold tracking-tight text-slate-950">{{ $t('No contacts found') }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ $t('Try changing filters or importing contacts.') }}</p>
                </div>

                <Transition name="contact-view" mode="out-in">
                    <div v-if="viewMode === 'grid'" key="grid" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                        <article
                            v-for="contact in rows.data"
                            :key="`card-${contact.id}`"
                            class="group relative cursor-pointer rounded-[2rem] border-2 bg-white p-5 shadow-sm shadow-slate-200/70 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-slate-200"
                            :class="isRowSelected(contact.id) ? 'border-blue-500 ring-4 ring-blue-100' : 'border-transparent hover:border-slate-100'"
                            @click="toggleRow(contact.id)"
                        >
                            <div
                                class="absolute left-5 top-5 flex h-9 w-9 items-center justify-center rounded-full border-2 bg-white shadow-sm transition-all duration-300"
                                :class="isRowSelected(contact.id) ? 'border-blue-600 bg-blue-600 text-white shadow-lg shadow-blue-200' : 'border-slate-200 text-transparent group-hover:border-blue-300 group-hover:bg-blue-50'"
                                :aria-label="isRowSelected(contact.id) ? $t('Selected') : $t('Select contact')"
                            >
                                <Check class="h-4 w-4" />
                            </div>

                            <div class="flex items-start gap-4">
                                <div class="relative shrink-0">
                                    <div class="flex h-16 w-16 items-center justify-center rounded-3xl text-2xl font-extrabold ring-4" :class="avatarClass(contact)">
                                        {{ contactInitial(contact) }}
                                    </div>
                                    <span class="absolute -bottom-1 -left-1 flex h-5 w-5 items-center justify-center rounded-full bg-white">
                                        <span class="absolute h-3 w-3 animate-ping rounded-full bg-emerald-400 opacity-70"></span>
                                        <span class="relative h-3 w-3 rounded-full bg-emerald-500 ring-2 ring-white"></span>
                                    </span>
                                </div>
                                <div class="min-w-0 flex-1 pt-1">
                                    <Link :href="`/contacts?id=${contact.uuid}`" class="line-clamp-2 text-xl font-extrabold tracking-tight text-slate-950 transition-colors duration-300 hover:text-primary" @click.stop>
                                        {{ contactName(contact) }}
                                    </Link>
                                    <p class="mt-2 truncate text-left font-mono text-[15px] font-semibold text-slate-600" dir="ltr">{{ contactPhone(contact) }}</p>
                                </div>
                            </div>

                            <div class="mt-5 space-y-4">
                                <div>
                                    <p class="mb-2 text-xs font-extrabold uppercase text-slate-400">{{ $t('Groups') }}</p>
                                    <div class="flex flex-wrap gap-2">
                                        <span v-for="groupName in visibleGroupNames(contact)" :key="`group-${contact.id}-${groupName}`" class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100">
                                            {{ groupName }}
                                        </span>
                                        <span v-if="remainingGroupCount(contact) > 0" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
                                            +{{ remainingGroupCount(contact) }}
                                        </span>
                                        <span v-if="visibleGroupNames(contact).length === 0" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-500">{{ $t('No Group') }}</span>
                                    </div>
                                </div>

                                <div>
                                    <p class="mb-2 text-xs font-extrabold uppercase text-slate-400">{{ $t('Labels') }}</p>
                                    <div class="flex flex-wrap gap-2">
                                        <span v-for="label in visibleLabels(contact)" :key="`label-${contact.id}-${label}`" class="rounded-full bg-sky-50 px-3 py-1 text-xs font-bold text-sky-700 ring-1 ring-sky-100">
                                            {{ label }}
                                        </span>
                                        <span v-if="remainingLabelCount(contact) > 0" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
                                            +{{ remainingLabelCount(contact) }}
                                        </span>
                                        <span v-if="visibleLabels(contact).length === 0" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-500">{{ $t('No Labels') }}</span>
                                    </div>
                                </div>

                                <div v-if="visibleCustomFields(contact).length > 0">
                                    <p class="mb-2 text-xs font-extrabold uppercase text-slate-400">{{ $t('Custom Fields') }}</p>
                                    <div class="space-y-2 rounded-3xl bg-[#F8FAFC] p-3">
                                        <div v-for="field in visibleCustomFields(contact)" :key="`custom-${contact.id}-${field.key}`" class="flex items-start justify-between gap-3 text-xs">
                                            <span class="shrink-0 font-bold text-slate-500">{{ field.name }}</span>
                                            <span class="line-clamp-2 text-left font-semibold text-slate-700" dir="auto">{{ field.value }}</span>
                                        </div>
                                        <span v-if="remainingCustomFieldCount(contact) > 0" class="inline-flex rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-100">
                                            +{{ remainingCustomFieldCount(contact) }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-3 py-1 text-xs font-bold" :class="contact.is_subscribed ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'">
                                    {{ contact.is_subscribed ? $t('Subscribed') : $t('Unsubscribed') }}
                                </span>
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
                                    {{ $t(String(contact.contact_source || 'platform').toLowerCase()) }}
                                </span>
                            </div>

                            <button type="button" class="mt-6 inline-flex h-12 w-full items-center justify-center gap-2 rounded-full bg-[#25D366] px-4 text-sm font-extrabold text-white shadow-lg shadow-emerald-100 transition-all duration-300 hover:-translate-y-0.5 hover:bg-[#1fb85a]" @click.stop="openQuickWhatsapp(contact)">
                                <MessageCircle class="h-5 w-5" />
                                {{ $t('Quick WhatsApp') }}
                            </button>
                        </article>
                    </div>

                    <div v-else-if="viewMode === 'list'" key="list" class="space-y-3">
                        <article
                            v-for="contact in rows.data"
                            :key="`list-${contact.id}`"
                            class="group grid cursor-pointer grid-cols-1 gap-4 rounded-[2rem] border-2 bg-white p-4 shadow-sm shadow-slate-200/70 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg md:grid-cols-[auto_minmax(0,1fr)_auto] md:items-center"
                            :class="isRowSelected(contact.id) ? 'border-blue-500 ring-4 ring-blue-100' : 'border-transparent hover:border-slate-100'"
                            @click="toggleRow(contact.id)"
                        >
                            <div class="flex items-center gap-4">
                                <div class="relative shrink-0">
                                    <div class="flex h-14 w-14 items-center justify-center rounded-3xl text-xl font-extrabold ring-4" :class="avatarClass(contact)">
                                        {{ contactInitial(contact) }}
                                    </div>
                                    <span class="absolute -bottom-1 -left-1 h-3.5 w-3.5 rounded-full bg-emerald-500 ring-2 ring-white"></span>
                                </div>
                                <div
                                    class="flex h-8 w-8 items-center justify-center rounded-full border-2 bg-white shadow-sm transition-all duration-300"
                                    :class="isRowSelected(contact.id) ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-200 text-transparent group-hover:border-blue-300 group-hover:bg-blue-50'"
                                    :aria-label="isRowSelected(contact.id) ? $t('Selected') : $t('Select contact')"
                                >
                                    <Check class="h-4 w-4" />
                                </div>
                            </div>

                            <div class="min-w-0">
                                <Link :href="`/contacts?id=${contact.uuid}`" class="block truncate text-lg font-extrabold tracking-tight text-slate-950 hover:text-primary" @click.stop>{{ contactName(contact) }}</Link>
                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-sm font-semibold text-slate-600" dir="ltr">{{ contactPhone(contact) }}</span>
                                </div>
                                <div class="mt-3 grid gap-2 lg:grid-cols-3">
                                    <div>
                                        <p class="mb-1 text-[11px] font-extrabold uppercase text-slate-400">{{ $t('Groups') }}</p>
                                        <div class="flex flex-wrap gap-1.5">
                                            <span v-for="groupName in visibleGroupNames(contact)" :key="`list-group-${contact.id}-${groupName}`" class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">
                                                {{ groupName }}
                                            </span>
                                            <span v-if="remainingGroupCount(contact) > 0" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">+{{ remainingGroupCount(contact) }}</span>
                                            <span v-if="visibleGroupNames(contact).length === 0" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-500">{{ $t('No Group') }}</span>
                                        </div>
                                    </div>
                                    <div>
                                        <p class="mb-1 text-[11px] font-extrabold uppercase text-slate-400">{{ $t('Labels') }}</p>
                                        <div class="flex flex-wrap gap-1.5">
                                            <span v-for="label in visibleLabels(contact)" :key="`list-label-${contact.id}-${label}`" class="rounded-full bg-sky-50 px-2.5 py-1 text-xs font-bold text-sky-700">
                                                {{ label }}
                                            </span>
                                            <span v-if="remainingLabelCount(contact) > 0" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">+{{ remainingLabelCount(contact) }}</span>
                                            <span v-if="visibleLabels(contact).length === 0" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-500">{{ $t('No Labels') }}</span>
                                        </div>
                                    </div>
                                    <div v-if="visibleCustomFields(contact).length > 0">
                                        <p class="mb-1 text-[11px] font-extrabold uppercase text-slate-400">{{ $t('Custom Fields') }}</p>
                                        <div class="flex flex-wrap gap-1.5">
                                            <span v-for="field in visibleCustomFields(contact)" :key="`list-custom-${contact.id}-${field.key}`" class="max-w-full truncate rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                                {{ field.name }}: {{ field.value }}
                                            </span>
                                            <span v-if="remainingCustomFieldCount(contact) > 0" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">+{{ remainingCustomFieldCount(contact) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <button type="button" class="inline-flex h-11 items-center justify-center gap-2 rounded-full bg-[#25D366] px-5 text-sm font-extrabold text-white transition-all duration-300 hover:bg-[#1fb85a]" @click.stop="openQuickWhatsapp(contact)">
                                <MessageCircle class="h-4 w-4" />
                                {{ $t('Quick WhatsApp') }}
                            </button>
                        </article>
                    </div>

                    <div v-else key="table" class="overflow-hidden rounded-[2rem] border border-slate-100 bg-white shadow-sm shadow-slate-200/70">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-100" :class="uiTextAlignmentClass">
                                <thead class="bg-[#F8FAFC]">
                                    <tr>
                                        <th class="sticky right-0 z-10 w-14 bg-[#F8FAFC] px-4 py-4">
                                            <input
                                                ref="selectAllCheckboxRef"
                                                type="checkbox"
                                                class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                                :checked="allOnPageSelected"
                                                @change="toggleSelectAll"
                                            >
                                        </th>
                                        <th v-for="column in visibleTableColumns" :key="`head-${column.key}`" class="whitespace-nowrap px-4 py-4 text-xs font-extrabold uppercase tracking-normal text-slate-500">
                                            {{ $t(column.label) }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    <tr
                                        v-for="contact in rows.data"
                                        :key="`table-${contact.id}`"
                                        class="group cursor-pointer transition-all duration-300 hover:bg-slate-50/80"
                                        :class="isRowSelected(contact.id) ? 'bg-blue-50/70' : ''"
                                        @click="toggleRow(contact.id)"
                                    >
                                        <td class="sticky right-0 z-10 bg-inherit px-4 py-4">
                                            <div class="flex h-8 w-8 items-center justify-center rounded-full border-2 bg-white shadow-sm transition-all duration-300" :class="isRowSelected(contact.id) ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-200 text-transparent group-hover:border-blue-300 group-hover:bg-blue-50'">
                                                <Check class="h-4 w-4" />
                                            </div>
                                        </td>
                                        <td v-for="column in visibleTableColumns" :key="`cell-${contact.id}-${column.key}`" class="whitespace-nowrap px-4 py-4 align-top text-sm">
                                            <template v-if="column.key === 'name'">
                                                <div class="flex items-center gap-3">
                                                    <div class="relative shrink-0">
                                                        <div class="flex h-11 w-11 items-center justify-center rounded-2xl text-base font-extrabold ring-2" :class="avatarClass(contact)">
                                                            {{ contactInitial(contact) }}
                                                        </div>
                                                        <span class="absolute -bottom-0.5 -left-0.5 h-3 w-3 rounded-full bg-emerald-500 ring-2 ring-white"></span>
                                                    </div>
                                                    <Link :href="`/contacts?id=${contact.uuid}`" class="max-w-[14rem] truncate text-sm font-extrabold tracking-tight text-slate-950 hover:text-primary" @click.stop>
                                                        {{ contactName(contact) }}
                                                    </Link>
                                                </div>
                                            </template>
                                            <template v-else-if="column.key === 'phone'">
                                                <span class="font-mono font-semibold text-slate-600" dir="ltr">{{ contactPhone(contact) }}</span>
                                            </template>
                                            <template v-else-if="column.key === 'email'">
                                                <span class="text-slate-600">{{ contact.email || '-' }}</span>
                                            </template>
                                            <template v-else-if="column.key === 'groups'">
                                                <div class="flex max-w-[16rem] flex-wrap gap-1.5">
                                                    <span v-for="groupName in visibleGroupNames(contact)" :key="`table-group-${contact.id}-${groupName}`" class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">
                                                        {{ groupName }}
                                                    </span>
                                                    <span v-if="remainingGroupCount(contact) > 0" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">+{{ remainingGroupCount(contact) }}</span>
                                                    <span v-if="visibleGroupNames(contact).length === 0" class="text-xs font-bold text-slate-400">{{ $t('No Group') }}</span>
                                                </div>
                                            </template>
                                            <template v-else-if="column.key === 'labels'">
                                                <div class="flex max-w-[16rem] flex-wrap gap-1.5">
                                                    <span v-for="label in visibleLabels(contact)" :key="`table-label-${contact.id}-${label}`" class="rounded-full bg-sky-50 px-2.5 py-1 text-xs font-bold text-sky-700">
                                                        {{ label }}
                                                    </span>
                                                    <span v-if="remainingLabelCount(contact) > 0" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">+{{ remainingLabelCount(contact) }}</span>
                                                    <span v-if="visibleLabels(contact).length === 0" class="text-xs font-bold text-slate-400">{{ $t('No Labels') }}</span>
                                                </div>
                                            </template>
                                            <template v-else-if="column.key === 'subscription'">
                                                <span class="rounded-full px-3 py-1 text-xs font-bold" :class="contact.is_subscribed ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'">
                                                    {{ contact.is_subscribed ? $t('Subscribed') : $t('Unsubscribed') }}
                                                </span>
                                            </template>
                                            <template v-else-if="column.key === 'contact_source'">
                                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
                                                    {{ $t(String(contact.contact_source || 'platform').toLowerCase()) }}
                                                </span>
                                            </template>
                                            <template v-else-if="column.key === 'country'">
                                                <span class="text-slate-600">{{ contact.country || '-' }}</span>
                                            </template>
                                            <template v-else-if="column.key === 'created_at'">
                                                <span class="text-slate-600">{{ formatDate(contact.created_at) }}</span>
                                            </template>
                                            <template v-else-if="column.isCustom">
                                                <span class="block max-w-[16rem] truncate text-slate-600" dir="auto">{{ getCustomAttributeDisplay(contact, column.fieldName) }}</span>
                                            </template>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="visibleTableColumns.length === 0" class="border-t border-slate-100 p-6 text-center text-sm font-bold text-slate-500">
                            {{ $t('Choose at least one column to display in the table.') }}
                        </div>
                    </div>
                </Transition>

                <div class="flex flex-col gap-3 rounded-[2rem] border border-white bg-white px-4 py-3 text-sm shadow-sm shadow-slate-200/70 sm:flex-row sm:items-center sm:justify-between">
                    <p class="font-medium text-slate-500">{{ rows.meta?.from || 0 }}-{{ rows.meta?.to || 0 }} / {{ rows.meta?.total || 0 }}</p>
                    <div class="flex items-center justify-between gap-2 sm:justify-end">
                        <button type="button" class="inline-flex h-10 items-center gap-2 rounded-full border border-slate-200 px-4 font-bold text-slate-700 transition-all duration-300 disabled:opacity-40" :disabled="currentPage <= 1" @click="goToPage(currentPage - 1)">
                            <ChevronRight class="h-4 w-4" />
                            {{ $t('Previous') }}
                        </button>
                        <span class="rounded-full bg-[#F8FAFC] px-4 py-2 font-extrabold text-slate-700">{{ currentPage }} / {{ totalPages }}</span>
                        <button type="button" class="inline-flex h-10 items-center gap-2 rounded-full border border-slate-200 px-4 font-bold text-slate-700 transition-all duration-300 disabled:opacity-40" :disabled="currentPage >= totalPages" @click="goToPage(currentPage + 1)">
                            {{ $t('Next') }}
                            <ChevronLeft class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </section>
        </div>

        <Transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="translate-y-8 scale-95 opacity-0"
            enter-to-class="translate-y-0 scale-100 opacity-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="translate-y-0 scale-100 opacity-100"
            leave-to-class="translate-y-8 scale-95 opacity-0"
        >
            <div v-if="selectedCount > 0" class="fixed inset-x-0 bottom-5 z-50 flex justify-center px-4">
                <div class="flex max-w-[min(96vw,1100px)] flex-wrap items-center justify-center gap-2 rounded-[2rem] border border-white/10 bg-slate-950/95 p-2 text-white shadow-2xl shadow-slate-400/50 backdrop-blur sm:rounded-full">
                    <div class="flex items-center gap-2 rounded-full bg-white/10 px-4 py-2 text-sm font-extrabold">
                        <Check class="h-4 w-4 text-blue-300" />
                        {{ selectedCount }} {{ $t('selected') }}
                    </div>
                    <span v-if="allFilteredSelected" class="hidden rounded-full bg-blue-500/15 px-3 py-2 text-xs font-bold text-blue-100 sm:inline-flex">{{ $t('All filtered results selected') }}</span>
                    <button type="button" class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-bold text-white transition-all duration-300 hover:bg-white/10" @click="bulkActionModal = 'add_label'">
                        <Tags class="h-4 w-4" />
                        {{ $t('Add Labels') }}
                    </button>
                    <button type="button" class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-bold text-white transition-all duration-300 hover:bg-white/10" @click="bulkActionModal = 'remove_label'">
                        <Minus class="h-4 w-4 text-sky-300" />
                        {{ $t('Remove Labels') }}
                    </button>
                    <button type="button" class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-bold text-white transition-all duration-300 hover:bg-white/10" @click="bulkActionModal = 'add_group'">
                        <Users class="h-4 w-4 text-emerald-300" />
                        {{ $t('Add Group') }}
                    </button>
                    <button type="button" class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-bold text-white transition-all duration-300 hover:bg-white/10" @click="bulkActionModal = 'remove_group'">
                        <Minus class="h-4 w-4 text-emerald-300" />
                        {{ $t('Remove Group') }}
                    </button>
                    <button type="button" class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-bold text-white transition-all duration-300 hover:bg-white/10" @click="bulkActionModal = 'change_subscription_status'">
                        <Check class="h-4 w-4 text-amber-300" />
                        {{ $t('Change Subscription') }}
                    </button>

                    <button v-if="role !== 'agent'" type="button" class="inline-flex items-center gap-2 rounded-full bg-rose-500 px-4 py-2 text-sm font-bold text-white transition-all duration-300 hover:bg-rose-600" @click="runBulkDelete">
                        <Trash2 class="h-4 w-4" />
                        {{ $t('Delete') }}
                    </button>
                    <button type="button" class="inline-flex items-center gap-2 rounded-full px-3 py-2 text-sm font-bold text-slate-200 transition-all duration-300 hover:bg-white/10" @click="runExport">
                        <Download class="h-4 w-4" />
                        {{ $t('Export Selected') }}
                    </button>
                    <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-slate-200 transition-all duration-300 hover:bg-white/20" :aria-label="$t('Clear Selection')" @click="clearSelectionAndFilters">
                        <X class="h-4 w-4" />
                    </button>
                </div>
            </div>
        </Transition>
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
                    <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                        {{ groupContactsCount(group) }}
                    </span>
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
