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
      <!-- Bot Settings -->
      <div class="space-y-1">
        <div class="text-[10px] font-medium text-gray-400 uppercase tracking-wider">Bot Settings</div>
        <div class="space-y-0.5">
          <div v-for="setting in botSettingsList" :key="setting.label" class="flex items-center justify-between text-xs">
            <span class="text-gray-600">{{ setting.label }}</span>
            <span :class="setting.value ? 'text-green-600' : 'text-gray-400'" class="font-medium">
              {{ setting.display || (setting.value ? 'On' : 'Off') }}
            </span>
          </div>
        </div>
      </div>

      <!-- Fallback Threshold Indicator -->
      <div v-if="fallbackThreshold > 0 && unmatchedMessageCount > 0" class="space-y-1 pt-2 border-t border-gray-100">
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
      <div v-else-if="botSettings.automation_enabled !== undefined" class="space-y-1 pt-2 border-t border-gray-100">
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
})

const expanded = ref(true)

const botSettingsList = computed(() => {
  const settings = props.botSettings
  if (!settings || Object.keys(settings).length === 0) return []

  return [
    { label: 'Automation', value: settings.automation_enabled, display: settings.automation_enabled ? 'Enabled' : 'Disabled' },
    { label: 'AI', value: settings.ai_enabled, display: settings.ai_enabled ? 'Enabled' : 'Disabled' },
    { label: 'AI Fallback', value: settings.ai_fallback_enabled, display: settings.ai_fallback_enabled ? 'Enabled' : 'Disabled' },
    { label: 'AI Only Mode', value: settings.ai_only_mode, display: settings.ai_only_mode ? 'Enabled' : 'Disabled' },
    { label: 'Fallback Threshold', value: true, display: settings.fallback_threshold ? `${settings.fallback_threshold} messages` : 'Not set' },
    { label: 'Flow Timeout', value: true, display: settings.flow_timeout_minutes ? `${settings.flow_timeout_minutes} min` : 'Not set' },
    { label: 'Escalation Transfer', value: settings.escalation_transfer_enabled, display: settings.escalation_transfer_enabled ? 'Enabled' : 'Disabled' },
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
  return props.botSettings?.fallback_threshold || 3
})
</script>