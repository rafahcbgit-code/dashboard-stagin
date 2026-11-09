<template>
  <div>
    <button
      @click="expanded = !expanded"
      class="w-full flex items-center justify-between px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
    >
      <div class="flex items-center gap-2">
        <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M9 5l7 7-7 7"/>
        </svg>
        <span>{{ $t("Trigger Trace") }}</span>
        <span v-if="traces.length > 0" class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-medium bg-green-100 text-green-700">
          {{ traces.length }}
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

    <div v-if="expanded" class="px-3 pb-2 space-y-1">
      <div v-if="traces.length === 0" class="text-xs text-gray-400 text-center py-2"> {{ $t("No trigger evaluation yet") }} </div>
      <div
        v-for="(trace, idx) in traces"
        :key="idx"
        class="rounded-md px-2.5 py-2"
        :class="trace.matched ? 'bg-green-50 border-s-2 border-green-400' : 'bg-gray-50 border-s-2 border-gray-300'"
      >
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-1.5">
            <div
              class="w-2 h-2 rounded-full shrink-0"
              :class="trace.matched ? 'bg-green-500' : 'bg-gray-300'"
            ></div>
            <span class="text-xs font-medium text-gray-800">{{ trace.name }}</span>
          </div>
          <div class="flex items-center gap-1">
            <span class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-gray-100 text-gray-500">{{ trace.type }}</span>
            <span v-if="trace.match_mode" class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-purple-100 text-purple-600">{{ trace.match_mode }}</span>
          </div>
        </div>
        <div v-if="trace.reason" class="text-[11px] text-gray-500 mt-0.5 ms-3.5">
          {{ trace.reason }}
        </div>
        <div v-if="trace.keywords && trace.keywords.length > 0" class="text-[11px] text-gray-400 mt-0.5 ms-3.5"> {{ $t("Keywords:") }} {{ trace.keywords.join(', ') }}
        </div>
        <div v-if="trace.rate_limit_type" class="text-[11px] text-gray-400 mt-0.5 ms-3.5"> {{ $t("Rate limit:") }} {{ trace.rate_limit_type }}
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  traces: { type: Array, default: () => [] },
})

const expanded = ref(false)

watch(() => props.traces.length, (newLen) => {
  if (newLen > 0) {
    expanded.value = true
  }
})
</script>