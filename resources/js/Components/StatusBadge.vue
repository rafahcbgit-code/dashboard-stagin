<template>
    <span class="cb-thread-status inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold" :data-tone="tone" :class="toneClass">
        {{ label }}
    </span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    tone: { type: String, default: 'neutral', validator: (value) => ['success', 'warning', 'error', 'neutral'].includes(value) },
    label: { type: String, required: true },
});

const toneClass = computed(() => ({
    success: 'bg-green-100 text-green-800',
    warning: 'bg-amber-100 text-amber-900',
    error: 'bg-red-100 text-red-800',
    neutral: 'bg-slate-100 text-slate-700',
}[props.tone]));
</script>
