<template>
  <div class="flex flex-col h-full min-h-[300px] lg:min-h-0 bg-white rounded-xl overflow-hidden shadow-sm border border-gray-200">
    <!-- Header -->
    <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex items-center justify-between shrink-0">
      <div class="flex items-center gap-2">
        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
        </svg>
        <h4 class="text-sm font-medium text-gray-700">Debug</h4>
      </div>
      <button @click="$emit('close')" class="text-gray-400 hover:text-gray-600 transition-colors lg:hidden">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M18 6L6 18M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <!-- Scrollable sections -->
    <div class="flex-1 overflow-y-auto">
      <!-- Quick log entries (raw debug entries) -->
      <div v-if="debugEntries.length > 0" class="border-b border-gray-100">
        <button
          @click="logExpanded = !logExpanded"
          class="w-full flex items-center justify-between px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
        >
          <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <span>Activity Log</span>
            <span class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-medium bg-gray-200 text-gray-600">
              {{ debugEntries.length }}
            </span>
          </div>
          <svg
            class="w-4 h-4 text-gray-400 transition-transform"
            :class="{ 'rotate-90': logExpanded }"
            fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
          >
            <path d="M9 5l7 7-7 7"/>
          </svg>
        </button>

        <div v-if="logExpanded" class="px-3 pb-2 space-y-1 max-h-[200px] overflow-y-auto">
          <div v-for="(entry, idx) in debugEntries" :key="idx" :class="debugEntryClass(entry.type)" class="rounded-md px-2.5 py-1.5">
            <div class="flex items-start gap-1.5">
              <div class="mt-0.5 shrink-0">
                <div v-if="entry.type === 'success'" class="w-1.5 h-1.5 rounded-full bg-green-500"></div>
                <div v-else-if="entry.type === 'warning'" class="w-1.5 h-1.5 rounded-full bg-amber-500"></div>
                <div v-else-if="entry.type === 'error'" class="w-1.5 h-1.5 rounded-full bg-red-500"></div>
                <div v-else class="w-1.5 h-1.5 rounded-full bg-blue-400"></div>
              </div>
              <div class="min-w-0">
                <div class="font-medium text-gray-800 text-[11px]">{{ entry.title }}</div>
                <div v-if="entry.detail" class="text-[10px] text-gray-500 mt-0.5 break-words">{{ entry.detail }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Structured debug sections -->
      <DebugTraceSection :traces="triggerTraces" />
      <DebugFlowSection :flow-steps="flowSteps" />
      <DebugAiSection :ai-decisions="aiDecisions" />
      <DebugVariablesSection :variables="variables" :contact-context="contactContext" />
      <DebugSessionSection :bot-settings="botSettings" :session-info="sessionInfo" :working-hours="workingHours" :unmatched-message-count="unmatchedMessageCount" />
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import DebugTraceSection from './DebugTraceSection.vue'
import DebugFlowSection from './DebugFlowSection.vue'
import DebugAiSection from './DebugAiSection.vue'
import DebugVariablesSection from './DebugVariablesSection.vue'
import DebugSessionSection from './DebugSessionSection.vue'

const props = defineProps({
  debugEntries: { type: Array, required: true },
  triggerTraces: { type: Array, default: () => [] },
  flowSteps: { type: Array, default: () => [] },
  aiDecisions: { type: Array, default: () => [] },
  variables: { type: Array, default: () => [] },
  contactContext: { type: Object, default: null },
  botSettings: { type: Object, default: () => ({}) },
  sessionInfo: { type: Object, default: null },
  workingHours: { type: Object, default: null },
  unmatchedMessageCount: { type: Number, default: 0 },
})

defineEmits(['close'])

const logExpanded = ref(false)

const debugEntryClass = (type) => {
  switch (type) {
    case 'success': return 'bg-green-50'
    case 'warning': return 'bg-amber-50'
    case 'error': return 'bg-red-50'
    default: return 'bg-blue-50'
  }
}
</script>