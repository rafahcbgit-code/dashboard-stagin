<template>
  <span :class="['cb-thread-status inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium', styles]" :data-tone="status">
    <span v-if="dot" class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true" />
    <slot>{{ label }}</slot>
  </span>
</template>

<script setup>
import { computed } from 'vue';
const props = defineProps({ label: String, status: { type: String, default: 'neutral' }, dot: Boolean });
const styles = computed(() => ({
  success: 'bg-emerald-50 text-emerald-700', warning: 'bg-amber-50 text-amber-700',
  danger: 'bg-red-50 text-red-700', info: 'bg-blue-50 text-blue-700',
  neutral: 'bg-slate-100 text-slate-600', primary: 'bg-emerald-50 text-emerald-700',
}[props.status] || 'bg-slate-100 text-slate-600'));
</script>
