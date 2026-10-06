<script setup>
    import {ref, computed, watch} from "vue";
    import {trans} from 'laravel-vue-i18n';
    import {
        Combobox,
        ComboboxInput,
        ComboboxButton,
        ComboboxOptions,
        ComboboxOption,
        TransitionRoot,
    } from "@headlessui/vue";
    import {CheckIcon, ChevronUpDownIcon} from "@heroicons/vue/20/solid";

    const emit = defineEmits(["update:modelValue"]);

    const props = defineProps({
        modelValue: Object,
        name: String,
        className: String,
        placeholder: {
            type: String, 
            default: () => trans('Select option')
        },
        options: {
            type: Array,
            default: () => [],
        },
        loadOptions: Function,
        createOption: Function,
        error: String,
    });

    const options = ref([]);
    const isLoading = ref(false);

    const queryOption = computed(() => {
        return query.value === ""
            ? null
            : {
                missing: true,
                label: query.value,
            };
    });

    let query = ref("");
    watch(query, q => {
        if (props.loadOptions) {
            isLoading.value = true;
            
            props.loadOptions(q, results => {
                options.value = Object.values(results).map(user => {
                    return {
                        value: user.id,
                        label: `${user.first_name} ${user.last_name}`
                    };
                });

                isLoading.value = false;
            });
        }
    },
    {immediate: true}
    );

    let filteredOptions = computed(() =>
    query.value === ""
        ? options.value
        : options.value.filter(option =>
            option.label
            .toLowerCase()
            .replace(/\s+/g, "")
            .includes(query.value.toLowerCase().replace(/\s+/g, ""))
        )
    );

    function handleUpdateModelValue(selected) {
        emit("update:modelValue", selected);

        if (props.createOption && selected?.missing) {
            isLoading.value = true;
            props.createOption(selected, option => {
                emit("update:modelValue", option);
                isLoading.value = false;
            });
        }
    }
</script>

<template>
    <div :class="className">
        <label for="name" class="block text-sm leading-6 text-gray-900">{{ name }}</label>
        <div>
            <Combobox 
            by="value" 
            :model-value="props.modelValue" 
            @update:model-value="handleUpdateModelValue">
                <div class="relative">
                    <div 
                    class="relative w-full cursor-default overflow-hidden rounded-lg bg-white text-start ring-1 shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-opacity-75 focus-visible:ring-offset-2 focus-visible:ring-offset-teal-300 sm:text-sm"
                    :class="error ? 'ring-[#b91c1c]' : 'ring-gray-300'">
                        <ComboboxInput
                        class="w-full border-none py-2 ltr:pl-3 rtl:pr-3 ltr:pr-10 rtl:pl-10 text-start text-sm leading-5 text-gray-900 focus:outline-none focus:ring-0"
                        :displayValue="option => option?.label"
                        dir="auto"
                        @change="query = $event.target.value"
                        />
                        <ComboboxButton class="absolute inset-y-0 ltr:right-0 rtl:left-0 flex items-center ltr:pr-2 rtl:pl-2">
                            <ChevronUpDownIcon class="h-5 w-5 text-gray-400" aria-hidden="true"/>
                        </ComboboxButton>
                    </div>
                    <TransitionRoot
                        leave="transition ease-in duration-100"
                        leaveFrom="opacity-100"
                        leaveTo="opacity-0"
                        @after-leave="query = ''"
                    >
                        <ComboboxOptions
                        class="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm"
                        >
                        <div
                            v-if="
                            filteredOptions.length === 0 &&
                            !isLoading &&
                            !queryOption &&
                            !props.createOption
                            "
                            class="relative cursor-default select-none py-2 px-4 text-gray-700"
                        >
                            {{ $t('Nothing found') }}
                        </div>

                        <div
                            v-if="isLoading"
                            class="relative cursor-default select-none py-2 px-4 text-gray-700"
                        >
                            {{ $t('Loading...') }}
                        </div>

                        <template v-if="!isLoading">
                            <ComboboxOption
                            v-if="
                                queryOption && !filteredOptions.length && props.createOption
                            "
                            as="template"
                            :value="queryOption"
                            v-slot="{active}"
                            >
                            <li
                                class="relative cursor-default select-none py-2 ltr:pl-10 rtl:pr-10 ltr:pr-4 rtl:pl-4"
                                :class="{
                                'bg-teal-600 text-white': active,
                                'text-gray-900': !active,
                                }"
                            >
                                {{ $t('Create') }} "{{ queryOption.label }}"
                            </li>
                            </ComboboxOption>
                            <ComboboxOption
                            v-for="option in filteredOptions"
                            as="template"
                            :key="option.value"
                            :value="option"
                            v-slot="{selected, active}"
                            >
                                <li
                                    class="relative cursor-default select-none py-2 ltr:pl-10 rtl:pr-10 ltr:pr-4 rtl:pl-4"
                                    :class="{
                                    'bg-teal-600 text-white': active,
                                    'text-gray-900': !active,
                                    }"
                                >
                                    <span
                                    class="block truncate"
                                    :class="{'font-medium': selected, 'font-normal': !selected}"
                                    >
                                    {{ option.label }}
                                    </span>
                                    <span
                                    v-if="selected"
                                    class="absolute inset-y-0 ltr:left-0 rtl:right-0 flex items-center ltr:pl-3 rtl:pr-3"
                                    :class="{'text-white': active, 'text-teal-600': !active}"
                                    >
                                        <CheckIcon class="h-5 w-5" aria-hidden="true"/>
                                    </span>
                                </li>
                            </ComboboxOption>
                        </template>
                        </ComboboxOptions>
                    </TransitionRoot>
                </div>
            </Combobox>
        </div>
        <div v-if="error" class="form-error text-[#b91c1c] text-xs">{{ error }}</div>
    </div>
</template>
