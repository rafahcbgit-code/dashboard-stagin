<template>
  <div class="cb-conversation-empty flex min-h-48 flex-col items-center justify-center px-6 py-10 text-center">
    <span class="cb-conversation-empty-icon mb-3 flex h-14 w-14 items-center justify-center rounded-2xl" aria-hidden="true">
      <Inbox :size="21" :stroke-width="1.8" />
    </span>
    <h3 class="cb-conversation-empty-title">{{ title }}</h3>
    <p class="cb-conversation-empty-description mt-1">{{ description }}</p>
  </div>
</template>

<script setup>
import { Inbox } from 'lucide-vue-next';

defineProps({
  title: { type: String, required: true },
  description: { type: String, required: true },
});
</script>
