<template>
  <div>
    <button
      @click="expanded = !expanded"
      class="w-full flex items-center justify-between px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
    >
      <div class="flex items-center gap-2">
        <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/>
        </svg>
        <span>Flow Execution</span>
        <span v-if="flowSteps.length > 0" class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-medium bg-blue-100 text-blue-700">
          {{ flowSteps.length }}
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
      <div v-if="flowSteps.length === 0" class="text-xs text-gray-400 text-center py-2">
        No flow execution yet
      </div>
      <div class="relative pl-4">
        <!-- Timeline line -->
        <div class="absolute left-[7px] top-2 bottom-2 w-px bg-gray-200"></div>

        <div
          v-for="(step, idx) in flowSteps"
          :key="idx"
          class="relative flex items-start gap-2 pb-2 last:pb-0"
        >
          <!-- Timeline dot -->
          <div class="mt-1.5 shrink-0 z-10">
            <div v-if="step.category === 'message_sent'" class="w-3 h-3 rounded-full bg-green-500 border-2 border-white"></div>
            <div v-else-if="step.category === 'waiting_for_input'" class="w-3 h-3 rounded-full bg-amber-500 border-2 border-white animate-pulse"></div>
            <div v-else-if="step.category === 'action_executed'" class="w-3 h-3 rounded-full bg-purple-500 border-2 border-white"></div>
            <div v-else-if="step.category === 'flow_ended'" class="w-3 h-3 rounded-full bg-red-400 border-2 border-white"></div>
            <div v-else class="w-3 h-3 rounded-full bg-gray-400 border-2 border-white"></div>
          </div>

          <!-- Step content -->
          <div class="flex-1 min-w-0">
            <div class="text-xs font-medium text-gray-700">{{ step.description || step.node_type }}</div>
            <div class="text-[10px] text-gray-400 flex items-center gap-1.5">
              <span class="inline-flex px-1 py-0.5 rounded bg-gray-100 text-gray-500 font-medium">{{ step.node_type }}</span>
              <span v-if="step.category" class="inline-flex px-1 py-0.5 rounded bg-gray-100 text-gray-500">{{ step.category }}</span>
            </div>
            <div v-if="step.condition_result" class="text-[11px] mt-0.5" :class="step.condition_result.result ? 'text-green-600' : 'text-red-500'">
              Condition: {{ step.condition_result.expression }} → {{ step.condition_result.result ? 'True' : 'False' }} ({{ step.condition_result.branch }})
            </div>
            <div v-if="step.validation_rules" class="text-[11px] text-gray-500 mt-0.5">
              <div class="flex items-center gap-2">
                <span v-if="step.validation_rules.input_type" class="inline-flex px-1 py-0.5 rounded bg-blue-50 text-blue-600">Input: {{ step.validation_rules.input_type }}</span>
                <span v-if="step.validation_rules.variable_name" class="inline-flex px-1 py-0.5 rounded bg-purple-50 text-purple-600">Saves: {{ step.validation_rules.variable_name }}</span>
                <span v-if="step.validation_rules.retry_limit" class="inline-flex px-1 py-0.5 rounded bg-amber-50 text-amber-600">Retries: {{ step.validation_rules.retry_limit }}</span>
              </div>
            </div>
            <div v-if="step.variables_set && step.variables_set.length > 0" class="text-[11px] text-gray-500 mt-0.5">
              Variables: {{ step.variables_set.map(v => `${v.name}="${v.value}"`).join(', ') }}
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  flowSteps: { type: Array, default: () => [] },
})

const expanded = ref(false)

watch(() => props.flowSteps.length, (newLen) => {
  if (newLen > 0) {
    expanded.value = true
  }
})
</script>