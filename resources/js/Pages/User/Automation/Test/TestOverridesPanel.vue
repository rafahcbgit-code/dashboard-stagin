<template>
  <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
    <!-- Bot & AI Settings -->
    <div class="px-4 py-3 border-b border-gray-100">
      <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Bot & AI Settings</div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5">
        <div
          v-for="setting in botSettingToggles"
          :key="setting.key"
          class="flex items-center justify-between gap-2 py-1 px-1.5 rounded -mx-1.5"
          :class="{ 'bg-amber-50 ring-1 ring-amber-200': isSettingOverridden(setting.key) }"
        >
          <div class="flex items-center gap-1.5 min-w-0">
            <span class="text-sm text-gray-700 truncate">{{ setting.label }}</span>
            <span
              v-if="isSettingOverridden(setting.key)"
              class="shrink-0 text-[9px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700"
            >Override</span>
          </div>
          <button
            type="button"
            @click="toggleSetting(setting.key)"
            class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-1 shrink-0"
            :class="getEffectiveSetting(setting.key) ? 'bg-primary' : 'bg-slate-300'"
          >
            <span
              class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition-transform duration-200"
              :class="getEffectiveSetting(setting.key) ? 'translate-x-[18px]' : 'translate-x-[3px]'"
            />
          </button>
        </div>
      </div>
    </div>

    <!-- Triggers -->
    <div v-if="triggers.length > 0" class="px-4 py-3 border-b border-gray-100">
      <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Triggers</div>
      <div class="space-y-0.5 max-h-48 overflow-y-auto">
        <div
          v-for="trigger in triggers"
          :key="trigger.id"
          class="flex items-center justify-between gap-2 py-1 px-1.5 rounded -mx-1.5"
          :class="{ 'bg-amber-50 ring-1 ring-amber-200': isTriggerOverridden(trigger.id) }"
        >
          <div class="flex items-center gap-2 min-w-0">
            <button
              type="button"
              @click="toggleTrigger(trigger.id)"
              class="relative inline-flex h-4 w-7 items-center rounded-full transition-colors duration-200 focus:outline-none shrink-0"
              :class="getEffectiveTrigger(trigger.id) ? 'bg-primary' : 'bg-slate-300'"
            >
              <span
                class="inline-block h-2.5 w-2.5 transform rounded-full bg-white shadow-sm transition-transform duration-200"
                :class="getEffectiveTrigger(trigger.id) ? 'translate-x-[14px]' : 'translate-x-[2px]'"
              />
            </button>
            <span class="text-sm text-gray-700 truncate">{{ trigger.name }}</span>
            <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 capitalize shrink-0">{{ trigger.type.replace('_', ' ') }}</span>
            <span
              v-if="isTriggerOverridden(trigger.id)"
              class="shrink-0 text-[9px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700"
            >Override</span>
          </div>
          <span v-if="trigger.flow_name" class="text-xs text-gray-400 truncate shrink-0">{{ trigger.flow_name }}</span>
        </div>
      </div>
    </div>

    <!-- Reset -->
    <div class="px-4 py-2 flex items-center justify-end">
      <button
        @click="$emit('reset-overrides')"
        :disabled="!hasAnyOverride"
        class="text-xs font-medium px-3 py-1.5 rounded-lg border transition-colors"
        :class="hasAnyOverride
          ? 'text-amber-700 border-amber-300 bg-amber-50 hover:bg-amber-100'
          : 'text-gray-400 border-gray-200 bg-gray-50 cursor-not-allowed'"
      >
        Reset All Overrides
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  dbBotSettings: { type: Object, default: () => ({}) },
  settingOverrides: { type: Object, default: () => ({}) },
  triggers: { type: Array, default: () => [] },
  triggerOverrides: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:setting-override', 'update:trigger-override', 'reset-overrides'])

const botSettingToggles = [
  { key: 'automation_enabled', label: 'Automation' },
  { key: 'ai_enabled', label: 'AI Assistant' },
  { key: 'ai_fallback_enabled', label: 'AI Fallback' },
  { key: 'ai_only_mode', label: 'AI Only Mode' },
  { key: 'escalation_transfer_enabled', label: 'Escalation Transfer' },
  { key: 'auto_escalation_enabled', label: 'Auto-Escalation' },
  { key: 'ai_escalation_enabled', label: 'AI Escalation' },
  { key: 'ai_followup_enabled', label: 'AI Follow-up' },
  { key: 'flow_rejoin_enabled', label: 'Flow Rejoin' },
]

const getEffectiveSetting = (key) => {
  if (key in props.settingOverrides) return props.settingOverrides[key]
  return props.dbBotSettings[key] ?? false
}

const getEffectiveTrigger = (id) => {
  if (id in props.triggerOverrides) return props.triggerOverrides[id]
  const trigger = props.triggers.find(t => t.id === id)
  return trigger?.is_enabled ?? false
}

const isSettingOverridden = (key) => key in props.settingOverrides
const isTriggerOverridden = (id) => id in props.triggerOverrides

const toggleSetting = (key) => {
  const current = getEffectiveSetting(key)
  emit('update:setting-override', key, !current)
}

const toggleTrigger = (id) => {
  const current = getEffectiveTrigger(id)
  emit('update:trigger-override', id, !current)
}

const hasAnyOverride = computed(() => {
  return Object.keys(props.settingOverrides).length > 0
    || Object.keys(props.triggerOverrides).length > 0
})
</script>