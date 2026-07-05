<template>
    <AppLayout>
        <div class="p-8 rounded-[5px] text-[#000] overflow-y-auto">
            <div class="flex justify-between">
                <div>
                    <h1 class="text-xl mb-1">{{ $t('Resource add-ons') }}</h1>
                    <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                        {{ $t('Grant additional subscription resources for the current billing period.') }}
                    </p>
                </div>
            </div>

            <form @submit.prevent="submitForm" class="bg-white border p-5 rounded-[0.5rem] mb-8">
                <div class="grid gap-4 md:grid-cols-5">
                    <div>
                        <label class="block text-sm leading-6 text-gray-900">{{ $t('Organization') }}</label>
                        <Combobox
                            by="value"
                            :model-value="selectedOrganizationOption"
                            @update:model-value="selectOrganization"
                        >
                            <div class="relative">
                                <div
                                    class="relative w-full cursor-default overflow-hidden rounded-lg bg-white text-left ring-1 shadow-sm sm:text-sm"
                                    :class="form.errors.organization_id ? 'ring-[#b91c1c]' : 'ring-gray-300'"
                                >
                                    <ComboboxInput
                                        class="w-full border-none py-2 pl-3 pr-10 text-sm leading-5 text-gray-900 focus:outline-none focus:ring-0"
                                        :displayValue="option => option?.label || ''"
                                        :placeholder="$t('Search organizations')"
                                        @change="organizationQuery = $event.target.value"
                                    />
                                    <ComboboxButton class="absolute inset-y-0 right-0 flex items-center pr-2">
                                        <ChevronUpDownIcon class="h-5 w-5 text-gray-400" aria-hidden="true"/>
                                    </ComboboxButton>
                                </div>
                                <ComboboxOptions class="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm">
                                    <div
                                        v-if="filteredOrganizationOptions.length === 0"
                                        class="relative cursor-default select-none py-2 px-4 text-gray-700"
                                    >
                                        {{ $t('Nothing found') }}
                                    </div>
                                    <ComboboxOption
                                        v-for="option in filteredOrganizationOptions"
                                        :key="option.value"
                                        :value="option"
                                        as="template"
                                        v-slot="{ selected, active }"
                                    >
                                        <li
                                            class="relative cursor-default select-none py-2 pl-10 pr-4"
                                            :class="active ? 'bg-teal-600 text-white' : 'text-gray-900'"
                                        >
                                            <span class="block truncate" :class="selected ? 'font-medium' : 'font-normal'">
                                                {{ option.label }}
                                            </span>
                                            <span
                                                v-if="selected"
                                                class="absolute inset-y-0 left-0 flex items-center pl-3"
                                                :class="active ? 'text-white' : 'text-teal-600'"
                                            >
                                                <CheckIcon class="h-5 w-5" aria-hidden="true"/>
                                            </span>
                                        </li>
                                    </ComboboxOption>
                                </ComboboxOptions>
                            </div>
                        </Combobox>
                        <div v-if="form.errors.organization_id" class="form-error text-[#b91c1c] text-xs">{{ form.errors.organization_id }}</div>
                    </div>
                    <FormSelect
                        v-model="form.resource"
                        :name="$t('Resource')"
                        :options="props.resources"
                        :error="form.errors.resource"
                        :placeholder="$t('Select resource')"
                    />
                    <FormInput
                        v-model="form.quantity"
                        :name="$t('Quantity')"
                        :type="'number'"
                        :error="form.errors.quantity"
                    />
                    <FormInput
                        v-model="form.valid_until"
                        :name="$t('Valid until')"
                        :type="'datetime-local'"
                        :error="form.errors.valid_until"
                    />
                    <div class="flex items-end">
                        <button
                            class="rounded-md bg-black px-3 py-2 text-sm text-white shadow-sm disabled:opacity-50"
                            :disabled="form.processing"
                        >
                            {{ $t('Grant') }}
                        </button>
                    </div>
                </div>
                <div class="mt-4">
                    <FormInput
                        v-model="form.notes"
                        :name="$t('Notes')"
                        :type="'text'"
                        :error="form.errors.notes"
                    />
                </div>
            </form>

            <div class="mb-4 flex items-center rounded-md bg-white border px-3 py-2 md:max-w-md">
                <input
                    @input="search"
                    v-model="params.search"
                    type="text"
                    class="w-full bg-transparent px-2 text-sm outline-none"
                    :placeholder="$t('Search organizations')"
                >
                <button v-if="!isSearching && params.search" @click="clearSearch" type="button" class="px-2 text-sm text-slate-500">
                    {{ $t('Clear') }}
                </button>
                <span v-if="isSearching" class="px-2 text-sm text-slate-500">{{ $t('Searching') }}...</span>
            </div>

            <div class="bg-white border rounded-[0.5rem] overflow-hidden">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-slate-600">
                        <tr>
                            <th class="px-4 py-3">{{ $t('Organization') }}</th>
                            <th class="px-4 py-3">{{ $t('Resource') }}</th>
                            <th class="px-4 py-3">{{ $t('Quantity') }}</th>
                            <th class="px-4 py-3">{{ $t('Valid until') }}</th>
                            <th class="px-4 py-3 text-right">{{ $t('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in props.rows.data" :key="row.id" class="border-t">
                            <td class="px-4 py-3">{{ row.organization?.name }}</td>
                            <td class="px-4 py-3">{{ resourceLabel(row.resource) }}</td>
                            <td class="px-4 py-3">{{ row.quantity }}</td>
                            <td class="px-4 py-3">{{ row.valid_until || '-' }}</td>
                            <td class="px-4 py-3 text-right">
                                <button @click="deleteRow(row.id)" class="text-red-700 hover:underline">
                                    {{ $t('Delete') }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="props.rows.data.length === 0">
                            <td class="px-4 py-6 text-center text-slate-500" colspan="5">
                                {{ $t('No resource add-ons found.') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import debounce from 'lodash/debounce';
import {
    Combobox,
    ComboboxInput,
    ComboboxButton,
    ComboboxOptions,
    ComboboxOption,
} from '@headlessui/vue';
import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid';
import AppLayout from './../Layout/App.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';

const props = defineProps({
    rows: Object,
    filters: Object,
    organizations: Array,
    resources: Array,
});

const organizationOptions = computed(() =>
    props.organizations.map((organization) => ({
        value: organization.id,
        label: organization.billing_organization_name
            ? `${organization.name} (${organization.billing_organization_name})`
            : organization.name,
        subscription_valid_until: organization.subscription_valid_until,
        billing_organization_name: organization.billing_organization_name,
    })),
);

const organizationQuery = ref('');
const filteredOrganizationOptions = computed(() => {
    const query = organizationQuery.value.toLowerCase().replace(/\s+/g, '');

    if (!query) {
        return organizationOptions.value;
    }

    return organizationOptions.value.filter((option) =>
        option.label.toLowerCase().replace(/\s+/g, '').includes(query),
    );
});

const form = useForm({
    organization_id: null,
    resource: null,
    quantity: 1,
    valid_until: null,
    notes: null,
});

const params = ref({
    search: props.filters?.search || null,
});
const isSearching = ref(false);

const runSearch = () => {
    router.get('/admin/resource-addons', params.value, {
        preserveState: true,
        replace: true,
        onFinish: () => {
            isSearching.value = false;
        },
    });
};

const search = debounce(() => {
    isSearching.value = true;
    runSearch();
}, 500);

const clearSearch = () => {
    params.value.search = null;
    isSearching.value = true;
    runSearch();
};

const selectedOrganizationOption = computed(() =>
    organizationOptions.value.find((organization) => Number(organization.value) === Number(form.organization_id)) || null,
);

const selectOrganization = (organization) => {
    form.organization_id = organization?.value || null;
};

const selectedOrganization = computed(() =>
    props.organizations.find((organization) => Number(organization.id) === Number(form.organization_id)),
);

const toDatetimeLocal = (value) => {
    if (!value) {
        return null;
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return String(value).slice(0, 16);
    }

    const timezoneOffset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() - timezoneOffset).toISOString().slice(0, 16);
};

watch(
    () => form.organization_id,
    () => {
        form.valid_until = toDatetimeLocal(selectedOrganization.value?.subscription_valid_until);
    },
);

const resourceLabel = (value) => {
    return props.resources.find((resource) => resource.value === value)?.label || value;
};

const submitForm = () => {
    form.post('/admin/resource-addons', {
        preserveScroll: true,
        onSuccess: () => form.reset('resource', 'quantity', 'valid_until', 'notes'),
    });
};

const deleteRow = (id) => {
    form.delete('/admin/resource-addons/' + id, {
        preserveScroll: true,
    });
};
</script>
