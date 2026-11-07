<script setup>
    import { computed, useId } from 'vue';

    const fieldId = useId();

    const props = defineProps({
        modelValue: [String, Number, Boolean],
        value: { type: [Boolean, Object] },
        name: String,
        className: String,
        label: String,
        disabled: Boolean
    })

    const emit = defineEmits(['update:modelValue']);
    const model = computed({
        get() {
            return props.modelValue;
        },
        set(value) {
            emit("update:modelValue", value);
        },
    });
</script>
<template>
    <div :class="className">
        <div class="relative flex mb-2">
            <div class="flex h-6 items-center mr-2">
                <input 
                    v-model="model"
                    :value="value"
                    :id="fieldId"
                    :name="name"
                    :disabled="disabled"
                    type="checkbox" 
                    class="h-[18px] w-[18px] rounded border-slate-300 text-primary focus:ring-primary"
                >
            </div>
            <div class="text-sm leading-6">
                <label :for="fieldId" class="text-gray-900 capitalize">{{ label }}</label>
            </div>
        </div>
    </div>
</template>
