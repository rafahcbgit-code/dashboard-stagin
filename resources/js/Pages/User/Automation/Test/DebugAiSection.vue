<template>
  <div>
    <button
      @click="expanded = !expanded"
      class="w-full flex items-center justify-between px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
    >
      <div class="flex items-center gap-2">
        <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
        </svg>
        <span>{{ $t("AI Decisions") }}</span>
        <span v-if="aiDecisions.length > 0" class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-medium bg-purple-100 text-purple-700">
          {{ aiDecisions.length }}
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

    <div v-if="expanded" class="px-3 pb-2 space-y-1.5">
      <div v-if="aiDecisions.length === 0" class="text-xs text-gray-400 text-center py-2"> {{ $t("No AI decisions yet") }} </div>
      <div
        v-for="(decision, idx) in aiDecisions"
        :key="idx"
        class="rounded-md px-2.5 py-2"
        :class="decision.type === 'answer' ? 'bg-green-50 border-s-2 border-green-400' : decision.type === 'low_confidence' ? 'bg-amber-50 border-s-2 border-amber-400' : decision.type === 'escalation' ? 'bg-red-50 border-s-2 border-red-400' : 'bg-gray-50 border-s-2 border-gray-300'"
      >
        <div class="flex items-center gap-1.5 mb-0.5">
          <div
            class="w-2 h-2 rounded-full"
            :class="decision.type === 'answer' ? 'bg-green-500' : decision.type === 'low_confidence' ? 'bg-amber-500' : decision.type === 'escalation' ? 'bg-red-500' : 'bg-gray-400'"
          ></div>
          <span class="text-xs font-medium text-gray-800">
            {{ decision.type === 'answer' ? 'إجابة الذكاء الاصطناعي' : decision.type === 'low_confidence' ? 'ثقة منخفضة' : decision.type === 'escalation' ? 'التصعيد' : 'لا يوجد تطابق' }}
          </span>
          <span v-if="decision.confidence" class="text-[10px] px-1.5 py-0.5 rounded font-medium"
            :class="decision.type === 'low_confidence' ? 'bg-amber-100 text-amber-700' : 'bg-purple-100 text-purple-600'"
          >
            {{ decision.confidence }}{{ $t("% confidence") }} </span>
        </div>
        <div v-if="decision.content" class="text-[11px] text-gray-600 ms-3.5">{{ decision.content }}</div>
        <div v-if="decision.type === 'low_confidence'" class="text-[10px] text-amber-600 ms-3.5 mt-0.5"> {{ $t("Below confidence threshold — answer shown in chat but would not auto-send to customers") }} </div>
        <div v-if="decision.kb_source" class="text-[11px] text-gray-400 ms-3.5">{{ $t("Source:") }} {{ decision.kb_source }}</div>
        <div v-if="decision.escalation_name" class="text-[11px] text-gray-500 ms-3.5">{{ $t("Escalation rule:") }} {{ decision.escalation_name }} ({{ decision.escalation_target }})</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  aiDecisions: { type: Array, default: () => [] },
})

const expanded = ref(false)

watch(() => props.aiDecisions.length, (newLen) => {
  if (newLen > 0) {
    expanded.value = true
  }
})
</script>