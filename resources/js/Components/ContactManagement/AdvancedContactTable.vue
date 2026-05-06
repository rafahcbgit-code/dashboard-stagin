<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { Link, router, usePage } from '@inertiajs/vue3';
import debounce from 'lodash/debounce';
import { useI18n } from 'vue-i18n';
import {
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Filter,
    Grid2X2,
    HelpCircle,
    Import,
    List,
    MessageCircle,
    Minus,
    Search,
    Columns3,
    Tags,
    Table2,
    Trash2,
    Upload,
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
const columnsStorageKey = computed(() => {
    const organizationId = page.props?.organization?.id ?? 'default';
    return `advanced-contact-columns-${organizationId}`;
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
const isSubscriptionMenuOpen = ref(false);
const isSourceMenuOpen = ref(false);
const isBlockedMenuOpen = ref(false);
const isImportWizardOpen = ref(false);
const viewMode = ref('table');
const columnsMenuRef = ref(null);
const subscriptionMenuRef = ref(null);
const sourceMenuRef = ref(null);
const blockedMenuRef = ref(null);
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
    email: false,
    groups: false,
    labels: false,
    subscription: false,
    contact_source: false,
    country: false,
    created_at: false,
};

const customColumnKey = (field) => `custom_attr_${field.uuid ?? field.name}`;

const buildDefaultColumns = () => ({
    ...defaultColumns,
    ...Object.fromEntries((props.fields || []).map((field) => [customColumnKey(field), false])),
});

const columns = ref(loadColumns());

function loadColumns() {
    const defaults = buildDefaultColumns();

    if (typeof window === 'undefined') {
        return defaults;
    }

    try {
        const cached = localStorage.getItem(columnsStorageKey.value);
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
                nextColumns[key] = false;
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

    localStorage.setItem(columnsStorageKey.value, JSON.stringify(value));
}, { deep: true });

watch(columnsStorageKey, () => {
    columns.value = loadColumns();
});

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
const previousPageIcon = computed(() => (isRtl.value ? ChevronRight : ChevronLeft));
const nextPageIcon = computed(() => (isRtl.value ? ChevronLeft : ChevronRight));
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
    .filter(Boolean)
    .filter((name, index, names) => names.indexOf(name) === index);

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

const contactChatUrl = (contact) => {
    return contact?.uuid ? `/chats/${contact.uuid}` : '#';
};

const openContactChat = (contact) => {
    const url = contactChatUrl(contact);
    if (url === '#') {
        return;
    }

    router.visit(url);
};

const messageSelectedContacts = () => {
    if (selectedContactsOnPage.value.length === 1 && selectedCount.value === 1) {
        openContactChat(selectedContactsOnPage.value[0]);
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

const contactSourceOptions = computed(() => [
    {
        value: 'platform',
        label: 'Platform',
        description: t('Platform means the contact was added manually inside the system by your team.'),
    },
    {
        value: 'imported',
        label: 'Imported',
        description: t('Imported means the contact came from an uploaded file. Always make sure WhatsApp consent exists before using it in campaigns.'),
    },
    {
        value: 'whatsapp',
        label: 'WhatsApp',
        description: t('WhatsApp means the contact came from an interaction or conversation on WhatsApp.'),
    },
    {
        value: 'api',
        label: 'API',
        description: t('API means the contact was added automatically from an external system connected to the platform, such as a store or another CRM.'),
    },
]);

const selectedContactSourceHelp = computed(() => {
    const selectedSource = contactSourceOptions.value.find((source) => source.value === query.contact_source);
    return selectedSource?.description || helpText.value.sourceColumn;
});

const allContactSourceOptions = computed(() => [
    {
        value: '',
        label: 'All Sources',
        description: helpText.value.sourceColumn,
    },
    ...contactSourceOptions.value,
]);

const selectedContactSourceOption = computed(() => (
    allContactSourceOptions.value.find((source) => source.value === query.contact_source) || allContactSourceOptions.value[0]
));

const subscriptionStatusOptions = computed(() => [
    { value: '', label: 'All Statuses' },
    { value: '1', label: 'Subscribed' },
    { value: '0', label: 'Unsubscribed' },
]);

const selectedSubscriptionStatusOption = computed(() => (
    subscriptionStatusOptions.value.find((status) => status.value === query.subscription_status) || subscriptionStatusOptions.value[0]
));

const blockedStatusOptions = computed(() => [
    { value: '', label: 'All' },
    { value: '1', label: 'Blocked' },
    { value: '0', label: 'Not blocked' },
]);

const selectedBlockedStatusOption = computed(() => (
    blockedStatusOptions.value.find((status) => status.value === query.blocked_status) || blockedStatusOptions.value[0]
));

const helpText = computed(() => ({
    page: t('This page brings together all your WhatsApp customers: you can search, classify, filter, import, and manage multiple contacts at once.'),
   // tour: t('Start a quick interactive guide that introduces the key parts of this page step by step.'),
    import: t('Use this to upload a customer file. Always make sure customers agreed to receive your WhatsApp messages before adding them.'),
    exportAll: t('Download a copy of all contacts for reports or backup.'),
    addContact: t('Add one customer manually when you do not need to upload a full file.'),
    totalContacts: t('The total number of contacts stored in your account, whether they appear in the current results or not.'),
    filteredContacts: t('The number of customers matching the current search and filters. This is the audience size you may target now.'),
    activeFilters: t('The number of filtering conditions currently applied to the list.'),
    search: t('Quickly search by customer name, phone number, email, or country.'),
    countryFilter: t('Choose a country code to show customers from a specific region and personalize campaigns by market.'),
    gridView: t('A visual view for quickly reviewing each customer as a card.'),
    listView: t('A medium-density view that shows the most important customer data in a clear row.'),
    tableView: t('A table view for careful review and comparison between fields.'),
    columns: t('Choose which columns you want to see in the table to reduce distraction.'),
    groups: t('Groups help you divide customers into audiences such as interested customers, subscribers, or VIP customers.'),
    advancedFilters: t('Use advanced filters to create a precise segment by group, label, subscription status, source, date, or custom data.'),
    selectAllResults: t('Select all results matching the current filters, not only the visible page, to run a bulk action.'),
    activeFilterBanner: t('A filter is applied; the displayed results are not your full customer database.'),
    resetFilters: t('Clear all filtering conditions and return to the full list.'),
    contacts: t('This is your current customer list. Review labels and subscription status before sending any campaign.'),
    selectPage: t('Select or deselect only the customers visible on this page.'),
    contactCard: t('Click the card to select the customer, or open the name to view details.'),
    contactName: t('Open the customer profile to view their data and history.'),
    whatsappMessage: t('Open a WhatsApp conversation with this customer. Use personal messages instead of generic messages whenever possible.'),
    groupsColumn: t('The group shows the customer audience or general classification.'),
    labelsColumn: t('Labels help your team understand the customer status, such as paid, pending payment, or interested.'),
    subscriptionColumn: t('Subscription status is very important: do not send campaigns to a customer who unsubscribed.'),
    blockedStatus: t('Block status shows contacts that have been blocked. Use it to exclude blocked customers from follow-up or campaigns.'),
    sourceColumn: t('The customer source shows whether they came from an import, WhatsApp, API, or inside the platform.'),
    countryColumn: t('Country helps you personalize language, offers, and timing.'),
    createdAtColumn: t('The creation date helps you understand how recently the customer was added to the database.'),
    dateAttributes: t('Use this section to filter by the contact creation date, or by the value of existing contact attributes such as language, stage, or any custom field.'),
    pagination: t('Move between pages of the current results without changing the search or filters.'),
    bulkBar: t('After selecting customers, use this bar to add labels or groups, change subscription status, or export.'),
}));
const helpTooltip = reactive({
    visible: false,
    text: '',
    left: 16,
    top: 16,
    placement: 'bottom',
});

const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

const positionHelpTooltip = (trigger) => {
    if (!trigger || typeof window === 'undefined') {
        return;
    }

    const rect = trigger.getBoundingClientRect();
    const tooltipWidth = Math.min(340, window.innerWidth - 32);
    const left = clamp(
        rect.left + (rect.width / 2) - (tooltipWidth / 2),
        16,
        Math.max(16, window.innerWidth - tooltipWidth - 16)
    );
    const canShowAbove = rect.top > 130;

    helpTooltip.left = Math.round(left);
    helpTooltip.top = Math.round(canShowAbove ? rect.top - 12 : rect.bottom + 12);
    helpTooltip.placement = canShowAbove ? 'top' : 'bottom';
};

const showHelpTooltip = (event) => {
    const trigger = event.target?.closest?.('[data-help-icon]');
    if (!trigger) {
        hideHelpTooltip();
        return;
    }

    const text = trigger.dataset.helpText || trigger.getAttribute('aria-label');
    if (!text) {
        return;
    }

    helpTooltip.text = text;
    positionHelpTooltip(trigger);
    helpTooltip.visible = true;
};

const hideHelpTooltip = () => {
    helpTooltip.visible = false;
};

const handleHelpPointerOut = (event) => {
    const trigger = event.target?.closest?.('[data-help-icon]');
    const nextTarget = event.relatedTarget;

    if (trigger && nextTarget instanceof Node && trigger.contains(nextTarget)) {
        return;
    }

    hideHelpTooltip();
};

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

const isGroupSelectedForFilter = (groupId) => query.group_ids.includes(String(groupId));

const isGroupSelectedForDeletion = (groupUuid) => selectedGroupUuidsForDeletion.value.includes(String(groupUuid));

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
        createGroupError.value = t('Audience name is required.');
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
            throw new Error(t('Failed to create audience.'));
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
            || t('Failed to create audience.');
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
        createGroupError.value = error?.response?.data?.message || t('Failed to delete audience.');
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
        createGroupError.value = error?.response?.data?.message || t('Failed to delete selected audience.');
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

    const filters = currentFilterPayload();
    if (filters.group_ids) {
        filters.filter_group_ids = filters.group_ids;
        delete filters.group_ids;
    }
    if (bulkActionModal.value === 'change_subscription_status' && filters.subscription_status) {
        filters.filter_subscription_status = filters.subscription_status;
        delete filters.subscription_status;
    }

    const payload = {
        ...filters,
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

const closeStatusMenus = () => {
    isSubscriptionMenuOpen.value = false;
    isSourceMenuOpen.value = false;
    isBlockedMenuOpen.value = false;
};

const toggleSubscriptionMenu = () => {
    const shouldOpen = !isSubscriptionMenuOpen.value;
    closeStatusMenus();
    isSubscriptionMenuOpen.value = shouldOpen;
};

const closeSubscriptionMenu = () => {
    isSubscriptionMenuOpen.value = false;
};

const selectSubscriptionStatus = (value) => {
    query.subscription_status = value;
    closeSubscriptionMenu();
};

const toggleSourceMenu = () => {
    const shouldOpen = !isSourceMenuOpen.value;
    closeStatusMenus();
    isSourceMenuOpen.value = shouldOpen;
};

const closeSourceMenu = () => {
    isSourceMenuOpen.value = false;
};

const selectContactSource = (value) => {
    query.contact_source = value;
    closeSourceMenu();
};

const toggleBlockedMenu = () => {
    const shouldOpen = !isBlockedMenuOpen.value;
    closeStatusMenus();
    isBlockedMenuOpen.value = shouldOpen;
};

const closeBlockedMenu = () => {
    isBlockedMenuOpen.value = false;
};

const selectBlockedStatus = (value) => {
    query.blocked_status = value;
    closeBlockedMenu();
};

const isInsideElement = (element, target) => (
    Boolean(element)
    && target instanceof Node
    && element.contains(target)
);

const handleOutsidePointerDown = (event) => {
    if (isColumnsMenuOpen.value && !isInsideElement(columnsMenuRef.value, event.target)) {
        closeColumnsMenu();
    }

    if (isSubscriptionMenuOpen.value && !isInsideElement(subscriptionMenuRef.value, event.target)) {
        closeSubscriptionMenu();
    }

    if (isSourceMenuOpen.value && !isInsideElement(sourceMenuRef.value, event.target)) {
        closeSourceMenu();
    }

    if (isBlockedMenuOpen.value && !isInsideElement(blockedMenuRef.value, event.target)) {
        closeBlockedMenu();
    }
};

onMounted(() => {
    loadSelectedIds();
    document.addEventListener('pointerdown', handleOutsidePointerDown, true);
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', handleOutsidePointerDown, true);
});

const onImportCompleted = () => {
    runFilterRequest();
};
</script>

<template>
    <div
        :dir="uiDirection"
        class="-m-4 min-h-screen bg-[#F8FAFC] px-4 py-5 text-slate-950 md:-m-6 md:px-6 md:py-6"
        :class="uiTextAlignmentClass"
        @pointerover="showHelpTooltip"
        @pointerout="handleHelpPointerOut"
        @focusin="showHelpTooltip"
        @focusout="hideHelpTooltip"
        @click="showHelpTooltip"
    >
        <div class="mx-auto max-w-[1800px] space-y-5 pb-24">
            <section class="rounded-[2rem] border border-white bg-white/90 p-5 shadow-sm shadow-slate-200/70">
                <div class="flex flex-col gap-5">
                    <div class="max-w-2xl">
                        <div class="mb-3 inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                            {{ $t('WhatsApp CRM') }}
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="text-3xl font-extrabold tracking-tight text-slate-950 md:text-4xl">{{ $t('Contact Management') }}</h1>
                            <span class="help-tooltip" tabindex="0" :aria-label="helpText.page">
                                <HelpCircle class="h-4 w-4" />
                                <span class="help-tooltip-content">{{ helpText.page }}</span>
                            </span>
                        </div>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ $t('Manage contacts, filters, groups, and bulk actions from one place.') }}</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        
                        <button type="button" class="btn-import inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md" :aria-label="helpText.import" @click="isImportWizardOpen = true">
                            <Import class="h-4 w-4" />
                            <span>{{ $t('Import') }}</span>
                            <span class="text-[10px] font-extrabold uppercase tracking-normal text-slate-400">XLSX/CSV</span>
                        </button>
                        <button type="button" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md" :data-help-text="helpText.exportAll" data-help-icon @click="runExportAll">
                            <Upload class="h-4 w-4" />
                            {{ $t('Export All') }}
                        </button>
                        <Link href="/contacts/add" class="inline-flex items-center gap-2 rounded-full bg-slate-950 px-4 py-2.5 text-sm font-bold text-white shadow-lg shadow-slate-300 transition-all duration-300 hover:-translate-y-0.5 hover:bg-slate-800" :data-help-text="helpText.addContact" data-help-icon>
                            <UserPlus class="h-4 w-4" />
                            {{ $t('Add Contact') }}
                        </Link>
                    </div>
                </div>

                <div class="stats-container mt-5 grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div class="rounded-[1.5rem] border border-slate-100 bg-[#F8FAFC] p-4" :aria-label="helpText.totalContacts">
                        <p class="flex items-center gap-1.5 text-xs font-bold text-slate-500">
                            {{ $t('Total Contacts') }}
                            <span class="help-tooltip" tabindex="0" :aria-label="helpText.totalContacts">
                                <HelpCircle class="h-3.5 w-3.5" />
                                <span class="help-tooltip-content">{{ helpText.totalContacts }}</span>
                            </span>
                        </p>
                        <p class="mt-2 text-2xl font-extrabold tracking-tight text-slate-950">{{ rowCount }}</p>
                    </div>
                    <div class="rounded-[1.5rem] border border-slate-100 bg-[#F8FAFC] p-4" :aria-label="helpText.filteredContacts">
                        <p class="flex items-center gap-1.5 text-xs font-bold text-slate-500">
                            {{ $t('Filtered Contacts') }}
                            <span class="help-tooltip" tabindex="0" :aria-label="helpText.filteredContacts">
                                <HelpCircle class="h-3.5 w-3.5" />
                                <span class="help-tooltip-content">{{ helpText.filteredContacts }}</span>
                            </span>
                        </p>
                        <p class="mt-2 text-2xl font-extrabold tracking-tight text-slate-950">{{ filteredCount }}</p>
                    </div>
                    <div class="rounded-[1.5rem] border border-slate-100 bg-[#F8FAFC] p-4" :aria-label="helpText.activeFilters">
                        <p class="flex items-center gap-1.5 text-xs font-bold text-slate-500">
                            {{ $t('Active Filters') }}
                            <span class="help-tooltip" tabindex="0" :aria-label="helpText.activeFilters">
                                <HelpCircle class="h-3.5 w-3.5" />
                                <span class="help-tooltip-content">{{ helpText.activeFilters }}</span>
                            </span>
                        </p>
                        <p class="mt-2 text-2xl font-extrabold tracking-tight text-slate-950">{{ activeFilterCount }}</p>
                    </div>
                </div>
            </section>

            <section class="rounded-[2rem] border border-white bg-white/95 p-4 shadow-sm shadow-slate-200/70">
                <div class="flex flex-col gap-3 2xl:flex-row 2xl:items-center 2xl:justify-between">
                    <div class="search-bar-container flex min-w-0 flex-1 flex-col gap-3 sm:flex-row">
                        <label class="relative block min-w-[18rem] flex-1">
                            <Search class="pointer-events-none absolute top-1/2 z-10 h-4 w-4 -translate-y-1/2 text-slate-400" :class="isRtl ? 'right-4' : 'left-4'" />
                            <input v-model="query.search" type="text" class="h-12 w-full rounded-full border border-slate-200 bg-[#F8FAFC] text-sm font-medium outline-none ring-primary/20 transition-all duration-300 placeholder:text-slate-400 focus:border-primary focus:bg-white focus:ring" :class="isRtl ? 'pr-11 pl-4' : 'pl-11 pr-4'" :placeholder="$t('Search name, phone, country, or email')" :aria-label="helpText.search" @input="debouncedSearch">
                        </label>
                        <select v-model="query.country_dial_code" class="h-12 w-full min-w-[14rem] rounded-full border border-slate-200 bg-[#F8FAFC] px-4 text-sm font-medium text-slate-700 outline-none ring-primary/20 transition-all duration-300 focus:border-primary focus:bg-white focus:ring sm:w-72" :aria-label="helpText.countryFilter" @change="applyQuickCountryDialCodeFilter">
                            <option value="">{{ $t('All Countries') }}</option>
                            <option v-for="option in countryDialCodeOptions" :key="`quick-${option.region}-${option.value}`" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <div class="inline-flex rounded-full bg-slate-100 p-1">
                            <button type="button" class="inline-flex items-center gap-2 rounded-full px-3 py-2 text-sm font-bold transition-all duration-300" :class="viewMode === 'grid' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-800'" :data-help-text="helpText.gridView" data-help-icon @click="viewMode = 'grid'">
                                <Grid2X2 class="h-4 w-4" />
                                {{ $t('Grid') }}
                            </button>
                            <button type="button" class="inline-flex items-center gap-2 rounded-full px-3 py-2 text-sm font-bold transition-all duration-300" :class="viewMode === 'list' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-800'" :data-help-text="helpText.listView" data-help-icon @click="viewMode = 'list'">
                                <List class="h-4 w-4" />
                                {{ $t('List') }}
                            </button>
                            <button type="button" class="inline-flex items-center gap-2 rounded-full px-3 py-2 text-sm font-bold transition-all duration-300" :class="viewMode === 'table' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-800'" :data-help-text="helpText.tableView" data-help-icon @click="viewMode = 'table'">
                                <Table2 class="h-4 w-4" />
                                {{ $t('Table') }}
                            </button>
                        </div>
                        <div v-if="viewMode === 'table'" ref="columnsMenuRef" class="relative">
                            <button type="button" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition-all duration-300 hover:border-indigo-200 hover:text-indigo-700" :data-help-text="helpText.columns" data-help-icon @click.stop="toggleColumnsMenu">
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
                                        <p class="flex items-center gap-1.5 text-sm font-extrabold tracking-tight text-slate-950">
                                            {{ $t('Table Columns') }}
                                            <span class="help-tooltip" tabindex="0" :aria-label="helpText.columns">
                                                <HelpCircle class="h-3.5 w-3.5" />
                                                <span class="help-tooltip-content">{{ helpText.columns }}</span>
                                            </span>
                                        </p>
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
                        <button type="button" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition-all duration-300 hover:border-emerald-200 hover:text-emerald-700" :data-help-text="helpText.groups" data-help-icon @click="isGroupsModalOpen = true">
                            <Users class="h-4 w-4" />
                            {{ $t('Groups') }}
                        </button>
                        <button type="button" class="btn-advanced-filters inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition-all duration-300 hover:border-sky-200 hover:text-sky-700" :data-help-text="helpText.advancedFilters" data-help-icon @click="isFilterModalOpen = true">
                            <Filter class="h-4 w-4" />
                            {{ $t('Advanced Filters') }}
                            <span v-if="activeFilterCount > 0" class="rounded-full bg-sky-100 px-2 py-0.5 text-xs text-sky-700">{{ activeFilterCount }}</span>
                        </button>
                        <button type="button" class="checkbox-select-all inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition-all duration-300 hover:border-blue-200 hover:text-blue-700" :data-help-text="helpText.selectAllResults" data-help-icon @click="selectAllFilteredRows">
                            <Check class="h-4 w-4" />
                            {{ $t('Select All Results') }}
                        </button>
                    </div>
                </div>

                <div v-if="activeFilterCount > 0" class="mt-3 flex flex-wrap items-center gap-2 rounded-full border border-amber-100 bg-amber-50 px-4 py-2">
                    <span class="text-xs font-bold text-amber-700" :aria-label="helpText.activeFilterBanner">{{ activeFilterCount }} {{ $t('filters active') }}</span>
                    <button type="button" class="rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-700 ring-1 ring-amber-100 transition-all duration-300 hover:text-amber-700" :aria-label="helpText.resetFilters" @click="clearFilters">{{ $t('Reset Filters') }}</button>
                </div>
            </section>

            <section class="contacts-table space-y-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-2xl font-extrabold tracking-tight text-slate-950">{{ $t('Contacts') }}</h2>
                            <span class="help-tooltip" tabindex="0" :aria-label="helpText.contacts">
                                <HelpCircle class="h-4 w-4" />
                                <span class="help-tooltip-content">{{ helpText.contacts }}</span>
                            </span>
                        </div>
                        <p class="mt-1 text-sm text-slate-500">{{ rows.meta?.from || 0 }}-{{ rows.meta?.to || 0 }} / {{ rows.meta?.total || 0 }}</p>
                    </div>
                    <button type="button" class="inline-flex items-center justify-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 transition-all duration-300 hover:border-blue-200 hover:text-blue-700" :data-help-text="helpText.selectPage" data-help-icon @click="toggleSelectAll">
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
                            :aria-label="helpText.contactCard"
                            @click="toggleRow(contact.id)"
                        >
                            <div
                                class="absolute left-5 top-5 flex h-9 w-9 items-center justify-center rounded-full border-2 bg-white shadow-sm transition-all duration-300"
                                :class="isRowSelected(contact.id) ? 'border-blue-600 bg-white text-blue-600 shadow-lg shadow-blue-100' : 'border-slate-200 text-slate-300 group-hover:border-blue-300 group-hover:bg-blue-50'"
                                :aria-label="isRowSelected(contact.id) ? $t('Selected') : $t('Select contact')"
                            >
                                <Check v-if="isRowSelected(contact.id)" class="h-4 w-4 stroke-[3]" />
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
                                    <Link :href="`/contacts?id=${contact.uuid}`" class="line-clamp-2 text-xl font-extrabold tracking-tight text-slate-950 transition-colors duration-300 hover:text-primary" :aria-label="helpText.contactName" @click.stop>
                                        {{ contactName(contact) }}
                                    </Link>
                                    <p class="mt-2 truncate text-left font-mono text-[15px] font-semibold text-slate-600" dir="ltr">{{ contactPhone(contact) }}</p>
                                </div>
                            </div>

                            <div class="mt-5 space-y-4">
                                <div>
                                    <p class="mb-2 text-xs font-extrabold uppercase text-slate-400" :data-help-text="helpText.groupsColumn" data-help-icon>{{ $t('Groups') }}</p>
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
                                    <p class="mb-2 text-xs font-extrabold uppercase text-slate-400" :data-help-text="helpText.labelsColumn" data-help-icon>{{ $t('Labels') }}</p>
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

                            <Link
                                :href="contactChatUrl(contact)"
                                class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-full bg-[#25D366] px-5 py-3 text-sm font-extrabold text-white shadow-sm shadow-emerald-200 transition-all duration-300 hover:-translate-y-0.5 hover:bg-[#1fb85a] hover:shadow-lg hover:shadow-emerald-200"
                                :aria-label="helpText.whatsappMessage"
                                @click.stop
                            >
                                <MessageCircle class="h-4 w-4" />
                                {{ $t('Send WhatsApp Message') }}
                            </Link>
                        </article>
                    </div>

                    <div v-else-if="viewMode === 'list'" key="list" class="space-y-3">
                        <article
                            v-for="contact in rows.data"
                            :key="`list-${contact.id}`"
                            class="group grid cursor-pointer grid-cols-1 gap-4 rounded-[2rem] border-2 bg-white p-4 shadow-sm shadow-slate-200/70 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg md:grid-cols-[auto_minmax(0,1fr)_auto] md:items-center"
                            :class="isRowSelected(contact.id) ? 'border-blue-500 ring-4 ring-blue-100' : 'border-transparent hover:border-slate-100'"
                            :aria-label="helpText.contactCard"
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
                                    :class="isRowSelected(contact.id) ? 'border-blue-600 bg-white text-blue-600 shadow-blue-100' : 'border-slate-200 text-slate-300 group-hover:border-blue-300 group-hover:bg-blue-50'"
                                    :aria-label="isRowSelected(contact.id) ? $t('Selected') : $t('Select contact')"
                                >
                                    <Check v-if="isRowSelected(contact.id)" class="h-4 w-4 stroke-[3]" />
                                </div>
                            </div>

                            <div class="min-w-0">
                                <Link :href="`/contacts?id=${contact.uuid}`" class="block truncate text-lg font-extrabold tracking-tight text-slate-950 hover:text-primary" :aria-label="helpText.contactName" @click.stop>{{ contactName(contact) }}</Link>
                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-sm font-semibold text-slate-600" dir="ltr">{{ contactPhone(contact) }}</span>
                                </div>
                                <div class="mt-3 grid gap-2 lg:grid-cols-3">
                                    <div>
                                        <p class="mb-1 text-[11px] font-extrabold uppercase text-slate-400" :data-help-text="helpText.groupsColumn" data-help-icon>{{ $t('Groups') }}</p>
                                        <div class="flex flex-wrap gap-1.5">
                                            <span v-for="groupName in visibleGroupNames(contact)" :key="`list-group-${contact.id}-${groupName}`" class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">
                                                {{ groupName }}
                                            </span>
                                            <span v-if="remainingGroupCount(contact) > 0" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">+{{ remainingGroupCount(contact) }}</span>
                                            <span v-if="visibleGroupNames(contact).length === 0" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-500">{{ $t('No Group') }}</span>
                                        </div>
                                    </div>
                                    <div>
                                        <p class="mb-1 text-[11px] font-extrabold uppercase text-slate-400" :data-help-text="helpText.labelsColumn" data-help-icon>{{ $t('Labels') }}</p>
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

                            <Link :href="contactChatUrl(contact)" class="inline-flex h-11 items-center justify-center gap-2 rounded-full bg-[#25D366] px-5 text-sm font-extrabold text-white transition-all duration-300 hover:bg-[#1fb85a]" :aria-label="helpText.whatsappMessage" @click.stop>
                                <MessageCircle class="h-4 w-4" />
                                {{ $t('Send WhatsApp Message') }}
                            </Link>
                        </article>
                    </div>

                    <div v-else key="table" class="overflow-hidden rounded-[2rem] border border-slate-100 bg-white shadow-sm shadow-slate-200/70">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-100" :class="uiTextAlignmentClass">
                                <thead class="bg-[#F8FAFC]">
                                    <tr>
                                         <th class="sticky right-0 z-10 w-14 bg-[#F8FAFC] px-4 py-4" :aria-label="helpText.selectPage">
                                            <input
                                                ref="selectAllCheckboxRef"
                                                type="checkbox"
                                                class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                                :checked="allOnPageSelected"
                                                @change="toggleSelectAll"
                                            >
                                        </th>
                                        <th v-for="column in visibleTableColumns" :key="`head-${column.key}`" class="whitespace-nowrap px-4 py-4 text-xs font-extrabold uppercase tracking-normal text-slate-500" :aria-label="column.key === 'groups' ? helpText.groupsColumn : column.key === 'labels' ? helpText.labelsColumn : column.key === 'subscription' ? helpText.subscriptionColumn : column.key === 'contact_source' ? helpText.sourceColumn : column.key === 'country' ? helpText.countryColumn : column.key === 'created_at' ? helpText.createdAtColumn : column.key === 'name' ? helpText.contactName : ''">
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
                                        :aria-label="helpText.contactCard"
                                        @click="toggleRow(contact.id)"
                                    >
                                        <td class="sticky right-0 z-10 bg-inherit px-4 py-4">
                                            <div class="flex h-8 w-8 items-center justify-center rounded-full border-2 bg-white shadow-sm transition-all duration-300" :class="isRowSelected(contact.id) ? 'border-blue-600 bg-white text-blue-600 shadow-blue-100' : 'border-slate-200 text-slate-300 group-hover:border-blue-300 group-hover:bg-blue-50'">
                                                <Check v-if="isRowSelected(contact.id)" class="h-4 w-4 stroke-[3]" />
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
                                                    <Link :href="`/contacts?id=${contact.uuid}`" class="max-w-[14rem] truncate text-sm font-extrabold tracking-tight text-slate-950 hover:text-primary" :aria-label="helpText.contactName" @click.stop>
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
                                                <span class="rounded-full px-3 py-1 text-xs font-bold" :class="contact.is_subscribed ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'" :aria-label="helpText.subscriptionColumn">
                                                    {{ contact.is_subscribed ? $t('Subscribed') : $t('Unsubscribed') }}
                                                </span>
                                            </template>
                                            <template v-else-if="column.key === 'contact_source'">
                                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600" :aria-label="helpText.sourceColumn">
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

                <div class="flex flex-col gap-3 rounded-[2rem] border border-white bg-white px-4 py-3 text-sm shadow-sm shadow-slate-200/70 sm:flex-row sm:items-center sm:justify-between" :aria-label="helpText.pagination">
                    <p class="font-medium text-slate-500">{{ rows.meta?.from || 0 }}-{{ rows.meta?.to || 0 }} / {{ rows.meta?.total || 0 }}</p>
                    <div class="flex items-center justify-between gap-2 sm:justify-end">
                        <button type="button" class="inline-flex h-10 min-w-[7.5rem] items-center justify-center gap-2 rounded-full border border-slate-200 px-4 font-bold text-slate-700 transition-all duration-300 hover:border-blue-200 hover:text-blue-700 disabled:cursor-not-allowed disabled:text-slate-400 disabled:opacity-50" :aria-label="helpText.pagination" :disabled="currentPage <= 1" @click="goToPage(currentPage - 1)">
                            <component :is="previousPageIcon" class="h-4 w-4 stroke-[2.5]" />
                            {{ $t('Previous') }}
                        </button>
                        <span class="inline-flex h-10 min-w-[5.5rem] items-center justify-center rounded-full bg-[#F8FAFC] px-4 font-extrabold text-slate-700" dir="ltr">{{ currentPage }} / {{ totalPages }}</span>
                        <button type="button" class="inline-flex h-10 min-w-[7.5rem] items-center justify-center gap-2 rounded-full border border-slate-200 px-4 font-bold text-slate-700 transition-all duration-300 hover:border-blue-200 hover:text-blue-700 disabled:cursor-not-allowed disabled:text-slate-400 disabled:opacity-50" :aria-label="helpText.pagination" :disabled="currentPage >= totalPages" @click="goToPage(currentPage + 1)">
                            {{ $t('Next') }}
                            <component :is="nextPageIcon" class="h-4 w-4 stroke-[2.5]" />
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
                <div class="flex max-w-[min(96vw,1100px)] flex-wrap items-center justify-center gap-2 rounded-[2rem] border border-white/10 bg-slate-950/95 p-2 text-white shadow-2xl shadow-slate-400/50 backdrop-blur sm:rounded-full" :aria-label="helpText.bulkBar">
                    <div class="flex items-center gap-2 rounded-full bg-white/10 px-4 py-2 text-sm font-extrabold" :aria-label="helpText.bulkBar">
                        <Check class="h-4 w-4 text-blue-300" />
                        {{ selectedCount }} {{ $t('selected') }}
                    </div>
                    <span v-if="allFilteredSelected" class="hidden rounded-full bg-blue-500/15 px-3 py-2 text-xs font-bold text-blue-100 sm:inline-flex" :aria-label="helpText.selectAllResults">{{ $t('All filtered results selected') }}</span>
                    <button type="button" class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-bold text-white transition-all duration-300 hover:bg-white/10" :data-help-text="helpText.labelsColumn" data-help-icon @click="bulkActionModal = 'add_label'">
                        <Tags class="h-4 w-4" />
                        {{ $t('Add Labels') }}
                    </button>
                    <button type="button" class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-bold text-white transition-all duration-300 hover:bg-white/10" :data-help-text="helpText.labelsColumn" data-help-icon @click="bulkActionModal = 'remove_label'">
                        <Minus class="h-4 w-4 text-sky-300" />
                        {{ $t('Remove Labels') }}
                    </button>
                    <button type="button" class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-bold text-white transition-all duration-300 hover:bg-white/10" :data-help-text="helpText.groupsColumn" data-help-icon @click="bulkActionModal = 'add_group'">
                        <Users class="h-4 w-4 text-emerald-300" />
                        {{ $t('Add Group') }}
                    </button>
                    <button type="button" class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-bold text-white transition-all duration-300 hover:bg-white/10" :data-help-text="helpText.groupsColumn" data-help-icon @click="bulkActionModal = 'remove_group'">
                        <Minus class="h-4 w-4 text-emerald-300" />
                        {{ $t('Remove Group') }}
                    </button>
                    <button type="button" class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-bold text-white transition-all duration-300 hover:bg-white/10" :data-help-text="helpText.subscriptionColumn" data-help-icon @click="bulkActionModal = 'change_subscription_status'">
                        <Check class="h-4 w-4 text-amber-300" />
                        {{ $t('Change Subscription') }}
                    </button>

                    <button v-if="role !== 'agent'" type="button" class="inline-flex items-center gap-2 rounded-full bg-rose-500 px-4 py-2 text-sm font-bold text-white transition-all duration-300 hover:bg-rose-600" :data-help-text="$t('Delete the selected customers only after making sure you no longer need their data.')" data-help-icon @click="runBulkDelete">
                        <Trash2 class="h-4 w-4" />
                        {{ $t('Delete') }}
                    </button>
                    <button type="button" class="inline-flex items-center gap-2 rounded-full px-3 py-2 text-sm font-bold text-slate-200 transition-all duration-300 hover:bg-white/10" :data-help-text="$t('Export only the selected customers to an external file.')" data-help-icon @click="runExport">
                        <Upload class="h-4 w-4" />
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
        <div
            class="space-y-3 pt-4"
            @pointerover="showHelpTooltip"
            @pointerout="handleHelpPointerOut"
            @focusin="showHelpTooltip"
            @focusout="hideHelpTooltip"
            @click="showHelpTooltip"
        >
            <div class="rounded-lg border border-slate-200 p-3">
               <div class="space-y-3">
                    <div>
                        <div class="mb-1 flex items-center justify-between gap-2">
                            <label class="text-xs font-medium text-slate-500" :data-help-text="helpText.groupsColumn" data-help-icon>{{ $t('Group') }}</label>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-500">
                                {{ $t('Selected') }}: {{ query.group_ids.length }}
                            </span>
                        </div>
                        <div class="max-h-52 overflow-y-auto rounded-lg border border-slate-200 bg-white p-2" :aria-label="helpText.groupsColumn">
                            <label class="mb-1 flex cursor-pointer items-center gap-3 rounded-md px-2 py-2 text-sm text-slate-700 transition hover:bg-slate-50">
                                <input v-model="query.group_ids" type="checkbox" :value="NO_GROUP_OPTION" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                <span>{{ $t('No Group') }}</span>
                            </label>
                            <label
                                v-for="group in groups"
                                :key="`filter-group-${group.id}`"
                                class="mb-1 flex cursor-pointer items-center gap-3 rounded-md px-2 py-2 text-sm text-slate-700 transition last:mb-0 hover:bg-slate-50"
                            >
                                <input v-model="query.group_ids" type="checkbox" :value="String(group.id)" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                <span class="truncate">{{ group.name }}</span>
                            </label>
                        </div>
                    </div>
                    <div>
                        <div class="mb-1 flex items-center justify-between gap-2">
                            <label class="text-xs font-medium text-slate-500" :data-help-text="helpText.labelsColumn" data-help-icon>{{ $t('Label') }}</label>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-500">
                                {{ $t('Selected') }}: {{ query.label_ids.length }}
                            </span>
                        </div>
                        <div class="max-h-52 overflow-y-auto rounded-lg border border-slate-200 bg-white p-2" :aria-label="helpText.labelsColumn">
                            <label class="mb-1 flex cursor-pointer items-center gap-3 rounded-md px-2 py-2 text-sm text-slate-700 transition hover:bg-slate-50">
                                <input v-model="query.label_ids" type="checkbox" :value="NO_LABEL_OPTION" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                <span>{{ $t('No Label') }}</span>
                            </label>
                            <label
                                v-for="label in labels"
                                :key="`filter-label-${label.id}`"
                                class="mb-1 flex cursor-pointer items-center gap-3 rounded-md px-2 py-2 text-sm text-slate-700 transition last:mb-0 hover:bg-slate-50"
                            >
                                <input v-model="query.label_ids" type="checkbox" :value="String(label.id)" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                                <span class="truncate">{{ label.title }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200 p-3">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $t('Status') }}</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 text-xs font-medium text-slate-500" :data-help-text="helpText.subscriptionColumn" data-help-icon>{{ $t('Subscription Status') }}</label>
                        <div ref="subscriptionMenuRef" class="relative">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between gap-3 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 transition hover:border-slate-400 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                :aria-label="helpText.subscriptionColumn"
                                aria-haspopup="listbox"
                                :aria-expanded="isSubscriptionMenuOpen"
                                @click.stop="toggleSubscriptionMenu"
                            >
                                <span class="truncate">{{ $t(selectedSubscriptionStatusOption.label) }}</span>
                                <ChevronDown class="h-4 w-4 shrink-0 text-slate-500 transition" :class="{ 'rotate-180': isSubscriptionMenuOpen }" />
                            </button>
                            <div
                                v-if="isSubscriptionMenuOpen"
                                class="absolute inset-x-0 top-full z-50 mt-1 max-h-80 overflow-y-auto rounded-lg border border-slate-200 bg-white p-1 shadow-xl shadow-slate-200"
                                role="listbox"
                            >
                                <button
                                    v-for="status in subscriptionStatusOptions"
                                    :key="`subscription-status-${status.value || 'all'}`"
                                    type="button"
                                    class="w-full rounded-md px-3 py-2 text-start text-sm font-semibold transition hover:bg-slate-50"
                                    :class="query.subscription_status === status.value ? 'bg-primary/10 text-primary' : 'text-slate-700'"
                                    role="option"
                                    :aria-selected="query.subscription_status === status.value"
                                    @click.stop="selectSubscriptionStatus(status.value)"
                                >
                                    <span class="block truncate">{{ $t(status.label) }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 text-xs font-medium text-slate-500" :data-help-text="helpText.sourceColumn" data-help-icon>{{ $t('Contact Source') }}</label>
                        <div ref="sourceMenuRef" class="relative">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between gap-3 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 transition hover:border-slate-400 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                :aria-label="selectedContactSourceHelp"
                                aria-haspopup="listbox"
                                :aria-expanded="isSourceMenuOpen"
                                @click.stop="toggleSourceMenu"
                            >
                                <span class="truncate">{{ $t(selectedContactSourceOption.label) }}</span>
                                <ChevronDown class="h-4 w-4 shrink-0 text-slate-500 transition" :class="{ 'rotate-180': isSourceMenuOpen }" />
                            </button>
                            <div
                                v-if="isSourceMenuOpen"
                                class="absolute inset-x-0 top-full z-50 mt-1 max-h-80 overflow-y-auto rounded-lg border border-slate-200 bg-white p-1 shadow-xl shadow-slate-200"
                                role="listbox"
                            >
                                <div
                                    v-for="source in allContactSourceOptions"
                                    :key="`contact-source-${source.value || 'all'}`"
                                    class="flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 transition hover:bg-slate-50"
                                    :class="query.contact_source === source.value ? 'bg-primary/10 text-primary' : 'text-slate-700'"
                                    role="option"
                                    :aria-selected="query.contact_source === source.value"
                                >
                                    <button
                                        type="button"
                                        class="min-w-0 flex-1 text-start text-sm font-semibold"
                                        @click.stop="selectContactSource(source.value)"
                                    >
                                        <span class="block truncate">{{ $t(source.label) }}</span>
                                    </button>
                                    <button
                                        type="button"
                                        class="contact-source-help-button shrink-0 rounded-full p-1 text-slate-500 transition hover:bg-white hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-primary/20"
                                        :aria-label="source.description"
                                        :data-help-text="source.description"
                                        data-help-icon
                                    ></button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 text-xs font-medium text-slate-500" :data-help-text="helpText.blockedStatus" data-help-icon>{{ $t('Block Status') }}</label>
                        <div ref="blockedMenuRef" class="relative">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between gap-3 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 transition hover:border-slate-400 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                :aria-label="helpText.blockedStatus"
                                aria-haspopup="listbox"
                                :aria-expanded="isBlockedMenuOpen"
                                @click.stop="toggleBlockedMenu"
                            >
                                <span class="truncate">{{ $t(selectedBlockedStatusOption.label) }}</span>
                                <ChevronDown class="h-4 w-4 shrink-0 text-slate-500 transition" :class="{ 'rotate-180': isBlockedMenuOpen }" />
                            </button>
                            <div
                                v-if="isBlockedMenuOpen"
                                class="absolute inset-x-0 top-full z-50 mt-1 max-h-80 overflow-y-auto rounded-lg border border-slate-200 bg-white p-1 shadow-xl shadow-slate-200"
                                role="listbox"
                            >
                                <button
                                    v-for="status in blockedStatusOptions"
                                    :key="`blocked-status-${status.value || 'all'}`"
                                    type="button"
                                    class="w-full rounded-md px-3 py-2 text-start text-sm font-semibold transition hover:bg-slate-50"
                                    :class="query.blocked_status === status.value ? 'bg-primary/10 text-primary' : 'text-slate-700'"
                                    role="option"
                                    :aria-selected="query.blocked_status === status.value"
                                    @click.stop="selectBlockedStatus(status.value)"
                                >
                                    <span class="block truncate">{{ $t(status.label) }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200 p-3">
                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500" :data-help-text="helpText.dateAttributes" data-help-icon>{{ $t('Date & Attributes') }}</p>
                <p class="mb-3 text-[11px] leading-5 text-slate-500" :data-help-text="helpText.dateAttributes" data-help-icon>
                    {{ helpText.dateAttributes }}
                </p>
                <div class="grid gap-3">
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 text-xs font-medium text-slate-500" :data-help-text="$t('Choose the start of the date range to show customers added after it.')" data-help-icon>{{ $t('Created From') }}</label>
                            <input v-model="query.created_from" type="date" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" :aria-label="$t('Choose the start of the date range to show customers added after it.')">
                        </div>
                        <div>
                            <label class="mb-1 text-xs font-medium text-slate-500" :data-help-text="$t('Choose the end of the date range to show customers added before it.')" data-help-icon>{{ $t('Created To') }}</label>
                            <input v-model="query.created_to" type="date" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" :aria-label="$t('Choose the end of the date range to show customers added before it.')">
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 text-xs font-medium text-slate-500" :data-help-text="$t('Choose a custom piece of customer data such as stage, language, or any field you added.')" data-help-icon>{{ $t('Custom Attribute') }}</label>
                        <select v-model="query.custom_attribute_key" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" :aria-label="$t('Choose a custom piece of customer data such as stage, language, or any field you added.')">
                            <option value="">{{ $t('Select Attribute') }}</option>
                            <option v-for="attribute in availableCustomAttributes" :key="attribute.value" :value="attribute.value">{{ attribute.label }}</option>
                        </select>
                    </div>
                    <input v-model="query.custom_attribute_value" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" :placeholder="$t('Contains value')" :aria-label="$t('Type part of the value you want to search for inside the custom field.')">
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
                    <div class="flex flex-wrap items-center gap-3 text-xs text-slate-600">
                        <span>{{ $t('Selected filters') }}: {{ query.group_ids.length }}</span>
                        <span>{{ $t('Selected for deletion') }}: {{ selectedGroupDeletionCount }}</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" class="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs text-slate-700 disabled:cursor-not-allowed disabled:opacity-60" :disabled="isDeletingGroups" @click="selectAllGroupsForDeletion">
                            {{ $t('Select All for deletion') }}
                        </button>
                        <button type="button" class="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs text-slate-700 disabled:cursor-not-allowed disabled:opacity-60" :disabled="isDeletingGroups || !selectedGroupDeletionCount" @click="clearGroupDeletionSelection">
                            {{ $t('Clear deletion') }}
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
                    :class="isGroupSelectedForFilter(group.id) ? 'border-primary bg-primary/5 text-primary' : 'border-slate-300 text-slate-700'"
                >
                    <input
                        type="checkbox"
                        class="h-4 w-4 shrink-0 rounded border-slate-300 text-primary focus:ring-primary"
                        :checked="isGroupSelectedForFilter(group.id)"
                        :aria-label="`${$t('Filter by')} ${group.name}`"
                        @change="toggleGroupFilterSelection(group.id)"
                    >
                    <button type="button" class="min-w-0 flex-1 text-left rtl:text-right" @click="toggleGroupFilterSelection(group.id)">
                        <span class="truncate">{{ group.name }}</span>
                        <span v-if="isGroupSelectedForFilter(group.id)" class="ml-2 text-xs rtl:ml-0 rtl:mr-2">{{ $t('Selected') }}</span>
                    </button>
                    <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                        {{ groupContactsCount(group) }}
                    </span>
                    <label class="inline-flex shrink-0 items-center gap-1 rounded-md border border-rose-100 bg-white px-2 py-1 text-[11px] text-rose-600">
                        <input
                            type="checkbox"
                            class="h-3.5 w-3.5 rounded border-rose-200 text-rose-500 focus:ring-rose-500"
                            :checked="isGroupSelectedForDeletion(group.uuid)"
                            :aria-label="`${$t('Select for deletion')} ${group.name}`"
                            @change="toggleGroupDeletionSelection(group.uuid)"
                        >
                        <span>{{ $t('For deletion') }}</span>
                    </label>
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
                <div class="flex items-center justify-between gap-2">
                    <label class="text-xs font-medium text-slate-500">{{ $t('Select Groups') }}</label>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-500">
                        {{ $t('Selected') }}: {{ bulkForm.group_ids.length }}
                    </span>
                </div>
                <div class="max-h-64 overflow-y-auto rounded-lg border border-slate-200 bg-white p-2">
                    <label
                        v-for="group in groups"
                        :key="`bulk-group-${group.id}`"
                        class="mb-1 flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2 text-sm transition last:mb-0"
                        :class="bulkForm.group_ids.includes(String(group.id)) ? 'border-blue-200 bg-blue-50 text-blue-700' : 'border-transparent text-slate-700 hover:bg-slate-50'"
                    >
                        <input v-model="bulkForm.group_ids" type="checkbox" :value="String(group.id)" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span class="truncate">{{ group.name }}</span>
                    </label>
                    <p v-if="!groups.length" class="px-3 py-4 text-center text-sm font-medium text-slate-400">
                        {{ $t('No groups available') }}
                    </p>
                </div>
            </template>

            <template v-if="bulkActionModal === 'add_label' || bulkActionModal === 'remove_label'">
                <div class="flex items-center justify-between gap-2">
                    <label class="text-xs font-medium text-slate-500">{{ $t('Select Labels') }}</label>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-500">
                        {{ $t('Selected') }}: {{ bulkForm.label_ids.length }}
                    </span>
                </div>
                <div class="max-h-64 overflow-y-auto rounded-lg border border-slate-200 bg-white p-2">
                    <label
                        v-for="label in labels"
                        :key="`bulk-label-${label.id}`"
                        class="mb-1 flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2 text-sm transition last:mb-0"
                        :class="bulkForm.label_ids.includes(String(label.id)) ? 'border-blue-200 bg-blue-50 text-blue-700' : 'border-transparent text-slate-700 hover:bg-slate-50'"
                    >
                        <input v-model="bulkForm.label_ids" type="checkbox" :value="String(label.id)" class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                        <span class="truncate">{{ label.title }}</span>
                    </label>
                    <p v-if="!labels.length" class="px-3 py-4 text-center text-sm font-medium text-slate-400">
                        {{ $t('No labels available') }}
                    </p>
                </div>
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
        :countries="contactConstants.countryDialCodes"
        @completed="onImportCompleted"
    />

    <Teleport to="body">
        <div
            v-if="helpTooltip.visible"
            class="contact-management-help-tooltip"
            :class="`is-${helpTooltip.placement}`"
            :style="{ left: `${helpTooltip.left}px`, top: `${helpTooltip.top}px` }"
            role="tooltip"
        >
            {{ helpTooltip.text }}
        </div>
    </Teleport>
</template>

<style>
.help-tooltip {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #94a3b8;
    cursor: help;
    vertical-align: middle;
}

.help-tooltip:hover,
.help-tooltip:focus {
    color: #0f172a;
    outline: none;
}

.help-tooltip-content {
    position: absolute;
    inset-inline-start: 50%;
    bottom: calc(100% + 10px);
    z-index: 60;
    width: min(280px, calc(100vw - 32px));
    transform: translateX(-50%);
    border-radius: 14px;
    background: #0f172a;
    padding: 10px 12px;
    color: #ffffff;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.55;
    opacity: 0;
    pointer-events: none;
    text-align: start;
    transition: opacity 160ms ease, transform 160ms ease;
    white-space: normal;
}

.help-tooltip:hover .help-tooltip-content,
.help-tooltip:focus .help-tooltip-content {
    opacity: 1;
    transform: translateX(-50%) translateY(-2px);
}

[data-help-icon] {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

[data-help-icon]::before {
    content: none !important;
    display: none !important;
}

[data-help-icon]::after {
    content: '?';
    display: inline-flex;
    width: 0.95rem;
    height: 0.95rem;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    border: 1.5px solid #94a3b8;
    border-radius: 9999px;
    color: #64748b;
    font-size: 0.62rem;
    font-weight: 800;
    line-height: 1;
}

[data-help-icon]:hover::after,
[data-help-icon]:focus-visible::after {
    border-color: #0f172a;
    color: #0f172a;
}

[data-help-icon]:hover::before,
[data-help-icon]:focus-visible::before {
    display: none !important;
}

.contact-management-help-tooltip {
    position: fixed;
    z-index: 9999;
    width: min(340px, calc(100vw - 32px));
    max-height: min(260px, calc(100vh - 32px));
    overflow-y: auto;
    border-radius: 12px;
    background: #0f172a;
    padding: 10px 12px;
    color: #ffffff;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.55;
    text-align: start;
    white-space: normal;
    word-break: normal;
    overflow-wrap: anywhere;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.22);
    pointer-events: none;
}

.contact-management-help-tooltip.is-top {
    transform: translateY(-100%);
}

.contact-management-help-tooltip.is-bottom {
    transform: none;
}
</style>
