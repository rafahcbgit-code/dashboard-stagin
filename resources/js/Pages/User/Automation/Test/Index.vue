<template>
  <Layout :fbmodule="fbmodule">
    <div class="relative flex flex-col h-[calc(100vh-4rem)]">
      <!-- Toolbar -->
      <div class="w-full flex items-center justify-between px-1 mb-3 shrink-0">
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
            @click="showOverrides = !showOverrides"
            class="text-sm text-gray-500 hover:text-gray-700 flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors"
            :class="{ 'bg-amber-50 border-amber-300 text-amber-700': showOverrides }"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
              <path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            Overrides
            <span
              v-if="overrideCount > 0"
              class="inline-flex items-center justify-center min-w-[18px] h-[18px] text-[10px] font-bold rounded-full bg-amber-500 text-white px-1"
            >{{ overrideCount }}</span>
          </button>
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

      <!-- Overrides Panel (absolute overlay, doesn't affect layout height) -->
      <transition
        enter-active-class="transition-all duration-200 ease-out"
        leave-active-class="transition-all duration-200 ease-in"
        enter-from-class="opacity-0 -translate-y-2"
        enter-to-class="opacity-100 translate-y-0"
        leave-from-class="opacity-100 translate-y-0"
        leave-to-class="opacity-0 -translate-y-2"
      >
        <div v-if="showOverrides" class="absolute top-[60px] left-1 right-1 z-30">
          <!-- Backdrop to close on click outside -->
          <div class="fixed inset-0 z-[-1]" @click="showOverrides = false"></div>
          <div class="shadow-lg rounded-xl">
            <TestOverridesPanel
              :db-bot-settings="dbBotSettings"
              :setting-overrides="settingOverrides"
              :triggers="triggers"
              :trigger-overrides="triggerOverrides"
              @update:setting-override="setSettingOverride"
              @update:trigger-override="setTriggerOverride"
              @reset-overrides="resetOverrides"
            />
          </div>
        </div>
      </transition>

      <!-- Main content: Chat + Debug -->
      <div class="flex-1 flex flex-col lg:flex-row gap-4 min-h-0 px-1">
        <!-- Chat Panel -->
        <div class="flex-1 min-w-0 transition-all duration-200">
          <TestChatPanel
            :org-name="orgName"
            :automation-enabled="effectiveBotSettings.automation_enabled ?? automationEnabled"
            :within-working-hours="withinWorkingHours"
            :during-working-hours="duringWorkingHours"
            :ai-enabled="effectiveBotSettings.ai_enabled ?? aiEnabled"
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
              :bot-settings="effectiveBotSettings"
              :session-info="sessionInfo"
              :working-hours="workingHours"
              :unmatched-message-count="unmatchedMessageCount"
              :setting-overrides="settingOverrides"
              :trigger-overrides="triggerOverrides"
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
import TestOverridesPanel from './TestOverridesPanel.vue'
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

const showOverrides = ref(false)

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
  effectiveBotSettings,
  sessionInfo,
  unmatchedMessageCount,
  activeInputHint,
  workingHours,
  // Override state
  triggers,
  settingOverrides,
  triggerOverrides,
  dbBotSettings,
  overrideCount,
  // Methods
  sendMessage,
  selectOption,
  testAiDirectly,
  resetChat,
  setSettingOverride,
  setTriggerOverride,
  resetOverrides,
} = useAutomationTest(props)
</script>