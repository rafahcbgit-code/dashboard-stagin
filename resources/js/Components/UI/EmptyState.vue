<template>
  <div class="cb-thread-empty flex min-h-48 flex-col items-center justify-center px-6 py-10 text-center">
    <div class="cb-thread-empty-icon mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600"><slot name="icon">⌁</slot></div>
    <h3 class="font-semibold text-slate-800">{{ title }}</h3>
    <p v-if="description" class="mt-1 max-w-sm text-sm text-slate-500">{{ description }}</p>
    <div class="mt-4"><slot name="action" /></div>
  </div>
</template>

<script setup>
defineProps({ title: { type: String, default: 'Nothing here yet' }, description: String });
</script>
