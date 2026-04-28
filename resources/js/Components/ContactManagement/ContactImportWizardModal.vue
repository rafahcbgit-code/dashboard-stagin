<script setup>
import { computed, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import Modal from '@/Components/Modal.vue';

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
const successMessage = ref('');

const fileName = ref('');
const batch = ref(null);
const rows = ref([]);
const rowsMeta = reactive({ current_page: 1, last_page: 1, per_page: 10, total: 0 });
const invalidRowsPreview = ref([]);
const invalidRowsCount = ref(0);
const invalidRowsPreviewLimited = ref(false);

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

const defaultCountryCode = ref('+966');
const normalizationApplied = ref(false);
const groupForm = reactive({
    assignment_mode: 'all_selected',
    group_ids: [],
    new_group_name: '',
    split_group_size: 100,
    split_group_prefix: 'audince',
});
const groupSearch = ref('');

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

const hasBatch = computed(() => !!batch.value?.uuid);
const batchHeaders = computed(() => batch.value?.headers || []);
const batchStats = computed(() => batch.value?.stats || {});
const rawReport = computed(() => batch.value?.report || null);
const isImportCompleted = computed(() => batch.value?.status === 'committed' || Boolean(rawReport.value?.executed_at));
const report = computed(() => (isImportCompleted.value ? rawReport.value : null));
const invalidRowsReportUrl = computed(() => (batch.value?.uuid ? `/contacts/import/${batch.value.uuid}/invalid-report.csv` : '#'));

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
    wizardStep.value = 1;
    busy.value = false;
    uploadBusy.value = false;
    errorMessage.value = '';
    successMessage.value = '';
    fileName.value = '';
    batch.value = null;
    rows.value = [];
    invalidRowsPreview.value = [];
    invalidRowsCount.value = 0;
    invalidRowsPreviewLimited.value = false;
    rowsMeta.current_page = 1;
    rowsMeta.last_page = 1;
    rowsMeta.per_page = 10;
    rowsMeta.total = 0;
    defaultCountryCode.value = '+966';
    normalizationApplied.value = false;
    groupForm.group_ids = [];
    groupForm.new_group_name = '';
    groupForm.assignment_mode = 'all_selected';
    groupForm.split_group_size = 100;
    groupForm.split_group_prefix = 'audince';
    groupSearch.value = '';
    showAdvancedMapping.value = false;

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

    try {
        const { data } = await axios.post(`/contacts/import/${batch.value.uuid}/mapping`, { mapping });
        batch.value = data.batch;
        applyRowsPayload(data.rows);
        wizardStep.value = 3;
        normalizationApplied.value = false;
        await normalizeRows();
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

    try {
        const { data } = await axios.post(`/contacts/import/${batch.value.uuid}/normalize`, {
            default_country_code: defaultCountryCode.value,
        });
        batch.value = data.batch;
        batch.value.requires_default_country_code = false;
        normalizationApplied.value = true;
        invalidRowsCount.value = Number(data?.invalid_preview?.count || 0);
        invalidRowsPreview.value = Array.isArray(data?.invalid_preview?.rows) ? data.invalid_preview.rows : [];
        invalidRowsPreviewLimited.value = Boolean(data?.invalid_preview?.limited);
        successMessage.value = invalidRowsCount.value > 0
            ? t('Numbers checked. Invalid rows were excluded from import and shown below.')
            : t('Numbers checked. No invalid rows found.');
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || t('Failed to normalize rows.');
    } finally {
        busy.value = false;
    }
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
        errorMessage.value = error?.response?.data?.message || t('Failed to assign audince.');
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

const goToPreviousStep = () => {
    if (busy.value || uploadBusy.value) {
        return;
    }

    wizardStep.value = Math.max(1, wizardStep.value - 1);
};
</script>

<template>
    <Modal :label="$t('Import Contacts Wizard')" :isOpen="modelValue" :closeBtn="true" @close="closeModal">
        <div class="space-y-4 pt-4">
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="rounded-full px-2 py-1" :class="wizardStep >= 1 ? 'bg-primary text-white' : 'bg-slate-100 text-slate-600'">{{ $t('1. Upload') }}</span>
                <span class="rounded-full px-2 py-1" :class="wizardStep >= 2 ? 'bg-primary text-white' : 'bg-slate-100 text-slate-600'">{{ $t('2. Mapping') }}</span>
                <span class="rounded-full px-2 py-1" :class="wizardStep >= 3 ? 'bg-primary text-white' : 'bg-slate-100 text-slate-600'">{{ $t('3. Review Invalid Numbers') }}</span>
                <span class="rounded-full px-2 py-1" :class="wizardStep >= 4 ? 'bg-primary text-white' : 'bg-slate-100 text-slate-600'">{{ $t('4. Audince') }}</span>
                <span class="rounded-full px-2 py-1" :class="wizardStep >= 5 ? 'bg-primary text-white' : 'bg-slate-100 text-slate-600'">{{ $t('5. Import Report') }}</span>
            </div>

            <div v-if="errorMessage" class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ errorMessage }}</div>
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
                            <label class="text-xs text-slate-500">{{ $t('Audince Name') }}
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
                <div v-if="busy && !normalizationApplied" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-3">
                    <div class="flex items-center gap-2 text-sm text-slate-700">
                        <svg class="h-4 w-4 animate-spin text-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4z"></path>
                        </svg>
                        <span>{{ $t('Checking numbers and preparing report...') }}</span>
                    </div>
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
                            ? $t('Numbers are ready. You can continue to audince assignment.')
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
                <p class="text-sm text-slate-600">{{ $t('Choose how to assign imported contacts to audince.') }}</p>

                <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-3 space-y-3">
                    <label
                        class="flex cursor-pointer items-start gap-2 rounded-lg border p-3 transition"
                        :class="groupForm.assignment_mode === 'all_selected' ? 'border-primary bg-primary/5 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300'"
                    >
                        <input v-model="groupForm.assignment_mode" type="radio" value="all_selected" class="mt-0.5">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">{{ $t('Add all contacts to selected audince') }}</p>
                            <p class="text-xs text-slate-500">{{ $t('All imported contacts will be added to selected/manual audince. File audince names will also be merged.') }}</p>
                        </div>
                    </label>
                    <label
                        class="flex cursor-pointer items-start gap-2 rounded-lg border p-3 transition"
                        :class="groupForm.assignment_mode === 'split' ? 'border-primary bg-primary/5 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300'"
                    >
                        <input v-model="groupForm.assignment_mode" type="radio" value="split" class="mt-0.5">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">{{ $t('Split contacts across generated audince') }}</p>
                            <p class="text-xs text-slate-500">{{ $t('Create sequential audince like prefix_1, prefix_2 and distribute contacts by batch size.') }}</p>
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
                                :placeholder="$t('Search audince...')"
                            >
                        </div>

                        <div class="mt-2 flex items-center gap-2 text-xs">
                            <span class="rounded-full bg-slate-100 px-2 py-1 text-slate-600">{{ $t('Visible') }}: {{ filteredContactGroups.length }}</span>
                            <span class="rounded-full bg-emerald-50 px-2 py-1 text-emerald-700">{{ $t('Selected') }}: {{ selectedGroupsCount }}</span>
                        </div>

                        <div class="mt-2 max-h-56 overflow-auto rounded-lg border border-slate-200 bg-slate-50/40 p-2">
                            <label
                                v-for="group in filteredContactGroups"
                                :key="`assign-${group.id}`"
                                class="mb-1 flex cursor-pointer items-center gap-2 rounded-md border px-2 py-2 text-sm transition last:mb-0"
                                :class="groupForm.group_ids.includes(group.id) ? 'border-primary bg-primary/5 text-primary' : 'border-transparent bg-white text-slate-700 hover:border-slate-200'"
                            >
                                <input v-model="groupForm.group_ids" type="checkbox" :value="group.id">
                                <span class="truncate">{{ group.name }}</span>
                            </label>
                            <p v-if="filteredContactGroups.length === 0" class="px-2 py-3 text-center text-xs text-slate-500">
                                {{ $t('No audince found') }}
                            </p>
                        </div>
                    </div>

                    <label class="text-xs text-slate-600">{{ $t('Create New Audince') }}
                        <input v-model="groupForm.new_group_name" type="text" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none ring-primary/20 focus:border-primary focus:ring" :placeholder="$t('New audince name')">
                    </label>
                </div>

                <div v-else class="grid gap-3 md:grid-cols-2">
                    <label class="text-xs text-slate-600">{{ $t('Contacts per audince') }}
                        <input v-model.number="groupForm.split_group_size" min="1" type="number" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none ring-primary/20 focus:border-primary focus:ring" :placeholder="$t('100')">
                    </label>
                    <label class="text-xs text-slate-600">{{ $t('Audince name prefix') }}
                        <input v-model="groupForm.split_group_prefix" type="text" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none ring-primary/20 focus:border-primary focus:ring" :placeholder="$t('audince')">
                    </label>
                </div>

                <div class="flex justify-end">
                    <div class="flex items-center gap-2">
                        <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700 disabled:opacity-60" :disabled="busy || uploadBusy" @click="goToPreviousStep">
                            {{ $t('Previous') }}
                        </button>
                        <button type="button" class="rounded-md bg-primary px-4 py-2 text-sm text-white disabled:opacity-60" :disabled="busy || (groupForm.assignment_mode === 'split' && (!groupForm.split_group_size || groupForm.split_group_size < 1 || !String(groupForm.split_group_prefix || '').trim()))" @click="saveGroupAssignment">
                            {{ busy ? $t('Saving...') : $t('Save Audince Assignment') }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="wizardStep === 5" class="space-y-3">
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
                    <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
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
                    </div>
                    <a :href="`/contacts/import/${batch.uuid}/report.csv`" target="_blank" class="text-primary underline">{{ $t('Download failure report CSV') }}</a>
                </div>
            </div>
        </div>
    </Modal>

    <Modal :label="$t('Edit Import Row')" :isOpen="isEditOpen" :closeBtn="true" @close="isEditOpen = false">
        <div class="space-y-2 pt-4">
            <div v-if="editErrorMessage" class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ editErrorMessage }}</div>
            <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                <input v-model="rowEditor.first_name" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" :placeholder="$t('First name')">
                <input v-model="rowEditor.last_name" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" :placeholder="$t('Last name')">
                <input v-model="rowEditor.phone" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" :placeholder="$t('Phone')">
                <input v-model="rowEditor.email" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" :placeholder="$t('Email')">
                <input v-model="rowEditor.group_name" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" :placeholder="$t('Audince names | separated')">
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
