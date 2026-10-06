<script setup>
import { computed, getCurrentInstance, ref } from 'vue';
import {
    Combobox,
    ComboboxButton,
    ComboboxInput,
    ComboboxOption,
    ComboboxOptions,
    TransitionRoot,
} from '@headlessui/vue';
import { CheckIcon, MagnifyingGlassIcon } from '@heroicons/vue/20/solid';

const props = defineProps({
    modelValue: [String, Number],
    name: { type: String, default: '' },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: '' },
    searchPlaceholder: { type: String, default: '' },
    searchLabel: { type: String, default: '' },
    emptyLabel: { type: String, default: '' },
    error: String,
    className: String,
});

const emit = defineEmits(['update:modelValue']);
const query = ref('');
const inputId = `searchable-select-${getCurrentInstance()?.uid ?? 'country'}`;

const selectedOption = computed(() => props.options.find((option) => String(option.value) === String(props.modelValue)) ?? null);
const normalizeSearchText = (value) => String(value ?? '')
    .normalize('NFKD')
    .replace(/[\u0300-\u036f\u064b-\u065f]/g, '')
    .toLocaleLowerCase()
    .trim();

const filteredOptions = computed(() => {
    const normalizedQuery = normalizeSearchText(query.value);

    if (!normalizedQuery) return props.options;

    return props.options.filter((option) => normalizeSearchText(`${option.label} ${option.searchText ?? ''} ${option.value}`).includes(normalizedQuery));
});

const updateValue = (option) => emit('update:modelValue', option?.value ?? null);
const clearSearchOnFocus = (event) => {
    query.value = '';

    if (selectedOption.value) {
        event.target.value = '';
    }
};
</script>

<template>
    <div :class="className">
        <label v-if="name" :for="inputId" class="mb-1 block text-sm leading-6 text-gray-900">{{ name }}</label>
        <Combobox :model-value="selectedOption" by="value" @update:model-value="updateValue">
            <div class="relative">
                <div class="relative w-full overflow-hidden rounded-lg bg-white shadow-sm ring-1" :class="error ? 'ring-[#b91c1c]' : 'ring-gray-300'">
                    <ComboboxInput
                        :id="inputId"
                        class="w-full border-0 bg-transparent py-2 ltr:pl-3 ltr:pr-11 rtl:pl-11 rtl:pr-3 text-start text-sm leading-5 text-gray-900 focus:outline-none focus:ring-0"
                        :display-value="(option) => option?.label ?? ''"
                        :placeholder="searchPlaceholder || placeholder"
                        :aria-label="name || searchPlaceholder"
                        @focus="clearSearchOnFocus"
                        @change="query = $event.target.value"
                    />
                    <ComboboxButton
                        type="button"
                        class="absolute inset-y-0 flex items-center ltr:right-0 ltr:pr-3 rtl:left-0 rtl:pl-3 text-gray-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                        :aria-label="searchLabel"
                        :title="searchLabel"
                    >
                        <MagnifyingGlassIcon class="size-5" aria-hidden="true" />
                    </ComboboxButton>
                </div>

                <TransitionRoot leave="transition ease-in duration-100" leave-from="opacity-100" leave-to="opacity-0" @after-leave="query = ''">
                    <ComboboxOptions class="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-md bg-white py-1 text-start text-sm shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none">
                        <div v-if="filteredOptions.length === 0" class="px-4 py-2 text-sm text-gray-600">
                            {{ emptyLabel }}
                        </div>
                        <ComboboxOption v-for="option in filteredOptions" :key="option.value" :value="option" as="template" v-slot="{ selected, active }">
                            <li
                                dir="auto"
                                class="relative cursor-default select-none py-2 ltr:pl-10 ltr:pr-4 rtl:pl-4 rtl:pr-10"
                                :class="active ? 'bg-emerald-700 text-white' : 'text-gray-900'"
                            >
                                <span class="block truncate" :class="selected ? 'font-semibold' : 'font-normal'">{{ option.label }}</span>
                                <span v-if="selected" class="absolute inset-y-0 flex items-center ltr:left-0 ltr:pl-3 rtl:right-0 rtl:pr-3" :class="active ? 'text-white' : 'text-emerald-700'">
                                    <CheckIcon class="size-5" aria-hidden="true" />
                                </span>
                            </li>
                        </ComboboxOption>
                    </ComboboxOptions>
                </TransitionRoot>
            </div>
        </Combobox>
        <p v-if="error" class="mt-1 text-xs text-[#b91c1c]" role="alert">{{ error }}</p>
    </div>
</template>
