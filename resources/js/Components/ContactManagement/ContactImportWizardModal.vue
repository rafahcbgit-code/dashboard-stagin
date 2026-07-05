<script setup>
import { computed, onUnmounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';
import fallbackCountryDialCodes from '@/Data/countryDialCodes.json';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    contactGroups: { type: Array, default: () => [] },
    fields: { type: Array, default: () => [] },
    countries: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue', 'completed']);
const { t } = useI18n();

const wizardStep = ref(1);
const busy = ref(false);
const uploadBusy = ref(false);
const errorMessage = ref('');
const warningMessage = ref('');
const successMessage = ref('');

const fileName = ref('');
const batch = ref(null);
const rows = ref([]);
const rowsMeta = reactive({ current_page: 1, last_page: 1, per_page: 10, total: 0 });
const invalidRowsPreview = ref([]);
const invalidRowsCount = ref(0);
const invalidRowsPreviewLimited = ref(false);
const existingGroupedContacts = reactive({
    count: 0,
    sample_phones: [],
});

const mapping = reactive({
    first_name: '',
    last_name: '',
    phone: '',
    email: '',
    group_name: '',
    street: '',
    city: '',
    state: '',
    zip: '',
    country: '',
    custom_attributes: {},
});

const defaultCountryCode = ref('');
const normalizationApplied = ref(false);
const groupForm = reactive({
    assignment_mode: 'all_selected',
    group_ids: [],
    new_group_name: '',
    split_group_size: 100,
    split_group_prefix: 'audience',
});
const groupSearch = ref('');
const audienceHelp = computed(() => ({
    step: t('In this step, choose which audience imported customers should be added to, so you do not send generic messages to all customers without segmentation.'),
    allSelected: t('Choose this option if you want to place all imported customers inside one or more audiences.'),
    split: t('Choose this option if the file is large and you want to automatically split customers into smaller audiences.'),
    fromFile: t('Use this option when your file already has a group_name column. Each contact will be added to the audience name written in its row.'),
    search: t('Search for an existing audience to use instead of creating a new one.'),
    visible: t('The number of audiences visible after the current search.'),
    selected: t('The number of audiences you selected to add customers to.'),
    chooseAudience: t('Choose the right audience based on the campaign goal or customer type.'),
    newAudience: t('Create a new audience if you cannot find a suitable classification for the imported customers.'),
    contactsPerAudience: t('Set how many customers should be placed in each new audience during automatic splitting.'),
    prefix: t('Type the beginning of the audience name. Sequential names such as audience_1 and audience_2 will be created.'),
    save: t('Save the customer-to-audience assignment before running the final import.'),
}));

const rowEditor = reactive({
    id: null,
    first_name: '',
    last_name: '',
    phone: '',
    email: '',
    group_name: '',
    street: '',
    city: '',
    state: '',
    zip: '',
    country: '',
    custom_attributes: {},
});
const isEditOpen = ref(false);
const editErrorMessage = ref('');
const isSavingEdit = ref(false);
const showAdvancedMapping = ref(false);
const statusPoller = ref(null);
const progressAnimator = ref(null);
const displayedProcessedRows = ref(0);

const hasBatch = computed(() => !!batch.value?.uuid);
const batchHeaders = computed(() => batch.value?.headers || []);
const batchStats = computed(() => batch.value?.stats || {});
const rawReport = computed(() => batch.value?.report || null);
const isImportCompleted = computed(() => batch.value?.status === 'committed' || Boolean(rawReport.value?.executed_at));
const report = computed(() => (isImportCompleted.value ? rawReport.value : null));
const backgroundImport = computed(() => batch.value?.mode === 'background');
const backgroundInProgress = computed(() => backgroundImport.value && ['queued', 'processing'].includes(batch.value?.status));
const backgroundDuplicatesCount = computed(() => Number(batch.value?.duplicates_count ?? rawReport.value?.duplicates_count ?? 0));
const backgroundImportedContactsCount = computed(() => Number(
    batch.value?.imported_contacts_count
    ?? batchStats.value?.imported
    ?? ((Number(batch.value?.created_count || 0) + Number(batch.value?.updated_count || 0)))
));
const backgroundTotalRows = computed(() => Number(batch.value?.total_rows || batchStats.value?.total || 0));
const backgroundTargetProcessedRows = computed(() => {
    const processedRows = Number(batch.value?.processed_rows || 0);

    if (backgroundInProgress.value && backgroundTotalRows.value && processedRows >= backgroundTotalRows.value) {
        return Math.max(0, backgroundTotalRows.value - 1);
    }

    return processedRows;
});
const backgroundDisplayedRows = computed(() => Math.min(
    backgroundTotalRows.value,
    Math.round(displayedProcessedRows.value)
));
const backgroundProgressPercent = computed(() => {
    if (!backgroundTotalRows.value) {
        return 0;
    }

    const percent = Math.min(100, Math.round((backgroundDisplayedRows.value / backgroundTotalRows.value) * 100));

    return backgroundInProgress.value ? Math.min(99, percent) : percent;
});
const backgroundPhaseLabel = computed(() => {
    const phase = batch.value?.progress_phase || batch.value?.report?.phase || batch.value?.status || 'queued';
    const labels = {
        queued: 'Queued',
        processing: 'Processing',
        reading_file: 'Reading file',
        saving_invalid_rows: 'Saving invalid rows',
        saving_contacts: 'Saving contacts',
        linking_audiences: 'Linking audiences',
        refreshing_audience_counts: 'Refreshing audience counts',
        completed: 'Completed',
        committed: 'Completed',
        failed: 'Failed',
    };

    return t(labels[phase] || phase);
});
const invalidRowsReportUrl = computed(() => (batch.value?.uuid ? `/contacts/import/${batch.value.uuid}/invalid-report.csv` : '#'));
const requiresDefaultCountryCode = computed(() => (
    Boolean(batch.value?.requires_default_country_code) || previewRowsNeedDefaultCountryCode.value
) && !normalizationApplied.value);
const previewRowsNeedDefaultCountryCode = computed(() => rows.value.some((row) => rowNeedsDefaultCountryCode(row)));
const countryCodeOptions = computed(() => {
    const options = Array.isArray(props.countries) && props.countries.length > 0
        ? props.countries
        : fallbackCountryDialCodes;

    return options
        .map((country) => {
            const dialCode = country?.dial_code || (country?.value ? `+${String(country.value).replace(/^\+/, '')}` : '');
            return {
                value: dialCode,
                label: country?.label || `${country?.country || country?.name || dialCode} (${dialCode})`,
            };
        })
        .filter((country) => country.value);
});

onUnmounted(() => {
    stopStatusPolling();
    stopProgressAnimation();
});

watch(
    () => props.modelValue,
    (isOpen) => {
        if (!isOpen) {
            resetWizard();
        }
    }
);

watch(
    () => props.fields,
    (fields) => {
        fields.forEach((field) => {
            if (typeof mapping.custom_attributes[field.name] === 'undefined') {
                mapping.custom_attributes[field.name] = '';
            }
        });
    },
    { immediate: true }
);

const closeModal = () => {
    emit('update:modelValue', false);
};

const resetWizard = () => {
    stopStatusPolling();
    stopProgressAnimation();
    wizardStep.value = 1;
    busy.value = false;
    uploadBusy.value = false;
    errorMessage.value = '';
    warningMessage.value = '';
    successMessage.value = '';
    fileName.value = '';
    batch.value = null;
    rows.value = [];
    invalidRowsPreview.value = [];
    invalidRowsCount.value = 0;
    invalidRowsPreviewLimited.value = false;
    existingGroupedContacts.count = 0;
    existingGroupedContacts.sample_phones = [];
    rowsMeta.current_page = 1;
    rowsMeta.last_page = 1;
    rowsMeta.per_page = 10;
    rowsMeta.total = 0;
    defaultCountryCode.value = '';
    normalizationApplied.value = false;
    groupForm.group_ids = [];
    groupForm.new_group_name = '';
    groupForm.assignment_mode = 'all_selected';
    groupForm.split_group_size = 100;
    groupForm.split_group_prefix = 'audience';
    groupSearch.value = '';
    showAdvancedMapping.value = false;
    displayedProcessedRows.value = 0;

    Object.assign(mapping, {
        first_name: '',
        last_name: '',
        phone: '',
        email: '',
        group_name: '',
        street: '',
        city: '',
        state: '',
        zip: '',
        country: '',
        custom_attributes: Object.fromEntries(props.fields.map((field) => [field.name, ''])),
    });
};

const stopProgressAnimation = () => {
    if (progressAnimator.value) {
        window.cancelAnimationFrame(progressAnimator.value);
        progressAnimator.value = null;
    }
};

const animateDisplayedProgress = (targetRows, totalRows) => {
    stopProgressAnimation();

    targetRows = Math.max(0, Math.min(Number(targetRows || 0), Number(totalRows || targetRows || 0)));

    if (!totalRows || targetRows < displayedProcessedRows.value) {
        displayedProcessedRows.value = targetRows;
        return;
    }

    const startRows = displayedProcessedRows.value;
    const deltaRows = targetRows - startRows;

    if (deltaRows <= 0) {
        displayedProcessedRows.value = targetRows;
        return;
    }

    const ratio = totalRows > 0 ? deltaRows / totalRows : 1;
    const duration = 700 + Math.min(2600, ratio * 2600);
    const startedAt = window.performance.now();

    const tick = (now) => {
        const progress = Math.min(1, (now - startedAt) / duration);
        const eased = 1 - Math.pow(1 - progress, 3);

        displayedProcessedRows.value = startRows + (deltaRows * eased);

        if (progress < 1) {
            progressAnimator.value = window.requestAnimationFrame(tick);
            return;
        }

        displayedProcessedRows.value = targetRows;
        progressAnimator.value = null;
    };

    progressAnimator.value = window.requestAnimationFrame(tick);
};

watch(
    () => [
        batch.value?.uuid,
        backgroundTargetProcessedRows.value,
        Number(batch.value?.total_rows || batchStats.value?.total || 0),
    ],
    ([uuid, processedRows, totalRows], [previousUuid] = []) => {
        if (!uuid) {
            displayedProcessedRows.value = 0;
            return;
        }

        if (uuid !== previousUuid) {
            displayedProcessedRows.value = 0;
        }

        animateDisplayedProgress(processedRows, totalRows);
    }
);

const pickHeader = (preferredKeys) => {
    const headers = batchHeaders.value;
    const lowered = headers.map((header) => String(header).toLowerCase());

    for (const key of preferredKeys) {
        const index = lowered.findIndex((header) => header === key || header.includes(key));
        if (index !== -1) {
            return headers[index];
        }
    }

    return '';
};

const setDefaultMapping = () => {
    mapping.first_name = pickHeader(['first_name', 'first name', 'name']);
    mapping.last_name = pickHeader(['last_name', 'last name']);
    mapping.phone = pickHeader(['phone', 'mobile', 'whatsapp']);
    mapping.email = pickHeader(['email', 'mail']);
    mapping.group_name = pickHeader(['group_name', 'group name', 'group']);
    mapping.street = pickHeader(['street']);
    mapping.city = pickHeader(['city']);
    mapping.state = pickHeader(['state']);
    mapping.zip = pickHeader(['zip', 'postal']);
    mapping.country = pickHeader(['country']);

    Object.keys(mapping.custom_attributes || {}).forEach((customFieldName) => {
        mapping.custom_attributes[customFieldName] = '';
    });
};

const uploadAndPreview = async (event) => {
    const file = event?.target?.files?.[0];
    if (!file) {
        return;
    }

    errorMessage.value = '';
    warningMessage.value = '';
    successMessage.value = '';
    busy.value = true;
    uploadBusy.value = true;
    fileName.value = file.name;

    try {
        const formData = new FormData();
        formData.append('file', file);

        const { data } = await axios.post('/contacts/import/preview', formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
                Accept: 'application/json',
            },
        });

        if (data?.background_import) {
            batch.value = data.batch;
            applyRowsPayload({ data: [], current_page: 1, last_page: 1, per_page: 10, total: 0 });
            successMessage.value = data.message
                ? t(data.message)
                : t('This import will continue in the background.');
            wizardStep.value = 5;
            startStatusPolling();
            return;
        }

        batch.value = data.batch;
        if (data.rows) {
            applyRowsPayload(data.rows);
        } else {
            applyRowsPayload({ data: [], current_page: 1, last_page: 1, per_page: 10, total: 0 });
        }
        setDefaultMapping();
        wizardStep.value = 2;
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || t('Failed to parse file.');
    } finally {
        busy.value = false;
        uploadBusy.value = false;
    }
};

const startStatusPolling = () => {
    stopStatusPolling();
    pollImportStatus();
    statusPoller.value = window.setInterval(pollImportStatus, 1000);
};

const stopStatusPolling = () => {
    if (statusPoller.value) {
        window.clearInterval(statusPoller.value);
        statusPoller.value = null;
    }
};

const pollImportStatus = async () => {
    if (!batch.value?.uuid || !backgroundImport.value) {
        stopStatusPolling();
        return;
    }

    try {
        const { data } = await axios.get(`/contacts/import/${batch.value.uuid}/status`, {
            headers: { Accept: 'application/json' },
        });

        if (data?.batch) {
            batch.value = data.batch;
        }

        if (['committed', 'failed'].includes(batch.value?.status)) {
            stopStatusPolling();
            if (batch.value?.status === 'committed') {
                successMessage.value = t('Background import finished successfully.');
                emit('completed');
            } else if (batch.value?.error_message) {
                errorMessage.value = batch.value.error_message;
            }
        }
    } catch (error) {
        warningMessage.value = error?.response?.data?.message || t('Unable to refresh import status.');
    }
};

const applyRowsPayload = (payload) => {
    rows.value = payload?.data || [];
    rowsMeta.current_page = payload?.current_page || 1;
    rowsMeta.last_page = payload?.last_page || 1;
    rowsMeta.per_page = payload?.per_page || 10;
    rowsMeta.total = payload?.total || 0;
};

const mapColumns = async () => {
    if (!hasBatch.value) {
        return;
    }

    if (!mapping.phone) {
        errorMessage.value = t('Phone mapping is required.');
        return;
    }

    busy.value = true;
    errorMessage.value = '';
    warningMessage.value = '';

    try {
        const { data } = await axios.post(`/contacts/import/${batch.value.uuid}/mapping`, { mapping });
        batch.value = data.batch;
        applyRowsPayload(data.rows);
        wizardStep.value = 3;
        normalizationApplied.value = false;
        if (requiresDefaultCountryCode.value) {
            warningMessage.value = t('Local phone numbers were detected. Choose a default country code before checking numbers.');
        } else {
            await normalizeRows();
        }
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || t('Failed to save mapping.');
    } finally {
        busy.value = false;
    }
};

const normalizeRows = async () => {
    if (!hasBatch.value) {
        return;
    }

    busy.value = true;
    errorMessage.value = '';
    warningMessage.value = '';

    try {
        const { data } = await axios.post(`/contacts/import/${batch.value.uuid}/normalize`, {
            default_country_code: defaultCountryCode.value || '',
        });
        batch.value = data.batch;
        batch.value.requires_default_country_code = false;
        normalizationApplied.value = true;
        invalidRowsCount.value = Number(data?.invalid_preview?.count || 0);
        invalidRowsPreview.value = Array.isArray(data?.invalid_preview?.rows) ? data.invalid_preview.rows : [];
        invalidRowsPreviewLimited.value = Boolean(data?.invalid_preview?.limited);
        existingGroupedContacts.count = Number(data?.existing_grouped_contacts?.count || 0);
        existingGroupedContacts.sample_phones = Array.isArray(data?.existing_grouped_contacts?.sample_phones)
            ? data.existing_grouped_contacts.sample_phones
            : [];
        successMessage.value = invalidRowsCount.value > 0
            ? t('Numbers checked. Invalid rows were excluded from import and shown below.')
            : t('Numbers checked. No invalid rows found.');
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || t('Failed to normalize rows.');
    } finally {
        busy.value = false;
    }
};

const rowNeedsDefaultCountryCode = (row) => {
    const phoneColumn = mapping.phone;
    const rawData = row?.raw_data || {};
    const mappedData = row?.mapped_data || {};
    const rawPhone = phoneColumn && Object.prototype.hasOwnProperty.call(rawData, phoneColumn)
        ? rawData[phoneColumn]
        : mappedData.phone;

    let phone = String(rawPhone ?? '').trim();
    if (phone === '') {
        return false;
    }

    phone = phone.replace(/^[=\s]+/, '').replace(/[^\d+]/g, '');
    if (phone === '' || phone.startsWith('+') || phone.startsWith('00')) {
        return false;
    }

    return /^\d{4,9}$/.test(phone);
};

const openEdit = (row) => {
    editErrorMessage.value = '';
    const mapped = row.mapped_data || {};
    rowEditor.id = row.id;
    rowEditor.first_name = mapped.first_name || '';
    rowEditor.last_name = mapped.last_name || '';
    rowEditor.phone = mapped.phone || '';
    rowEditor.email = mapped.email || '';
    rowEditor.group_name = mapped.group_name || '';
    rowEditor.street = mapped.street || '';
    rowEditor.city = mapped.city || '';
    rowEditor.state = mapped.state || '';
    rowEditor.zip = mapped.zip || '';
    rowEditor.country = mapped.country || '';
    rowEditor.custom_attributes = { ...(mapped.custom_attributes || {}) };
    isEditOpen.value = true;
};

const saveEdit = async () => {
    if (!rowEditor.id) {
        editErrorMessage.value = t('Unable to edit this row (missing row id). Please reopen the row.');
        return;
    }

    if (!hasBatch.value) {
        editErrorMessage.value = t('Import batch is not ready. Please reopen the import wizard.');
        return;
    }

    busy.value = true;
    isSavingEdit.value = true;
    errorMessage.value = '';
    editErrorMessage.value = '';

    try {
        await axios.post(`/contacts/import/${batch.value.uuid}/rows/${rowEditor.id}`, {
            _method: 'PATCH',
            first_name: rowEditor.first_name,
            last_name: rowEditor.last_name,
            phone: rowEditor.phone,
            email: rowEditor.email,
            group_name: rowEditor.group_name,
            street: rowEditor.street,
            city: rowEditor.city,
            state: rowEditor.state,
            zip: rowEditor.zip,
            country: rowEditor.country,
            custom_attributes: rowEditor.custom_attributes,
        });
        isEditOpen.value = false;
        await normalizeRows();
    } catch (error) {
        const errors = error?.response?.data?.errors;
        const firstError = errors && typeof errors === 'object'
            ? Object.values(errors).flat()[0]
            : null;
        const message = firstError || error?.response?.data?.message || t('Failed to update row.');
        editErrorMessage.value = message;
        errorMessage.value = message;
    } finally {
        busy.value = false;
        isSavingEdit.value = false;
    }
};


const saveGroupAssignment = async () => {
    if (!hasBatch.value) {
        return;
    }

    busy.value = true;
    errorMessage.value = '';
    warningMessage.value = '';

    try {
        const { data } = await axios.post(`/contacts/import/${batch.value.uuid}/group-assignment`, {
            group_ids: groupForm.group_ids,
            new_group_name: groupForm.new_group_name,
            assignment_mode: groupForm.assignment_mode,
            split_group_size: groupForm.assignment_mode === 'split' ? Number(groupForm.split_group_size) : null,
            split_group_prefix: groupForm.assignment_mode === 'split' ? groupForm.split_group_prefix : null,
        });
        batch.value = data.batch;
        applyRowsPayload(data.rows);
        wizardStep.value = 5;
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || t('Failed to assign audience.');
    } finally {
        busy.value = false;
    }
};

const executeImport = async () => {
    if (!hasBatch.value) {
        return;
    }

    busy.value = true;
    errorMessage.value = '';
    warningMessage.value = '';
    successMessage.value = '';

    try {
        const { data } = await axios.post(`/contacts/import/${batch.value.uuid}/commit`);
        // Controller returns nested payload:
        // { report: {...}, batch: { batch: {...}, rows: {...} } }
        if (data?.batch?.batch) {
            batch.value = data.batch.batch;
            applyRowsPayload(data.batch.rows || { data: [], current_page: 1, last_page: 1, per_page: rowsMeta.per_page, total: 0 });
        } else {
            batch.value = data?.batch || batch.value;
        }
        successMessage.value = t('Import finished successfully.');
        emit('completed');
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || t('Failed to import contacts.');
    } finally {
        busy.value = false;
    }
};

const canContinueToGroups = computed(() => normalizationApplied.value);
const hasGroupNameMapping = computed(() => String(mapping.group_name || '').trim() !== '');
const missingDefaultCountryCodeCount = computed(() => Number(batch.value?.missing_default_country_code_count || 0));
const existingGroupedContactsCount = computed(() => Number(existingGroupedContacts.count || 0));
const hasExistingGroupedContacts = computed(() => existingGroupedContactsCount.value > 0);
const displayedInvalidRowsCount = computed(() => Math.max(
    Number(invalidRowsCount.value || 0),
    Number(batchStats.value?.invalid || 0)
));
const hasInvalidRows = computed(() => displayedInvalidRowsCount.value > 0);
const filteredContactGroups = computed(() => {
    const keyword = String(groupSearch.value || '').trim().toLowerCase();
    if (keyword === '') {
        return props.contactGroups;
    }

    return (props.contactGroups || []).filter((group) =>
        String(group?.name || '').toLowerCase().includes(keyword)
    );
});
const selectedGroupsCount = computed(() => (groupForm.group_ids || []).length);
const groupAssignmentIsInvalid = computed(() => (
    (groupForm.assignment_mode === 'split' && (!groupForm.split_group_size || groupForm.split_group_size < 1 || !String(groupForm.split_group_prefix || '').trim()))
    || (groupForm.assignment_mode === 'file_group_name' && !hasGroupNameMapping.value)
));
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

const goToPreviousStep = () => {
    if (busy.value || uploadBusy.value) {
        return;
    }

    wizardStep.value = Math.max(1, wizardStep.value - 1);
};
</script>

<template>
    <Modal :label="$t('Import Contacts Wizard')" :isOpen="modelValue" :closeBtn="true" @close="closeModal">
        <div
            class="contact-import-wizard space-y-4 pt-4"
            @pointerover="showHelpTooltip"
            @pointerout="handleHelpPointerOut"
            @focusin="showHelpTooltip"
            @focusout="hideHelpTooltip"
            @click="showHelpTooltip"
        >
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="rounded-full px-2 py-1" :class="wizardStep >= 1 ? 'bg-primary text-white' : 'bg-slate-100 text-slate-600'">{{ $t('1. Upload') }}</span>
                <span class="rounded-full px-2 py-1" :class="wizardStep >= 2 ? 'bg-primary text-white' : 'bg-slate-100 text-slate-600'">{{ $t('2. Mapping') }}</span>
                <span class="rounded-full px-2 py-1" :class="wizardStep >= 3 ? 'bg-primary text-white' : 'bg-slate-100 text-slate-600'">{{ $t('3. Review Invalid Numbers') }}</span>
                <span class="rounded-full px-2 py-1" :class="wizardStep >= 4 ? 'bg-primary text-white' : 'bg-slate-100 text-slate-600'">{{ $t('4. audience') }}</span>
                <span class="rounded-full px-2 py-1" :class="wizardStep >= 5 ? 'bg-primary text-white' : 'bg-slate-100 text-slate-600'">{{ $t('5. Import Report') }}</span>
            </div>

            <div v-if="errorMessage" class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ errorMessage }}</div>
            <div v-if="warningMessage" class="rounded-md border border-orange-200 bg-orange-50 px-3 py-2 text-sm font-medium text-orange-800">{{ warningMessage }}</div>
            <div v-if="successMessage" class="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ successMessage }}</div>

            <div v-if="wizardStep === 1" class="space-y-3">
                <p class="text-sm text-slate-600">{{ $t('Upload CSV/XLSX file to start import preview.') }}</p>
                <input type="file" accept=".csv,.xlsx" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:cursor-not-allowed disabled:bg-slate-100" :disabled="uploadBusy" @change="uploadAndPreview">
                <p v-if="fileName" class="text-xs text-slate-500">{{ $t('File') }}: {{ fileName }}</p>
                <div v-if="uploadBusy" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-3">
                    <div class="flex items-center gap-2 text-sm text-slate-700">
                        <svg class="h-4 w-4 animate-spin text-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4z"></path>
                        </svg>
                        <span>{{ $t('Uploading and processing file... You will be moved automatically to Mapping step.') }}</span>
                    </div>
                </div>
            </div>

            <div v-if="wizardStep === 2" class="space-y-3">
                <p class="text-sm text-slate-600">{{ $t('Field mapping is required before processing the file.') }}</p>
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                    <label class="text-xs text-slate-500">{{ $t('First Name') }}
                        <select v-model="mapping.first_name" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                            <option value="">-</option>
                            <option v-for="header in batchHeaders" :key="`first_name-${header}`" :value="header">{{ header }}</option>
                        </select>
                    </label>
                    <label class="text-xs text-slate-500">{{ $t('Phone') }} *
                        <select v-model="mapping.phone" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                            <option value="">{{ $t('Select phone column') }}</option>
                            <option v-for="header in batchHeaders" :key="`phone-${header}`" :value="header">{{ header }}</option>
                        </select>
                    </label>
                </div>

                <div class="rounded-md border border-slate-200">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between px-3 py-2 text-sm font-medium text-slate-700"
                        @click="showAdvancedMapping = !showAdvancedMapping"
                    >
                        <span>{{ $t('Advanced Mapping Fields') }}</span>
                        <span>{{ showAdvancedMapping ? $t('Hide') : $t('Show') }}</span>
                    </button>
                    <div v-if="showAdvancedMapping" class="space-y-3 border-t border-slate-200 p-3">
                        <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                            <label class="text-xs text-slate-500">{{ $t('Last Name') }}
                                <select v-model="mapping.last_name" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                                    <option value="">-</option>
                                    <option v-for="header in batchHeaders" :key="`last_name-${header}`" :value="header">{{ header }}</option>
                                </select>
                            </label>
                            <label class="text-xs text-slate-500">{{ $t('Email') }}
                                <select v-model="mapping.email" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                                    <option value="">-</option>
                                    <option v-for="header in batchHeaders" :key="`email-${header}`" :value="header">{{ header }}</option>
                                </select>
                            </label>
                            <label class="text-xs text-slate-500">{{ $t('audience Name') }}
                                <select v-model="mapping.group_name" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                                    <option value="">-</option>
                                    <option v-for="header in batchHeaders" :key="`group-${header}`" :value="header">{{ header }}</option>
                                </select>
                            </label>
                            <label class="text-xs text-slate-500">{{ $t('Country') }}
                                <select v-model="mapping.country" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                                    <option value="">-</option>
                                    <option v-for="header in batchHeaders" :key="`country-${header}`" :value="header">{{ header }}</option>
                                </select>
                            </label>
                            <label class="text-xs text-slate-500">{{ $t('Street') }}
                                <select v-model="mapping.street" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                                    <option value="">-</option>
                                    <option v-for="header in batchHeaders" :key="`street-${header}`" :value="header">{{ header }}</option>
                                </select>
                            </label>
                            <label class="text-xs text-slate-500">{{ $t('City') }}
                                <select v-model="mapping.city" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                                    <option value="">-</option>
                                    <option v-for="header in batchHeaders" :key="`city-${header}`" :value="header">{{ header }}</option>
                                </select>
                            </label>
                            <label class="text-xs text-slate-500">{{ $t('State') }}
                                <select v-model="mapping.state" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                                    <option value="">-</option>
                                    <option v-for="header in batchHeaders" :key="`state-${header}`" :value="header">{{ header }}</option>
                                </select>
                            </label>
                            <label class="text-xs text-slate-500">{{ $t('Zip') }}
                                <select v-model="mapping.zip" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                                    <option value="">-</option>
                                    <option v-for="header in batchHeaders" :key="`zip-${header}`" :value="header">{{ header }}</option>
                                </select>
                            </label>
                        </div>

                        <div class="rounded-md border border-slate-200 p-3">
                            <p class="mb-2 text-sm font-medium text-slate-700">{{ $t('Custom Attributes Mapping') }}</p>
                            <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                                <label v-for="field in fields" :key="`custom-${field.id}`" class="text-xs text-slate-500">{{ field.name }}
                                    <select v-model="mapping.custom_attributes[field.name]" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                                        <option value="">-</option>
                                        <option v-for="header in batchHeaders" :key="`${field.name}-${header}`" :value="header">{{ header }}</option>
                                    </select>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end">
                    <div class="flex items-center gap-2">
                        <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700 disabled:opacity-60" :disabled="busy || uploadBusy" @click="goToPreviousStep">
                            {{ $t('Previous') }}
                        </button>
                        <button type="button" class="rounded-md bg-primary px-4 py-2 text-sm text-white disabled:opacity-60" :disabled="busy" @click="mapColumns">
                        {{ busy ? $t('Saving...') : $t('Continue') }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="wizardStep === 3" class="space-y-4">
                <div v-if="requiresDefaultCountryCode" class="rounded-xl border border-amber-200 bg-amber-50 p-3">
                    <p class="text-sm font-semibold text-amber-900">{{ $t('Default country code required') }}</p>
                    <p class="mt-1 text-xs text-amber-800">
                        {{ $t('Some phone numbers do not include a country code. Choose the default country code that should be applied only to those local numbers.') }}
                    </p>
                    <div class="mt-2 inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900 ring-1 ring-amber-200">
                        {{ $t('Numbers needing a country code') }}: {{ missingDefaultCountryCodeCount }}
                    </div>
                    <div class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-[1fr_auto]">
                        <select
                            v-model="defaultCountryCode"
                            class="h-11 rounded-md border border-amber-300 bg-white px-3 text-sm text-slate-800 outline-none ring-amber-200 transition focus:border-amber-500 focus:ring"
                        >
                            <option value="">{{ $t('Select country code') }}</option>
                            <option v-for="country in countryCodeOptions" :key="`default-country-${country.value}-${country.label}`" :value="country.value">
                                {{ country.label }}
                            </option>
                        </select>
                        <button
                            type="button"
                            class="inline-flex h-11 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-white transition hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="busy || !defaultCountryCode"
                            @click="normalizeRows"
                        >
                            {{ busy ? $t('Checking...') : $t('Check numbers') }}
                        </button>
                    </div>
                </div>

                <div v-if="busy && !normalizationApplied" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-3">
                    <div class="flex items-center gap-2 text-sm text-slate-700">
                        <svg class="h-4 w-4 animate-spin text-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4z"></path>
                        </svg>
                        <span>{{ $t('Checking numbers and preparing report...') }}</span>
                    </div>
                </div>

                <div v-if="hasExistingGroupedContacts" class="rounded-xl border border-orange-200 bg-orange-50 px-3 py-3 text-sm text-orange-900">
                    <p class="font-semibold">{{ $t('Some uploaded numbers already exist in audiences') }}</p>
                    <p class="mt-1 text-xs leading-5 text-orange-800">
                        {{ $t('These contacts are already assigned to one or more audiences. Review them before adding them again to avoid duplicated segmentation.') }}
                    </p>
                    <p class="mt-2 text-xs font-semibold text-orange-900">
                        {{ $t('Existing numbers in audiences') }}: {{ existingGroupedContactsCount }}
                    </p>
                    <p v-if="existingGroupedContacts.sample_phones.length" class="mt-1 text-xs text-orange-800">
                        {{ $t('Examples') }}: {{ existingGroupedContacts.sample_phones.join(', ') }}
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
                    <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                        <p class="text-[11px] text-slate-500">{{ $t('Total') }}</p>
                        <p class="text-sm font-semibold text-slate-800">{{ batchStats.total || 0 }}</p>
                    </div>
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2">
                        <p class="text-[11px] text-emerald-700">{{ $t('Valid') }}</p>
                        <p class="text-sm font-semibold text-emerald-800">{{ batchStats.valid || 0 }}</p>
                    </div>
                    <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2">
                        <p class="text-[11px] text-rose-700">{{ $t('Invalid') }}</p>
                        <p class="text-sm font-semibold text-rose-800">{{ displayedInvalidRowsCount }}</p>
                    </div>
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                        <p class="text-[11px] text-amber-700">{{ $t('Removed') }}</p>
                        <p class="text-sm font-semibold text-amber-800">{{ batchStats.removed || 0 }}</p>
                    </div>
                </div>

                <div class="rounded-lg border border-slate-200 bg-white p-3">
                    

                    <div class="mt-3 flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="inline-flex h-11 min-w-[10rem] flex-1 items-center justify-center rounded-md border border-slate-300 px-4 text-sm text-slate-700 transition-colors hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="busy || uploadBusy"
                            @click="goToPreviousStep"
                        >
                            {{ $t('Previous') }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex h-11 min-w-[12rem] flex-[2_1_14rem] items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-white transition-colors hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="busy || !canContinueToGroups"
                            @click="wizardStep = 4"
                        >
                            {{ $t('Continue') }}
                        </button>
                    </div>
                    <p class="mt-2 text-xs" :class="canContinueToGroups ? 'text-emerald-700' : 'text-slate-500'">
                        {{ canContinueToGroups
                            ? $t('Numbers are ready. You can continue to audience assignment.')
                            : $t('Checking numbers before continuing.') }}
                    </p>
                </div>

                <div v-if="hasInvalidRows" class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-rose-50 px-3 py-2">
                        <div>
                            <p class="text-sm font-semibold text-rose-800">{{ $t('Invalid numbers report') }}</p>
                            <p class="text-xs text-rose-700">{{ $t('Download all invalid rows before continuing the import.') }}</p>
                        </div>
                        <a
                            :href="invalidRowsReportUrl"
                            target="_blank"
                            class="rounded-md border border-rose-200 bg-white px-3 py-1.5 text-xs font-medium text-rose-700 transition hover:bg-rose-100"
                        >
                            {{ $t('Download invalid numbers report CSV') }}
                        </a>
                    </div>
                    <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-xs">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-2 py-2 text-left">{{ $t('Row') }}</th>
                                <th class="px-2 py-2 text-left">{{ $t('Phone') }}</th>
                                <th class="px-2 py-2 text-left">{{ $t('Name') }}</th>
                                <th class="px-2 py-2 text-left">{{ $t('Status') }}</th>
                                <th class="px-2 py-2 text-left">{{ $t('Errors') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in invalidRowsPreview" :key="`invalid-${row.row_number}`">
                                <td class="px-2 py-2">{{ row.row_number }}</td>
                                <td class="px-2 py-2">{{ row.mapped_data?.phone || '-' }}</td>
                                <td class="px-2 py-2">{{ row.mapped_data?.first_name || '-' }}</td>
                                <td class="px-2 py-2">
                                    <span class="rounded-full px-2 py-0.5" :class="row.status === 'invalid' ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700'">{{ $t(row.status) }}</span>
                                </td>
                                <td class="px-2 py-2">{{ (row.errors || []).join(' | ') || '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                    <div v-if="invalidRowsPreviewLimited" class="border-t border-slate-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">
                        {{ $t('Only part of invalid rows is shown for preview.') }}
                    </div>
                </div>
                
            </div>

            <div v-if="wizardStep === 4" class="space-y-3">
                <p class="text-sm text-slate-600" :data-help-text="audienceHelp.step" data-help-icon>{{ $t('Choose how to assign imported contacts to audience.') }}</p>

                <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-3 space-y-3">
                    <label
                        class="flex cursor-pointer items-start gap-2 rounded-lg border p-3 transition"
                        :class="groupForm.assignment_mode === 'all_selected' ? 'border-primary bg-primary/5 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300'"
                        :data-help-text="audienceHelp.allSelected"
                        data-help-icon
                    >
                        <input v-model="groupForm.assignment_mode" type="radio" value="all_selected" class="mt-0.5">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">{{ $t('Add all contacts to selected audience') }}</p>
                            <p class="text-xs text-slate-500">{{ $t('All imported contacts will be added to selected/manual audience only. File audience names are used only when choosing audiences from file.') }}</p>
                        </div>
                    </label>
                    <label
                        class="flex cursor-pointer items-start gap-2 rounded-lg border p-3 transition"
                        :class="groupForm.assignment_mode === 'split' ? 'border-primary bg-primary/5 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300'"
                        :data-help-text="audienceHelp.split"
                        data-help-icon
                    >
                        <input v-model="groupForm.assignment_mode" type="radio" value="split" class="mt-0.5">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">{{ $t('Split contacts across generated audience') }}</p>
                            <p class="text-xs text-slate-500">{{ $t('Create sequential audience like prefix_1, prefix_2 and distribute contacts by batch size.') }}</p>
                        </div>
                    </label>
                    <label
                        class="flex items-start gap-2 rounded-lg border p-3 transition"
                        :class="[
                            groupForm.assignment_mode === 'file_group_name' ? 'border-primary bg-primary/5 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300',
                            hasGroupNameMapping ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'
                        ]"
                        :data-help-text="audienceHelp.fromFile"
                        data-help-icon
                    >
                        <input v-model="groupForm.assignment_mode" type="radio" value="file_group_name" class="mt-0.5" :disabled="!hasGroupNameMapping">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">{{ $t('Use audiences from file') }}</p>
                            <p class="text-xs text-slate-500">{{ $t('Contacts will be assigned using the group_name value in each row. Existing audiences are reused and missing audiences are created automatically.') }}</p>
                            <p v-if="!hasGroupNameMapping" class="mt-1 text-xs font-medium text-orange-700">
                                {{ $t('Map the audience Name field to enable this option.') }}
                            </p>
                        </div>
                    </label>
                </div>

                <div v-if="groupForm.assignment_mode === 'all_selected'" class="space-y-3">
                    <div class="rounded-xl border border-slate-200 bg-white p-3">
                        <div class="relative">
                            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M8.5 3a5.5 5.5 0 104.236 9.01l3.127 3.127a1 1 0 001.414-1.414l-3.127-3.127A5.5 5.5 0 008.5 3zM5 8.5a3.5 3.5 0 117 0 3.5 3.5 0 01-7 0z" clip-rule="evenodd" />
                            </svg>
                            <input
                                v-model="groupSearch"
                                type="text"
                                class="w-full rounded-lg border border-slate-300 py-2 pl-9 pr-3 text-sm outline-none ring-primary/20 transition focus:border-primary focus:ring"
                                :placeholder="$t('Search audience...')"
                                :aria-label="audienceHelp.search"
                            >
                        </div>

                        <div class="mt-2 flex items-center gap-2 text-xs">
                            <span class="rounded-full bg-slate-100 px-2 py-1 text-slate-600" :data-help-text="audienceHelp.visible" data-help-icon>{{ $t('Visible') }}: {{ filteredContactGroups.length }}</span>
                            <span class="rounded-full bg-emerald-50 px-2 py-1 text-emerald-700" :data-help-text="audienceHelp.selected" data-help-icon>{{ $t('Selected') }}: {{ selectedGroupsCount }}</span>
                        </div>

                        <div class="mt-2 max-h-56 overflow-auto rounded-lg border border-slate-200 bg-slate-50/40 p-2">
                            <label
                                v-for="group in filteredContactGroups"
                                :key="`assign-${group.id}`"
                                class="mb-1 flex cursor-pointer items-center gap-2 rounded-md border px-2 py-2 text-sm transition last:mb-0"
                                :class="groupForm.group_ids.includes(group.id) ? 'border-primary bg-primary/5 text-primary' : 'border-transparent bg-white text-slate-700 hover:border-slate-200'"
                                :data-help-text="audienceHelp.chooseAudience"
                                data-help-icon
                            >
                                <input v-model="groupForm.group_ids" type="checkbox" :value="group.id">
                                <span class="truncate">{{ group.name }}</span>
                            </label>
                            <p v-if="filteredContactGroups.length === 0" class="px-2 py-3 text-center text-xs text-slate-500">
                                {{ $t('No audience found') }}
                            </p>
                        </div>
                    </div>

                    <label class="text-xs text-slate-600">
                        <span :data-help-text="audienceHelp.newAudience" data-help-icon>{{ $t('Create New audience') }}</span>
                        <input v-model="groupForm.new_group_name" type="text" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none ring-primary/20 focus:border-primary focus:ring" :placeholder="$t('New audience name')" :aria-label="audienceHelp.newAudience">
                    </label>
                </div>

                <div v-else-if="groupForm.assignment_mode === 'split'" class="grid gap-3 md:grid-cols-2">
                    <label class="text-xs text-slate-600">
                        <span :data-help-text="audienceHelp.contactsPerAudience" data-help-icon>{{ $t('Contacts per audience') }}</span>
                        <input v-model.number="groupForm.split_group_size" min="1" type="number" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none ring-primary/20 focus:border-primary focus:ring" :placeholder="$t('100')" :aria-label="audienceHelp.contactsPerAudience">
                    </label>
                    <label class="text-xs text-slate-600">
                        <span :data-help-text="audienceHelp.prefix" data-help-icon>{{ $t('audience name prefix') }}</span>
                        <input v-model="groupForm.split_group_prefix" type="text" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none ring-primary/20 focus:border-primary focus:ring" :placeholder="$t('audience')" :aria-label="audienceHelp.prefix">
                    </label>
                </div>

                <div v-else-if="groupForm.assignment_mode === 'file_group_name'" class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-3 text-sm text-emerald-900" :data-help-text="audienceHelp.fromFile" data-help-icon>
                    <p class="font-semibold">{{ $t('Audience names will come from your file') }}</p>
                    <p class="mt-1 text-xs leading-5 text-emerald-800">
                        {{ $t('Each imported contact will be added to the audience written in its group_name column. If an audience does not exist yet, it will be created automatically.') }}
                    </p>
                </div>

                <div class="flex justify-end">
                    <div class="flex items-center gap-2">
                        <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700 disabled:opacity-60" :disabled="busy || uploadBusy" @click="goToPreviousStep">
                            {{ $t('Previous') }}
                        </button>
                        <button type="button" class="rounded-md bg-primary px-4 py-2 text-sm text-white disabled:opacity-60" :data-help-text="audienceHelp.save" data-help-icon :disabled="busy || groupAssignmentIsInvalid" @click="saveGroupAssignment">
                            {{ busy ? $t('Saving...') : $t('Save Audience Assignment') }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="wizardStep === 5" class="space-y-3">
                <div v-if="backgroundImport" class="space-y-3">
                    <div class="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="font-medium text-slate-800">{{ $t('Background import') }}</p>
                                <p class="text-xs text-slate-600">{{ batch.original_file_name }}</p>
                            </div>
                            <span class="rounded-full px-2 py-1 text-xs font-medium" :class="batch.status === 'failed' ? 'bg-rose-100 text-rose-700' : isImportCompleted ? 'bg-emerald-100 text-emerald-700' : 'bg-sky-100 text-sky-700'">
                                {{ $t(batch.status || 'queued') }}
                            </span>
                        </div>
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                            <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${backgroundProgressPercent}%` }"></div>
                        </div>
                        <p class="mt-2 text-xs text-slate-600">
                            {{ backgroundDisplayedRows }} / {{ backgroundTotalRows }} ({{ backgroundProgressPercent }}%) - {{ backgroundPhaseLabel }}
                        </p>
                        <p v-if="backgroundInProgress" class="mt-2 text-xs text-slate-600">
                            {{ $t('You can keep this window open to watch progress. The import is running in the background.') }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-2 md:grid-cols-6">
                        <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                            <p class="text-[11px] text-slate-500">{{ $t('Total') }}</p>
                            <p class="text-sm font-semibold text-slate-800">{{ batch.total_rows || 0 }}</p>
                        </div>
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2">
                            <p class="text-[11px] text-emerald-700">{{ $t('Valid rows') }}</p>
                            <p class="text-sm font-semibold text-emerald-800">{{ batch.valid_rows || 0 }}</p>
                        </div>
                        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2">
                            <p class="text-[11px] text-rose-700">{{ $t('Invalid') }}</p>
                            <p class="text-sm font-semibold text-rose-800">{{ batch.invalid_rows || 0 }}</p>
                        </div>
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                            <p class="text-[11px] text-amber-700">{{ $t('Duplicates') }}</p>
                            <p class="text-sm font-semibold text-amber-800">{{ backgroundDuplicatesCount }}</p>
                        </div>
                        <div class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-2">
                            <p class="text-[11px] text-violet-700">{{ $t('Imported contacts') }}</p>
                            <p class="text-sm font-semibold text-violet-800">{{ backgroundImportedContactsCount }}</p>
                        </div>
                        <div class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2">
                            <p class="text-[11px] text-sky-700">{{ $t('Created') }}</p>
                            <p class="text-sm font-semibold text-sky-800">{{ batch.created_count || 0 }}</p>
                        </div>
                        <div class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2">
                            <p class="text-[11px] text-indigo-700">{{ $t('Updated') }}</p>
                            <p class="text-sm font-semibold text-indigo-800">{{ batch.updated_count || 0 }}</p>
                        </div>
                    </div>

                    <p v-if="backgroundDuplicatesCount" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        {{ $t('Some valid rows had duplicate phone numbers. Duplicate rows are counted as valid rows, but they do not create extra contacts; their audience names are merged into the same contact.') }}
                    </p>

                    <div v-if="isImportCompleted || batch.status === 'failed'" class="flex flex-wrap gap-2 text-sm">
                        <a v-if="batch.invalid_rows" :href="invalidRowsReportUrl" target="_blank" class="text-primary underline">{{ $t('Download invalid numbers report CSV') }}</a>
                        <a :href="`/contacts/import/${batch.uuid}/report.csv`" target="_blank" class="text-primary underline">{{ $t('Download failure report CSV') }}</a>
                    </div>
                </div>

                <div v-else class="space-y-3">
                    <div v-if="!isImportCompleted" class="rounded-md bg-slate-50 p-3 text-sm text-slate-700">
                        <p>{{ $t('Ready to import valid rows.') }}</p>
                        <p>{{ $t('Valid rows') }}: {{ batchStats.valid || 0 }} | {{ $t('Invalid rows') }}: {{ batchStats.invalid || 0 }} | {{ $t('Removed rows') }}: {{ batchStats.removed || 0 }}</p>
                    </div>

                    <div v-if="!report" class="flex justify-end">
                        <div class="flex items-center gap-2">
                            <button type="button" class="rounded-md bg-primary px-4 py-2 text-sm text-white disabled:opacity-60" :disabled="busy" @click="executeImport">
                                {{ busy ? $t('Importing...') : $t('Execute Import') }}
                            </button>
                        </div>
                    </div>

                    <div v-else class="space-y-2 rounded-md border border-slate-200 p-3 text-sm">
                        <p class="font-medium text-slate-800">{{ $t('Execution Report') }}</p>
                        <div class="grid grid-cols-2 gap-2 md:grid-cols-5">
                            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2">
                                <p class="text-[11px] text-emerald-700">{{ $t('success') }}</p>
                                <p class="text-sm font-semibold text-emerald-800">{{ report.success_count || 0 }}</p>
                            </div>
                            <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2">
                                <p class="text-[11px] text-rose-700">{{ $t('failed') }}</p>
                                <p class="text-sm font-semibold text-rose-800">{{ report.failed_count || 0 }}</p>
                            </div>
                            <div class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2">
                                <p class="text-[11px] text-sky-700">{{ $t('Created') }}</p>
                                <p class="text-sm font-semibold text-sky-800">{{ report.created_count || 0 }}</p>
                            </div>
                            <div class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2">
                                <p class="text-[11px] text-indigo-700">{{ $t('Updated') }}</p>
                                <p class="text-sm font-semibold text-indigo-800">{{ report.updated_count || 0 }}</p>
                            </div>
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
                                <p class="text-[11px] text-amber-700">{{ $t('Duplicates') }}</p>
                                <p class="text-sm font-semibold text-amber-800">{{ report.duplicates_count || 0 }}</p>
                            </div>
                        </div>
                        <a :href="`/contacts/import/${batch.uuid}/report.csv`" target="_blank" class="text-primary underline">{{ $t('Download failure report CSV') }}</a>
                    </div>
                </div>
            </div>
        </div>
    </Modal>

    <Teleport to="body">
        <div
            v-if="helpTooltip.visible"
            class="contact-import-help-tooltip"
            :class="`is-${helpTooltip.placement}`"
            :style="{ left: `${helpTooltip.left}px`, top: `${helpTooltip.top}px` }"
            role="tooltip"
        >
            {{ helpTooltip.text }}
        </div>
    </Teleport>

    <Modal :label="$t('Edit Import Row')" :isOpen="isEditOpen" :closeBtn="true" @close="isEditOpen = false">
        <div class="space-y-2 pt-4">
            <div v-if="editErrorMessage" class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ editErrorMessage }}</div>
            <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                <input v-model="rowEditor.first_name" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" :placeholder="$t('First name')">
                <input v-model="rowEditor.last_name" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" :placeholder="$t('Last name')">
                <input v-model="rowEditor.phone" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" :placeholder="$t('Phone')">
                <input v-model="rowEditor.email" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" :placeholder="$t('Email')">
                <input v-model="rowEditor.group_name" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" :placeholder="$t('Audience names | separated')">
                <input v-model="rowEditor.country" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" :placeholder="$t('Country')">
                <input v-model="rowEditor.street" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" :placeholder="$t('Street')">
                <input v-model="rowEditor.city" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" :placeholder="$t('City')">
                <input v-model="rowEditor.state" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" :placeholder="$t('State')">
                <input v-model="rowEditor.zip" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" :placeholder="$t('Zip')">
            </div>

            <div class="rounded-md border border-slate-200 p-2">
                <p class="mb-2 text-xs font-medium text-slate-600">{{ $t('Custom Attributes') }}</p>
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                    <label v-for="field in fields" :key="`edit-custom-${field.id}`" class="text-xs text-slate-500">{{ field.name }}
                        <input v-model="rowEditor.custom_attributes[field.name]" type="text" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                    </label>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm" @click="isEditOpen = false">{{ $t('Cancel') }}</button>
                <button type="button" class="rounded-md bg-primary px-3 py-2 text-sm text-white disabled:opacity-60" :disabled="isSavingEdit" @click.prevent="saveEdit">
                    {{ isSavingEdit ? $t('Saving...') : $t('Save') }}
                </button>
            </div>
        </div>
    </Modal>
</template>
<style>
.contact-import-wizard [data-help-icon] {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

.contact-import-wizard [data-help-icon]::before {
    content: none !important;
    display: none !important;
}

.contact-import-wizard [data-help-icon]::after {
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

.contact-import-wizard [data-help-icon]:hover::after,
.contact-import-wizard [data-help-icon]:focus-visible::after {
    border-color: #0f172a;
    color: #0f172a;
}

.contact-import-help-tooltip {
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

.contact-import-help-tooltip.is-top {
    transform: translateY(-100%);
}

.contact-import-help-tooltip.is-bottom {
    transform: none;
}
</style>
