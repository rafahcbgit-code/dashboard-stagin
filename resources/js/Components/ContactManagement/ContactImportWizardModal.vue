<script setup>
import { computed, reactive, ref, watch } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    contactGroups: { type: Array, default: () => [] },
    fields: { type: Array, default: () => [] },
    countries: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue', 'completed']);

const wizardStep = ref(1);
const busy = ref(false);
const errorMessage = ref('');
const successMessage = ref('');

const fileName = ref('');
const batch = ref(null);
const rows = ref([]);
const rowsMeta = reactive({ current_page: 1, last_page: 1, per_page: 10, total: 0 });
const rowFilter = ref('all');

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
const groupForm = reactive({
    group_ids: [],
    new_group_name: '',
});

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

const hasBatch = computed(() => !!batch.value?.uuid);
const batchHeaders = computed(() => batch.value?.headers || []);
const batchStats = computed(() => batch.value?.stats || {});
const report = computed(() => batch.value?.report || null);

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
    errorMessage.value = '';
    successMessage.value = '';
    fileName.value = '';
    batch.value = null;
    rows.value = [];
    rowFilter.value = 'all';
    rowsMeta.current_page = 1;
    rowsMeta.last_page = 1;
    rowsMeta.per_page = 10;
    rowsMeta.total = 0;
    defaultCountryCode.value = '+966';
    groupForm.group_ids = [];
    groupForm.new_group_name = '';

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

const importSummaryText = computed(() => {
    if (!report.value) {
        return '';
    }

    return `${report.value.success_count || 0} success / ${report.value.failed_count || 0} failed`;
});

const uploadAndPreview = async (event) => {
    const file = event?.target?.files?.[0];
    if (!file) {
        return;
    }

    errorMessage.value = '';
    successMessage.value = '';
    busy.value = true;
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
        errorMessage.value = error?.response?.data?.message || 'Failed to parse file.';
    } finally {
        busy.value = false;
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
        errorMessage.value = 'Phone mapping is required.';
        return;
    }

    busy.value = true;
    errorMessage.value = '';

    try {
        const { data } = await axios.post(`/contacts/import/${batch.value.uuid}/mapping`, { mapping });
        batch.value = data.batch;
        applyRowsPayload(data.rows);
        wizardStep.value = 3;
        rowFilter.value = 'invalid';
        await fetchRows(1, 'invalid');
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Failed to save mapping.';
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
        applyRowsPayload(data.rows);
        rowFilter.value = 'invalid';
        await fetchRows(1, 'invalid');
        successMessage.value = 'Normalization completed. Fix or remove invalid rows before import.';
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Failed to normalize rows.';
    } finally {
        busy.value = false;
    }
};

const fetchRows = async (page = 1, status = rowFilter.value) => {
    if (!hasBatch.value) {
        return;
    }

    busy.value = true;
    errorMessage.value = '';
    rowFilter.value = status;

    try {
        const { data } = await axios.get(`/contacts/import/${batch.value.uuid}/rows`, {
            params: {
                page,
                per_page: rowsMeta.per_page,
                status,
            },
        });

        batch.value = data.batch;
        applyRowsPayload(data.rows);
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Failed to load rows.';
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
        editErrorMessage.value = 'Unable to edit this row (missing row id). Please reopen the row.';
        return;
    }

    if (!hasBatch.value) {
        editErrorMessage.value = 'Import batch is not ready. Please reopen the import wizard.';
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
        await fetchRows(rowsMeta.current_page, rowFilter.value);
    } catch (error) {
        const errors = error?.response?.data?.errors;
        const firstError = errors && typeof errors === 'object'
            ? Object.values(errors).flat()[0]
            : null;
        const message = firstError || error?.response?.data?.message || 'Failed to update row.';
        editErrorMessage.value = message;
        errorMessage.value = message;
    } finally {
        busy.value = false;
        isSavingEdit.value = false;
    }
};

const removeRow = async (row) => {
    if (!hasBatch.value) {
        return;
    }

    busy.value = true;
    errorMessage.value = '';

    try {
        await axios.delete(`/contacts/import/${batch.value.uuid}/rows/${row.id}`);
        await fetchRows(rowsMeta.current_page, rowFilter.value);
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Failed to remove row.';
    } finally {
        busy.value = false;
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
        });
        batch.value = data.batch;
        applyRowsPayload(data.rows);
        wizardStep.value = 5;
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Failed to assign groups.';
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
        batch.value = data.batch;
        successMessage.value = 'Import finished successfully.';
        emit('completed');
    } catch (error) {
        errorMessage.value = error?.response?.data?.message || 'Failed to import contacts.';
    } finally {
        busy.value = false;
    }
};

const canGoToGroupStep = computed(() => (batchStats.value.invalid || 0) === 0);
</script>

<template>
    <Modal :label="$t('Import Contacts Wizard')" :isOpen="modelValue" :closeBtn="true" @close="closeModal">
        <div class="space-y-4 pt-4">
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="rounded-full px-2 py-1" :class="wizardStep >= 1 ? 'bg-primary text-white' : 'bg-slate-100 text-slate-600'">1. Upload</span>
                <span class="rounded-full px-2 py-1" :class="wizardStep >= 2 ? 'bg-primary text-white' : 'bg-slate-100 text-slate-600'">2. Mapping</span>
                <span class="rounded-full px-2 py-1" :class="wizardStep >= 3 ? 'bg-primary text-white' : 'bg-slate-100 text-slate-600'">3. Normalize & Clean</span>
                <span class="rounded-full px-2 py-1" :class="wizardStep >= 4 ? 'bg-primary text-white' : 'bg-slate-100 text-slate-600'">4. Groups</span>
                <span class="rounded-full px-2 py-1" :class="wizardStep >= 5 ? 'bg-primary text-white' : 'bg-slate-100 text-slate-600'">5. Import Report</span>
            </div>

            <div v-if="errorMessage" class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ errorMessage }}</div>
            <div v-if="successMessage" class="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ successMessage }}</div>

            <div v-if="wizardStep === 1" class="space-y-3">
                <p class="text-sm text-slate-600">Upload CSV/XLSX file to start import preview.</p>
                <input type="file" accept=".csv,.xlsx" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" @change="uploadAndPreview">
                <p v-if="fileName" class="text-xs text-slate-500">File: {{ fileName }}</p>
            </div>

            <div v-if="wizardStep === 2" class="space-y-3">
                <p class="text-sm text-slate-600">Field mapping is required before processing the file.</p>
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                    <label class="text-xs text-slate-500">First Name
                        <select v-model="mapping.first_name" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                            <option value="">-</option>
                            <option v-for="header in batchHeaders" :key="`first_name-${header}`" :value="header">{{ header }}</option>
                        </select>
                    </label>
                    <label class="text-xs text-slate-500">Last Name
                        <select v-model="mapping.last_name" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                            <option value="">-</option>
                            <option v-for="header in batchHeaders" :key="`last_name-${header}`" :value="header">{{ header }}</option>
                        </select>
                    </label>
                    <label class="text-xs text-slate-500">Phone *
                        <select v-model="mapping.phone" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                            <option value="">Select phone column</option>
                            <option v-for="header in batchHeaders" :key="`phone-${header}`" :value="header">{{ header }}</option>
                        </select>
                    </label>
                    <label class="text-xs text-slate-500">Email
                        <select v-model="mapping.email" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                            <option value="">-</option>
                            <option v-for="header in batchHeaders" :key="`email-${header}`" :value="header">{{ header }}</option>
                        </select>
                    </label>
                    <label class="text-xs text-slate-500">Group Name
                        <select v-model="mapping.group_name" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                            <option value="">-</option>
                            <option v-for="header in batchHeaders" :key="`group-${header}`" :value="header">{{ header }}</option>
                        </select>
                    </label>
                    <label class="text-xs text-slate-500">Country
                        <select v-model="mapping.country" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                            <option value="">-</option>
                            <option v-for="header in batchHeaders" :key="`country-${header}`" :value="header">{{ header }}</option>
                        </select>
                    </label>
                    <label class="text-xs text-slate-500">Street
                        <select v-model="mapping.street" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                            <option value="">-</option>
                            <option v-for="header in batchHeaders" :key="`street-${header}`" :value="header">{{ header }}</option>
                        </select>
                    </label>
                    <label class="text-xs text-slate-500">City
                        <select v-model="mapping.city" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                            <option value="">-</option>
                            <option v-for="header in batchHeaders" :key="`city-${header}`" :value="header">{{ header }}</option>
                        </select>
                    </label>
                    <label class="text-xs text-slate-500">State
                        <select v-model="mapping.state" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                            <option value="">-</option>
                            <option v-for="header in batchHeaders" :key="`state-${header}`" :value="header">{{ header }}</option>
                        </select>
                    </label>
                    <label class="text-xs text-slate-500">Zip
                        <select v-model="mapping.zip" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                            <option value="">-</option>
                            <option v-for="header in batchHeaders" :key="`zip-${header}`" :value="header">{{ header }}</option>
                        </select>
                    </label>
                </div>

                <div class="rounded-md border border-slate-200 p-3">
                    <p class="mb-2 text-sm font-medium text-slate-700">Custom Attributes Mapping</p>
                    <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                        <label v-for="field in fields" :key="`custom-${field.id}`" class="text-xs text-slate-500">{{ field.name }}
                            <select v-model="mapping.custom_attributes[field.name]" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                                <option value="">-</option>
                                <option v-for="header in batchHeaders" :key="`${field.name}-${header}`" :value="header">{{ header }}</option>
                            </select>
                        </label>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="button" class="rounded-md bg-primary px-4 py-2 text-sm text-white disabled:opacity-60" :disabled="busy" @click="mapColumns">
                        {{ busy ? 'Saving...' : 'Save Mapping & Continue' }}
                    </button>
                </div>
            </div>

            <div v-if="wizardStep === 3" class="space-y-3">
                <div class="grid gap-2 md:grid-cols-2">
                    <label class="text-xs text-slate-500">Default Country Code (for numbers without code)
                        <input v-model="defaultCountryCode" type="text" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="+966">
                    </label>
                    <label class="text-xs text-slate-500">Filter Rows
                        <select v-model="rowFilter" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" @change="fetchRows(1, rowFilter)">
                            <option value="all">All</option>
                            <option value="invalid">Invalid</option>
                            <option value="valid">Valid</option>
                            <option value="removed">Removed</option>
                        </select>
                    </label>
                </div>

                <div class="flex flex-wrap items-center gap-2 text-xs text-slate-600">
                    <span>Total: {{ batchStats.total || 0 }}</span>
                    <span>Valid: {{ batchStats.valid || 0 }}</span>
                    <span>Invalid: {{ batchStats.invalid || 0 }}</span>
                    <span>Removed: {{ batchStats.removed || 0 }}</span>
                </div>

                <div class="flex justify-between">
                    <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm" :disabled="busy" @click="normalizeRows">
                        {{ busy ? 'Processing...' : 'Apply Normalization' }}
                    </button>
                    <button type="button" class="rounded-md bg-primary px-4 py-2 text-sm text-white disabled:opacity-60" :disabled="busy || !canGoToGroupStep" @click="wizardStep = 4">
                        Continue to Group Assignment
                    </button>
                </div>

                <div class="overflow-x-auto rounded-md border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-xs">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-2 py-2 text-left">Row</th>
                                <th class="px-2 py-2 text-left">Phone</th>
                                <th class="px-2 py-2 text-left">Name</th>
                                <th class="px-2 py-2 text-left">Status</th>
                                <th class="px-2 py-2 text-left">Errors</th>
                                <th class="px-2 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in rows" :key="row.id">
                                <td class="px-2 py-2">{{ row.row_number }}</td>
                                <td class="px-2 py-2">{{ row.mapped_data?.phone || '-' }}</td>
                                <td class="px-2 py-2">{{ row.mapped_data?.first_name || '-' }}</td>
                                <td class="px-2 py-2">
                                    <span class="rounded-full px-2 py-0.5" :class="row.status === 'invalid' ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700'">{{ row.status }}</span>
                                </td>
                                <td class="px-2 py-2">{{ (row.errors || []).join(' | ') || '-' }}</td>
                                <td class="px-2 py-2 text-right">
                                    <button type="button" class="rounded border border-rose-300 px-2 py-1 text-rose-600" @click="removeRow(row)">Delete</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-end gap-2">
                    <button type="button" class="rounded border border-slate-300 px-2 py-1 text-xs disabled:opacity-50" :disabled="rowsMeta.current_page <= 1 || busy" @click="fetchRows(rowsMeta.current_page - 1, rowFilter)">Prev</button>
                    <span class="text-xs text-slate-500">{{ rowsMeta.current_page }} / {{ rowsMeta.last_page }}</span>
                    <button type="button" class="rounded border border-slate-300 px-2 py-1 text-xs disabled:opacity-50" :disabled="rowsMeta.current_page >= rowsMeta.last_page || busy" @click="fetchRows(rowsMeta.current_page + 1, rowFilter)">Next</button>
                </div>
            </div>

            <div v-if="wizardStep === 4" class="space-y-3">
                <p class="text-sm text-slate-600">Assign existing groups and/or create one new group. File groups and selected groups will be merged.</p>
                <div class="max-h-48 overflow-auto rounded-md border border-slate-200 p-2">
                    <label v-for="group in contactGroups" :key="`assign-${group.id}`" class="mb-1 flex items-center gap-2 text-sm text-slate-700">
                        <input v-model="groupForm.group_ids" type="checkbox" :value="group.id">
                        {{ group.name }}
                    </label>
                </div>
                <label class="text-xs text-slate-500">Create New Group
                    <input v-model="groupForm.new_group_name" type="text" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="New group name">
                </label>
                <div class="flex justify-end">
                    <button type="button" class="rounded-md bg-primary px-4 py-2 text-sm text-white disabled:opacity-60" :disabled="busy" @click="saveGroupAssignment">
                        {{ busy ? 'Saving...' : 'Save Group Assignment' }}
                    </button>
                </div>
            </div>

            <div v-if="wizardStep === 5" class="space-y-3">
                <div class="rounded-md bg-slate-50 p-3 text-sm text-slate-700">
                    <p>Ready to import valid rows.</p>
                    <p>Valid rows: {{ batchStats.valid || 0 }} | Invalid rows: {{ batchStats.invalid || 0 }} | Removed rows: {{ batchStats.removed || 0 }}</p>
                </div>

                <div v-if="!report" class="flex justify-end">
                    <button type="button" class="rounded-md bg-primary px-4 py-2 text-sm text-white disabled:opacity-60" :disabled="busy" @click="executeImport">
                        {{ busy ? 'Importing...' : 'Execute Import' }}
                    </button>
                </div>

                <div v-else class="space-y-2 rounded-md border border-slate-200 p-3 text-sm">
                    <p class="font-medium text-slate-800">Execution Report</p>
                    <p class="text-slate-600">{{ importSummaryText }}</p>
                    <p class="text-slate-600">Created: {{ report.created_count || 0 }}, Updated: {{ report.updated_count || 0 }}, Removed: {{ report.removed_count || 0 }}</p>
                    <a :href="`/contacts/import/${batch.uuid}/report.csv`" target="_blank" class="text-primary underline">Download failure report CSV</a>
                </div>
            </div>
        </div>
    </Modal>

    <Modal :label="$t('Edit Import Row')" :isOpen="isEditOpen" :closeBtn="true" @close="isEditOpen = false">
        <div class="space-y-2 pt-4">
            <div v-if="editErrorMessage" class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ editErrorMessage }}</div>
            <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                <input v-model="rowEditor.first_name" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="First name">
                <input v-model="rowEditor.last_name" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="Last name">
                <input v-model="rowEditor.phone" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="Phone">
                <input v-model="rowEditor.email" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="Email">
                <input v-model="rowEditor.group_name" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="Group names | separated">
                <input v-model="rowEditor.country" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="Country">
                <input v-model="rowEditor.street" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="Street">
                <input v-model="rowEditor.city" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="City">
                <input v-model="rowEditor.state" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="State">
                <input v-model="rowEditor.zip" type="text" class="rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="Zip">
            </div>

            <div class="rounded-md border border-slate-200 p-2">
                <p class="mb-2 text-xs font-medium text-slate-600">Custom Attributes</p>
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                    <label v-for="field in fields" :key="`edit-custom-${field.id}`" class="text-xs text-slate-500">{{ field.name }}
                        <input v-model="rowEditor.custom_attributes[field.name]" type="text" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-2 text-sm">
                    </label>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm" @click="isEditOpen = false">Cancel</button>
                <button type="button" class="rounded-md bg-primary px-3 py-2 text-sm text-white disabled:opacity-60" :disabled="isSavingEdit" @click.prevent="saveEdit">
                    {{ isSavingEdit ? 'Saving...' : 'Save' }}
                </button>
            </div>
        </div>
    </Modal>
</template>
