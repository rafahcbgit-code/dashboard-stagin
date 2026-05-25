<template>
  <div>
    <button
      @click="expanded = !expanded"
      class="w-full flex items-center justify-between px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
    >
      <div class="flex items-center gap-2">
        <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
          <path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
        <span>Session & Settings</span>
      </div>
      <svg
        class="w-4 h-4 text-gray-400 transition-transform"
        :class="{ 'rotate-90': expanded }"
        fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
      >
        <path d="M9 5l7 7-7 7"/>
      </svg>
    </button>

    <div v-if="expanded" class="px-3 pb-2 space-y-2">
      <!-- Master Controls -->
      <div class="space-y-1">
        <div class="text-[10px] font-medium text-gray-400 uppercase tracking-wider">Master Controls</div>
        <!-- Automation -->
        <div class="flex items-center justify-between text-xs rounded px-1.5 py-1" :class="automationEnabled ? 'bg-green-50' : 'bg-red-50'">
          <span class="flex items-center gap-1.5">
            <span class="w-1.5 h-1.5 rounded-full" :class="automationEnabled ? 'bg-green-500' : 'bg-red-500'"></span>
            <span :class="automationEnabled ? 'text-green-700' : 'text-red-700'" class="font-semibold">Automation</span>
            <span v-if="isOverridden('automation_enabled')" class="text-[9px] font-semibold px-1 py-0.5 rounded bg-amber-100 text-amber-700">Override</span>
          </span>
          <span :class="automationEnabled ? 'text-green-600' : 'text-red-600'" class="font-medium">
            {{ automationEnabled ? 'Enabled' : 'Disabled' }}
          </span>
        </div>
        <!-- Automation OFF warning -->
        <div v-if="!automationEnabled" class="flex items-start gap-1.5 rounded bg-red-50 border border-red-100 px-2 py-1.5 text-[10px] text-red-600">
          <svg class="w-3 h-3 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
          <span>All automation is paused. Nothing will respond.</span>
        </div>
      </div>

      <!-- AI Settings (dimmed if automation off) -->
      <div class="space-y-1 pt-2 border-t border-gray-100" :class="{ 'opacity-40': !automationEnabled }">
        <div class="text-[10px] font-medium text-gray-400 uppercase tracking-wider">AI</div>
        <div class="flex items-center justify-between text-xs px-1.5 py-1 rounded" :class="aiEnabled ? 'bg-violet-50' : 'bg-gray-50'">
          <span class="flex items-center gap-1.5">
            <span class="w-1.5 h-1.5 rounded-full" :class="aiEnabled ? 'bg-violet-500' : 'bg-gray-400'"></span>
            <span :class="aiEnabled ? 'text-violet-700' : 'text-gray-500'" class="font-semibold">AI Assistant</span>
            <span v-if="isOverridden('ai_enabled')" class="text-[9px] font-semibold px-1 py-0.5 rounded bg-amber-100 text-amber-700">Override</span>
          </span>
          <span :class="aiEnabled ? 'text-green-600' : 'text-gray-400'" class="font-medium">
            {{ aiEnabled ? 'Enabled' : 'Disabled' }}
          </span>
        </div>

        <!-- AI-Only Mode -->
        <div class="flex items-center justify-between text-xs px-1.5 py-1" :class="{ 'opacity-40': !aiEnabled }">
          <span class="flex items-center gap-1.5">
            <span class="w-1.5 h-1.5 rounded-full" :class="aiOnlyMode ? 'bg-indigo-500' : 'bg-gray-400'"></span>
            <span :class="aiOnlyMode ? 'text-indigo-700' : 'text-gray-500'">AI-Only Mode</span>
            <span v-if="isOverridden('ai_only_mode')" class="text-[9px] font-semibold px-1 py-0.5 rounded bg-amber-100 text-amber-700">Override</span>
          </span>
          <span :class="aiOnlyMode ? 'text-indigo-600' : 'text-gray-400'" class="font-medium">
            {{ aiOnlyMode ? 'Active' : 'Off' }}
          </span>
        </div>
        <div v-if="aiOnlyMode" class="flex items-start gap-1.5 rounded bg-indigo-50 border border-indigo-100 px-2 py-1 text-[10px] text-indigo-700">
          <svg class="w-3 h-3 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
          <span>Triggers and flows bypassed. Only AI + Fallback trigger.</span>
        </div>

        <!-- AI Fallback -->
        <div class="flex items-center justify-between text-xs px-1.5 py-1 rounded" :class="{ 'opacity-40': !aiEnabled }">
          <span class="flex items-center gap-1.5">
            <span class="w-1.5 h-1.5 rounded-full" :class="aiFallback ? 'bg-violet-500' : 'bg-gray-400'"></span>
            <span :class="aiFallback ? 'text-violet-700' : 'text-gray-500'">AI Fallback</span>
            <span v-if="isOverridden('ai_fallback_enabled')" class="text-[9px] font-semibold px-1 py-0.5 rounded bg-amber-100 text-amber-700">Override</span>
          </span>
          <span :class="aiFallback ? 'text-green-600' : 'text-gray-400'" class="font-medium">
            {{ aiFallback ? 'Enabled' : 'Disabled' }}
          </span>
        </div>

        <!-- AI sub-settings (indented, only if AI Fallback ON) -->
        <div v-if="aiFallback && aiEnabled" class="ml-3 pl-2 border-l-2 border-violet-100 space-y-0.5">
          <div class="flex items-center justify-between text-[11px] py-0.5">
            <span class="flex items-center gap-1">
              <span>AI Escalation</span>
              <span v-if="isOverridden('ai_escalation_enabled')" class="text-[9px] font-semibold px-1 py-0.5 rounded bg-amber-100 text-amber-700">Override</span>
            </span>
            <span :class="settings.ai_escalation_enabled ? 'text-amber-600' : 'text-gray-400'" class="font-medium">
              {{ settings.ai_escalation_enabled ? 'On' : 'Off' }}
            </span>
          </div>
          <div class="flex items-center justify-between text-[11px] py-0.5">
            <span class="flex items-center gap-1">
              <span>AI Follow-up</span>
              <span v-if="isOverridden('ai_followup_enabled')" class="text-[9px] font-semibold px-1 py-0.5 rounded bg-amber-100 text-amber-700">Override</span>
            </span>
            <span :class="settings.ai_followup_enabled ? 'text-blue-600' : 'text-gray-400'" class="font-medium">
              {{ settings.ai_followup_enabled ? 'On' : 'Off' }}
            </span>
          </div>
        </div>
      </div>

      <!-- Escalation & Other -->
      <div class="space-y-1 pt-2 border-t border-gray-100" :class="{ 'opacity-40': !automationEnabled }">
        <div class="text-[10px] font-medium text-gray-400 uppercase tracking-wider">Escalation & Other</div>
        <div v-for="item in otherSettings" :key="item.key" class="flex items-center justify-between text-xs px-1.5 py-0.5" :class="{ 'bg-amber-50 border-l-2 border-amber-400 pl-2 -ml-0.5 rounded-r': item.overridden }">
          <span class="flex items-center gap-1.5">
            <span>{{ item.label }}</span>
            <span v-if="item.overridden" class="text-[9px] font-semibold px-1 py-0.5 rounded bg-amber-100 text-amber-700">Override</span>
          </span>
          <span :class="item.value ? 'text-green-600' : 'text-gray-400'" class="font-medium">
            {{ item.display || (item.value ? 'On' : 'Off') }}
          </span>
        </div>
      </div>

      <!-- Fallback Threshold Indicator -->
      <div v-if="automationEnabled && fallbackThreshold > 0 && unmatchedMessageCount > 0" class="space-y-1 pt-2 border-t border-gray-100">
        <div class="text-[10px] font-medium text-gray-400 uppercase tracking-wider">Fallback Threshold</div>
        <div class="flex items-center gap-2">
          <div class="flex-1 h-2 bg-gray-200 rounded-full overflow-hidden">
            <div
              class="h-full rounded-full transition-all duration-300"
              :class="unmatchedMessageCount >= fallbackThreshold ? 'bg-red-500' : 'bg-amber-400'"
              :style="{ width: Math.min((unmatchedMessageCount / fallbackThreshold) * 100, 100) + '%' }"
            ></div>
          </div>
          <span class="text-xs font-medium" :class="unmatchedMessageCount >= fallbackThreshold ? 'text-red-600' : 'text-amber-600'">
            {{ unmatchedMessageCount }}/{{ fallbackThreshold }}
          </span>
        </div>
        <div v-if="unmatchedMessageCount >= fallbackThreshold" class="text-[11px] text-red-500">
          Would auto-escalate to human agent
        </div>
      </div>

      <!-- Session State -->
      <div v-if="sessionInfo" class="space-y-1 pt-2 border-t border-gray-100">
        <div class="text-[10px] font-medium text-gray-400 uppercase tracking-wider">Session</div>
        <div v-for="item in sessionList" :key="item.label" class="flex items-center justify-between text-xs">
          <span class="text-gray-600">{{ item.label }}</span>
          <span class="text-gray-800 font-medium">{{ item.value }}</span>
        </div>
      </div>

      <!-- Working Hours -->
      <div v-if="workingHours && workingHours.days" class="space-y-1 pt-2 border-t border-gray-100">
        <div class="text-[10px] font-medium text-gray-400 uppercase tracking-wider">Working Hours</div>
        <div v-if="workingHours.timezone" class="text-[10px] text-gray-400 mb-1">{{ workingHours.timezone }}</div>
        <div v-for="day in workingHours.days" :key="day.day" class="flex items-center justify-between text-xs">
          <span class="text-gray-600">{{ day.day }}</span>
          <span :class="day.active ? 'text-gray-800' : 'text-gray-400'" class="font-medium">
            {{ day.active ? `${day.from} - ${day.to}` : 'Off' }}
          </span>
        </div>
      </div>
      <div v-else-if="settings.automation_enabled !== undefined" class="space-y-1 pt-2 border-t border-gray-100">
        <div class="text-[10px] font-medium text-gray-400 uppercase tracking-wider">Working Hours</div>
        <div class="text-xs text-gray-400">No schedule configured</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  botSettings: { type: Object, default: () => ({}) },
  sessionInfo: { type: Object, default: null },
  workingHours: { type: Object, default: null },
  unmatchedMessageCount: { type: Number, default: 0 },
  settingOverrides: { type: Object, default: () => ({}) },
  triggerOverrides: { type: Object, default: () => ({}) },
})

const expanded = ref(true)

const settings = computed(() => props.botSettings || {})
const overrides = computed(() => props.settingOverrides || {})

const isOverridden = (key) => key in overrides.value

const automationEnabled = computed(() => settings.value.automation_enabled ?? false)
const aiEnabled = computed(() => settings.value.ai_enabled ?? false)
const aiOnlyMode = computed(() => settings.value.ai_only_mode ?? false)
const aiFallback = computed(() => settings.value.ai_fallback_enabled ?? false)

const otherSettings = computed(() => {
  const s = settings.value
  const o = overrides.value
  return [
    { key: 'escalation_transfer_enabled', label: 'Escalation Transfer', value: s.escalation_transfer_enabled, display: s.escalation_transfer_enabled ? 'Enabled' : 'Disabled', overridden: 'escalation_transfer_enabled' in o },
    { key: 'auto_escalation_enabled', label: 'Auto-Escalation', value: s.auto_escalation_enabled, display: s.auto_escalation_enabled ? 'Enabled' : 'Disabled', overridden: 'auto_escalation_enabled' in o },
    { key: 'fallback_threshold', label: 'Fallback Threshold', value: true, display: s.fallback_threshold ? `${s.fallback_threshold} msgs` : 'Not set', overridden: false },
    { key: 'flow_timeout_minutes', label: 'Flow Timeout', value: true, display: s.flow_timeout_minutes ? `${s.flow_timeout_minutes} min` : 'Not set', overridden: false },
    { key: 'flow_rejoin_enabled', label: 'Flow Rejoin', value: s.flow_rejoin_enabled, display: s.flow_rejoin_enabled ? 'Enabled' : 'Disabled', overridden: 'flow_rejoin_enabled' in o },
  ]
})

const sessionList = computed(() => {
  if (!props.sessionInfo) return []
  const items = []
  if (props.sessionInfo.flowSessionId) {
    items.push({ label: 'Flow Session', value: props.sessionInfo.flowSessionId.substring(0, 12) + '...' })
  }
  if (props.sessionInfo.currentFlow) {
    items.push({ label: 'Current Flow', value: props.sessionInfo.currentFlow })
  }
  if (props.sessionInfo.messagesCount !== undefined) {
    items.push({ label: 'Messages', value: String(props.sessionInfo.messagesCount) })
  }
  return items
})

const fallbackThreshold = computed(() => {
  return settings.value?.fallback_threshold || 3
})
</script>