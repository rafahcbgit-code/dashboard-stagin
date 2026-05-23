<template>
  <Layout :fbmodule="fbmodule">
    <div class="flex flex-col h-[calc(100vh-4rem)]">
      <!-- Toolbar -->
      <div class="w-full flex items-center justify-between px-1 mb-3">
        <div>
          <h3 class="text-lg font-semibold text-gray-900">Automation Test</h3>
          <p class="text-sm text-gray-500 mt-0.5">Test how your automations respond. No real messages are sent.</p>
        </div>
        <div class="flex items-center gap-2">
          <label class="flex items-center gap-1.5 text-sm cursor-pointer select-none">
            <input type="checkbox" v-model="isNewContact" class="rounded border-gray-300 text-[#034737] focus:ring-[#034737]" />
            <span class="text-gray-700">New contact</span>
          </label>
          <label class="flex items-center gap-1.5 text-sm cursor-pointer select-none">
            <input type="checkbox" v-model="duringWorkingHours" class="rounded border-gray-300 text-[#034737] focus:ring-[#034737]" />
            <span class="text-gray-700">Working hours</span>
          </label>
          <button
            @click="showDebug = !showDebug"
            class="text-sm text-gray-500 hover:text-gray-700 flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors"
            :class="{ 'bg-gray-100 border-gray-300 text-gray-700': showDebug }"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
            </svg>
            {{ showDebug ? 'Hide' : 'Show' }} Debug
          </button>
          <button
            v-if="chatMessages.length > 0"
            @click="resetChat"
            class="text-sm text-gray-400 hover:text-red-500 transition-colors flex items-center gap-1 px-3 py-1.5 rounded-lg border border-gray-200 hover:border-red-200"
          >
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Reset
          </button>
        </div>
      </div>

      <!-- Main content: Chat + Debug -->
      <div class="flex-1 flex flex-col lg:flex-row gap-4 min-h-0 px-1">
        <!-- Chat Panel -->
        <div class="flex-1 min-w-0 transition-all duration-200">
          <TestChatPanel
            :org-name="orgName"
            :automation-enabled="automationEnabled"
            :within-working-hours="withinWorkingHours"
            :during-working-hours="duringWorkingHours"
            :ai-enabled="aiEnabled"
            :aimodule="aimodule"
            :messages="chatMessages"
            :is-typing="isTyping"
            :input-message="inputMessage"
            :input-hint="activeInputHint"
            @update:input-message="inputMessage = $event"
            @send="sendMessage"
            @select-option="selectOption"
          />
        </div>

        <!-- Debug Panel -->
        <transition
          enter-active-class="transition-all duration-200 ease-out"
          leave-active-class="transition-all duration-200 ease-in"
          enter-from-class="opacity-0 lg:w-0"
          enter-to-class="opacity-100"
          leave-from-class="opacity-100"
          leave-to-class="opacity-0 lg:w-0"
        >
          <div v-if="showDebug" class="w-full lg:w-[420px] shrink-0 transition-all duration-200">
            <TestDebugPanel
              :debug-entries="debugEntries"
              :trigger-traces="triggerTraces"
              :flow-steps="flowSteps"
              :ai-decisions="aiDecisions"
              :variables="variables"
              :bot-settings="botSettings"
              :session-info="sessionInfo"
              :working-hours="workingHours"
              :unmatched-message-count="unmatchedMessageCount"
              @close="showDebug = false"
            />
          </div>
        </transition>
      </div>
    </div>
  </Layout>
</template>

<script setup>
import { ref } from 'vue'
import Layout from '../Layout.vue'
import TestChatPanel from './TestChatPanel.vue'
import TestDebugPanel from './TestDebugPanel.vue'
import { useAutomationTest } from '@/Composables/useAutomationTest'

const props = defineProps({
  flows: { type: Array, default: () => [] },
  aiEnabled: { type: Boolean, default: false },
  aiFallbackEnabled: { type: Boolean, default: false },
  withinWorkingHours: { type: Boolean, default: true },
  automationEnabled: { type: Boolean, default: true },
  aimodule: { type: Boolean, default: false },
  fbmodule: { type: Boolean, default: false },
  orgName: { type: String, default: 'ChatBerry' },
})

const {
  isNewContact,
  duringWorkingHours,
  showDebug,
  inputMessage,
  isTyping,
  chatMessages,
  debugEntries,
  triggerTraces,
  flowSteps,
  aiDecisions,
  variables,
  botSettings,
  sessionInfo,
  unmatchedMessageCount,
  activeInputHint,
  workingHours,
  sendMessage,
  selectOption,
  testAiDirectly,
  resetChat,
} = useAutomationTest(props)
</script>