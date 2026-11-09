<template>
  <div>
    <button
      @click="expanded = !expanded"
      class="w-full flex items-center justify-between px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
    >
      <div class="flex items-center gap-2">
        <svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M4 7V4a1 1 0 011-1h14a1 1 0 011 1v3M4 7v10a2 2 0 002 2h12a2 2 0 002-2V7M4 7h16"/>
          <path d="M10 11h4"/>
        </svg>
        <span>{{ $t("Variables") }}</span>
        <span v-if="variables.length > 0" class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-medium bg-amber-100 text-amber-700">
          {{ variables.length }}
        </span>
      </div>
      <svg
        class="w-4 h-4 text-gray-400 transition-transform"
        :class="{ 'rotate-90': expanded }"
        fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
      >
        <path d="M9 5l7 7-7 7"/>
      </svg>
    </button>

    <div v-if="expanded" class="px-3 pb-2">
      <div v-if="variables.length === 0" class="text-xs text-gray-400 text-center py-2"> {{ $t("No variables set yet") }} </div>
      <div v-else class="space-y-1">
        <div
          v-for="(variable, idx) in variables"
          :key="idx"
          class="flex items-center justify-between rounded-md px-2.5 py-1.5 bg-amber-50 border border-amber-100"
        >
          <span class="text-xs font-mono text-gray-700">{{ variable.name }}</span>
          <span class="text-xs text-gray-500">{{ variable.value }}</span>
        </div>
      </div>
      <div v-if="contactContext" class="mt-2 pt-2 border-t border-gray-100">
        <div class="text-[10px] font-medium text-gray-400 uppercase tracking-wider mb-1">{{ $t("Contact Context") }}</div>
        <div class="space-y-1">
          <div v-for="(value, key) in contactContext" :key="key" class="flex items-center justify-between">
            <span class="text-xs font-mono text-gray-600">{{ key }}</span>
            <span class="text-xs text-gray-500">{{ value }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'

defineProps({
  variables: { type: Array, default: () => [] },
  contactContext: { type: Object, default: null },
})

const expanded = ref(false)
</script>