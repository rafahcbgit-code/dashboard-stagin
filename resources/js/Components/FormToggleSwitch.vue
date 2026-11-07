<script setup>
import { ref } from 'vue';

const props = defineProps({
    modelValue: Boolean,
    className: String,
    disabled: {
        type: Boolean,
        default: false
    },
})

const emit = defineEmits(['update:modelValue']);
const updateValue = () => {
    if (props.disabled == false) {
        emit('update:modelValue', !props.modelValue);
    }
};
</script>
<template>
    <div :class="className">
        <button type="button" role="switch" class="cb-thread-switch" :aria-checked="Boolean(props.modelValue)"
            :disabled="disabled" @click="updateValue">
            <span class="cb-thread-switch-thumb"></span>
        </button>
    </div>
</template>
