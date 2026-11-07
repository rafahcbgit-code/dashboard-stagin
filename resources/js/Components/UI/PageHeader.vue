<template>
  <header class="cb-thread-page-header flex flex-wrap items-center justify-between gap-4 border-b border-slate-200/80 bg-white px-5 py-4 md:px-7">
    <div class="min-w-0">
      <h1 class="truncate text-xl font-semibold text-slate-900"><slot name="title">{{ title }}</slot></h1>
      <p v-if="description" class="mt-1 text-sm text-slate-500">{{ description }}</p>
    </div>
    <div class="flex items-center gap-2"><slot name="actions" /></div>
  </header>
</template>

<script setup>
defineProps({ title: String, description: String });
</script>
