<script setup>
    import { computed, ref } from 'vue';
    import {
      Listbox,
      ListboxLabel,
      ListboxButton,
      ListboxOptions,
      ListboxOption,
    } from '@headlessui/vue'
    import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid';
    import { trans } from 'laravel-vue-i18n';

    const props = defineProps({
      options: Array,
      modelValue: [String, Number, Array],
      name: String,
      className: String,
      optionClassName: String,
      placeholder: {
        type: String, 
        default: () => trans('Select option')
      },
      multiple: Boolean,
      required: Boolean,
      error: String,
      disabled: Boolean,
      native: Boolean
    })

    const emit = defineEmits(['update:modelValue'])

    const label = computed(() => {
      return props.options.filter(option => {
        if(Array.isArray(props.modelValue)){
          return props.modelValue.includes(option.value);
        }

        return props.modelValue === option.value;
      }).map(option => option.label).join(', ');
    })
</script>
<template>
    <div :class="className">
        <label for="name" class="block text-sm leading-6 text-gray-900">{{ name }}</label>
        <div v-if="disabled == true">
            <input
            class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6"
            :class="error ? 'ring-[#b91c1c]' : 'ring-gray-300'"
            :type="'text'"
            :value="label"
            :step="'any'"
            :placeholder="placeholder"
            :disabled="true"
            />
        </div>
        <div v-else-if="native == true">
            <select
            class="block w-full rounded-lg border-0 bg-white py-2 px-5 text-gray-900 shadow-sm outline-none ring-1 ring-inset focus:ring-2 focus:ring-indigo-600 sm:text-sm"
            :class="error ? 'ring-[#b91c1c]' : 'ring-gray-300'"
            :value="props.modelValue ?? ''"
            :required="required"
            @change="event => emit('update:modelValue', event.target.value || null)"
            >
                <option value="" disabled>{{ props.placeholder }}</option>
                <option
                v-for="option in props.options"
                :key="option.value"
                :value="option.value"
                :title="option.description || undefined"
                >
                    {{ option.label }}
                </option>
            </select>
        </div>
        <div v-else>
            <Listbox 
                :multiple="props.multiple"
                @update:modelValue="value => emit('update:modelValue', value)"
                :model-value="props.modelValue">
                <div class="relative">
                    <ListboxButton
                    class="relative w-full cursor-default rounded-lg bg-white py-2 ltr:pr-10 rtl:pl-10 px-5 text-start shadow-sm ring-1 ring-inset focus:outline-none focus-visible:border-indigo-500 focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-opacity-75 focus-visible:ring-offset-2 focus-visible:ring-offset-orange-300 sm:text-sm"
                    :class="error ? 'ring-[#b91c1c]' : 'ring-gray-300'"
                    >
                    <span class="block truncate" v-if="label">{{ label }}</span>
                    <span v-else class="text-gray-500">{{ props.placeholder }}</span>
                    <span
                        class="pointer-events-none absolute inset-y-0 ltr:right-0 rtl:left-0 flex items-center ltr:pr-2 rtl:pl-2"
                    >
                        <ChevronUpDownIcon
                        class="h-5 w-5 text-gray-400"
                        aria-hidden="true"
                        />
                    </span>
                    </ListboxButton>

                    <transition
                    leave-active-class="transition duration-100 ease-in"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                    >
                    <ListboxOptions
                        class="z-20 absolute mt-1 max-h-60 w-max min-w-full max-w-[90vw] overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm ltr:left-0 rtl:right-0" :class="optionClassName"
                    >
                        <ListboxOption
                        v-slot="{ active, selected }"
                        v-for="option in props.options"
                        :key="option.value"
                        :value="option.value"
                        as="template"
                        >
                        <li :title="option.description || undefined" :class="[active ? 'bg-amber-100 text-amber-900' : 'text-gray-900','relative cursor-default select-none whitespace-normal break-words py-2 ltr:pl-10 ltr:pr-4 rtl:pl-4 rtl:pr-10 text-start']">
                            <span :class="[selected ? 'font-medium' : 'font-normal', 'block']">{{ option.label }}</span>
                            <span v-if="active && option.description" role="tooltip" class="mt-1 block text-xs leading-4 text-slate-600">{{ option.description }}</span>
                            <span v-if="selected" class="absolute inset-y-0 ltr:left-0 rtl:right-0 flex items-center ltr:pl-3 rtl:pr-3 text-amber-600">
                                <CheckIcon class="h-5 w-5" aria-hidden="true" />
                            </span>
                        </li>
                        </ListboxOption>
                    </ListboxOptions>
                    </transition>
                </div>
            </Listbox>
        </div>
        <div v-if="error" class="form-error text-[#b91c1c] text-xs">{{ error }}</div>
    </div>
</template>
