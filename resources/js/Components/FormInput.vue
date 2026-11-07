<script setup>
    import { useId } from 'vue';

    const fieldId = useId();

    const props = defineProps({
        modelValue: [String, Number],
        label: String,
        name: String,
        placeholder: String,
        type: String,
        min: [Number, String],
        max: [Number, String],
        maxLength: [Number, String],
        className: String,
        labelClass: String,
        required: Boolean,
        error: String,
        disabled: Boolean,
        showCharacterCount: Boolean
    })

    const emit = defineEmits(['update:modelValue']);
    const updateValue = (event) => {
        emit('update:modelValue', event.target.value);
    };

    const characterCount = (value) => Array.from(String(value ?? '')).length;
</script>
<template>
    <div :class="className">
        <label :for="fieldId" class="block text-sm leading-6 text-gray-900" :class="labelClass">{{ label ?? name }}</label>
        <div>
            <input
            class="cb-thread-field block min-h-10 w-full rounded-md border bg-white px-3 py-2 text-slate-900 shadow-sm outline-none placeholder:text-slate-400 focus:border-primary focus:ring-2 focus:ring-primary/15 sm:text-sm"
            :id="fieldId"
            :name="name"
            :class="error ? 'border-red-400 ring-2 ring-red-500/10' : 'border-slate-300'"
            :type="type"
            :value="props.modelValue"
            @input="updateValue"
            :step="'any'"
            :min="min"
            :max="max"
            :maxlength="maxLength"
            :placeholder="placeholder"
            :disabled="disabled"
            :required="required"
            />
        </div>
        <div v-if="showCharacterCount && maxLength" class="mt-1 text-right text-[11px] text-slate-400">
            {{ characterCount(props.modelValue) }}/{{ maxLength }}
        </div>
        <div v-if="error" class="form-error text-[#b91c1c] text-xs">{{ error }}</div>
    </div>
</template>
