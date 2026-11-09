<template>
  <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
    <!-- Section: Master Controls -->
    <div class="px-4 py-3 border-b border-gray-100">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-1 h-4 rounded-full bg-slate-400"></div>
        <div class="text-xs font-bold text-slate-500 uppercase tracking-wide">{{ $t("Master Controls") }}</div>
      </div>

      <!-- Automation Master Switch -->
      <div class="rounded-lg border-2 p-3 mb-3" :class="effectiveAutomation ? 'border-primary/30 bg-primary/[0.02]' : 'border-red-200 bg-red-50/30'">
        <div class="flex items-center gap-2.5">
          <div class="shrink-0 w-7 h-7 rounded-lg flex items-center justify-center" :class="effectiveAutomation ? 'bg-primary/10' : 'bg-red-100'">
            <svg class="w-4 h-4" :class="effectiveAutomation ? 'text-primary' : 'text-red-500'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-1.5">
              <span class="text-sm font-semibold text-slate-800">{{ $t("Automation") }}</span>
              <span v-if="isSettingOverridden('automation_enabled')" class="text-[9px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700">{{ $t("Override") }}</span>
            </div>
            <p class="text-[11px] text-slate-500 mt-0.5">
              <span v-if="effectiveAutomation">{{ $t("Your bot responds automatically to messages.") }}</span>
              <span v-else class="text-red-600 font-medium">{{ $t("All automation is paused. Nothing will respond.") }}</span>
            </p>
          </div>
          <button type="button" @click="toggleSetting('automation_enabled')"
            class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors duration-200 focus:outline-none shrink-0"
            :class="effectiveAutomation ? 'bg-primary' : 'bg-red-400'"
          >
            <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow-sm transition-transform duration-200" :class="effectiveAutomation ? 'translate-x-[22px]' : 'translate-x-[3px]'" />
          </button>
        </div>
      </div>

      <!-- Everything below depends on Automation being ON -->
      <div :class="{ 'opacity-50 pointer-events-none': !effectiveAutomation }">
        <!-- AI Assistant -->
        <div class="rounded-lg border border-slate-200 p-3 mb-2">
          <div class="flex items-center gap-2.5">
            <div class="shrink-0 w-7 h-7 rounded-lg flex items-center justify-center" :class="effectiveAiEnabled ? 'bg-violet-100' : 'bg-slate-100'">
              <svg class="w-4 h-4" :class="effectiveAiEnabled ? 'text-violet-600' : 'text-slate-400'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446A9 9 0 1 1 12 3z"/><path d="M17 4a2 2 0 0 0 2 2a2 2 0 0 0-2 2a2 2 0 0 0-2-2a2 2 0 0 0 2-2"/></svg>
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-1.5">
                <span class="text-sm font-semibold text-slate-800">{{ $t("AI Assistant") }}</span>
                <span v-if="isSettingOverridden('ai_enabled')" class="text-[9px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700">{{ $t("Override") }}</span>
              </div>
              <p class="text-[11px] text-slate-500 mt-0.5">
                <span v-if="effectiveAiEnabled">{{ $t("AI can understand messages and answer from KB.") }}</span>
                <span v-else>{{ $t("AI is disabled. Only exact keyword matching works.") }}</span>
              </p>
            </div>
            <button type="button" :disabled="$page.props.automationAiSuspended" @click="toggleSetting('ai_enabled')"
              class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none shrink-0"
              :class="effectiveAiEnabled ? 'bg-primary' : 'bg-slate-300'"
            >
              <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition-transform duration-200" :class="effectiveAiEnabled ? 'translate-x-[18px]' : 'translate-x-[3px]'" />
            </button>
          </div>

          <!-- AI-Only Mode (requires AI ON) -->
          <div class="mt-2 rounded-lg border border-slate-200 p-2.5" :class="{ 'opacity-50 pointer-events-none': !effectiveAiEnabled }">
            <div class="flex items-center gap-2.5">
              <div class="shrink-0 w-6 h-6 rounded-lg flex items-center justify-center" :class="effectiveAiOnlyMode ? 'bg-indigo-100' : 'bg-slate-100'">
                <svg class="w-3.5 h-3.5" :class="effectiveAiOnlyMode ? 'text-indigo-600' : 'text-slate-400'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 12l2 2l4-4"/></svg>
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-1.5">
                  <span class="text-xs font-semibold text-slate-800">{{ $t("AI-Only Mode") }}</span>
                  <span v-if="isSettingOverridden('ai_only_mode')" class="text-[9px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700">{{ $t("Override") }}</span>
                </div>
              </div>
              <button type="button" :disabled="$page.props.automationAiSuspended" @click="toggleSetting('ai_only_mode')"
                class="relative inline-flex h-4 w-7 items-center rounded-full transition-colors duration-200 focus:outline-none shrink-0"
                :class="effectiveAiOnlyMode ? 'bg-indigo-500' : 'bg-slate-300'"
              >
                <span class="inline-block h-2.5 w-2.5 transform rounded-full bg-white shadow-sm transition-transform duration-200" :class="effectiveAiOnlyMode ? 'translate-x-[14px]' : 'translate-x-[2px]'" />
              </button>
            </div>
            <div v-if="effectiveAiOnlyMode" class="mt-2 flex items-start gap-1.5 rounded bg-indigo-50 border border-indigo-100 px-2.5 py-1.5 text-[10px] text-indigo-700">
              <svg class="w-3 h-3 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
              <span>{{ $t("AI-Only Mode is active. Triggers and flows are bypassed.") }}</span>
            </div>
          </div>
        </div>

        <!-- Section: When No Trigger Matches -->
        <div class="flex items-center gap-2 mt-3 mb-2">
          <div class="w-1 h-4 rounded-full bg-violet-500"></div>
          <div class="text-xs font-bold text-slate-500 uppercase tracking-wide">{{ $t("When No Trigger Matches") }}</div>
        </div>

        <!-- AI Fallback (requires AI ON) -->
        <div class="rounded-lg border border-slate-200 p-3 mb-2" :class="{ 'opacity-50 pointer-events-none': !effectiveAiEnabled }">
          <div class="flex items-center gap-2.5">
            <div class="shrink-0 w-7 h-7 rounded-lg flex items-center justify-center" :class="effectiveAiFallback ? 'bg-violet-100' : 'bg-slate-100'">
              <svg class="w-4 h-4" :class="effectiveAiFallback ? 'text-violet-600' : 'text-slate-400'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446A9 9 0 1 1 12 3z"/><path d="M17 4a2 2 0 0 0 2 2a2 2 0 0 0-2 2a2 2 0 0 0-2-2a2 2 0 0 0 2-2"/></svg>
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-1.5">
                <span class="text-sm font-semibold text-slate-800">{{ $t("AI Fallback") }}</span>
                <span v-if="isSettingOverridden('ai_fallback_enabled')" class="text-[9px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700">{{ $t("Override") }}</span>
              </div>
              <p class="text-[11px] text-slate-500 mt-0.5">
                <span v-if="effectiveAiFallback">{{ $t("AI searches Knowledge Base when no trigger matches.") }}</span>
                <span v-else>{{ $t("AI won't answer unmatched messages.") }}</span>
              </p>
            </div>
            <button type="button" :disabled="$page.props.automationAiSuspended" @click="toggleSetting('ai_fallback_enabled')"
              class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none shrink-0"
              :class="effectiveAiFallback ? 'bg-primary' : 'bg-slate-300'"
            >
              <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition-transform duration-200" :class="effectiveAiFallback ? 'translate-x-[18px]' : 'translate-x-[3px]'" />
            </button>
          </div>

          <!-- AI sub-settings (require AI Fallback ON) -->
          <div v-if="effectiveAiFallback" class="mt-2 space-y-1.5 pl-2 border-s-2 border-violet-100">
            <!-- AI Escalation -->
            <div class="flex items-center justify-between py-1">
              <div class="flex items-center gap-1.5 min-w-0">
                <span class="text-xs text-slate-700">{{ $t("AI Escalation Rules") }}</span>
                <span v-if="isSettingOverridden('ai_escalation_enabled')" class="text-[9px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700">{{ $t("Override") }}</span>
              </div>
              <button type="button" :disabled="$page.props.automationAiSuspended" @click="toggleSetting('ai_escalation_enabled')"
                class="relative inline-flex h-4 w-7 items-center rounded-full transition-colors duration-200 focus:outline-none shrink-0"
                :class="getEffectiveSetting('ai_escalation_enabled') ? 'bg-amber-500' : 'bg-slate-300'"
              >
                <span class="inline-block h-2.5 w-2.5 transform rounded-full bg-white shadow-sm transition-transform duration-200" :class="getEffectiveSetting('ai_escalation_enabled') ? 'translate-x-[14px]' : 'translate-x-[2px]'" />
              </button>
            </div>
            <!-- AI Follow-up -->
            <div class="flex items-center justify-between py-1">
              <div class="flex items-center gap-1.5 min-w-0">
                <span class="text-xs text-slate-700">{{ $t("AI Follow-up") }}</span>
                <span v-if="isSettingOverridden('ai_followup_enabled')" class="text-[9px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700">{{ $t("Override") }}</span>
              </div>
              <button type="button" :disabled="$page.props.automationAiSuspended" @click="toggleSetting('ai_followup_enabled')"
                class="relative inline-flex h-4 w-7 items-center rounded-full transition-colors duration-200 focus:outline-none shrink-0"
                :class="getEffectiveSetting('ai_followup_enabled') ? 'bg-blue-500' : 'bg-slate-300'"
              >
                <span class="inline-block h-2.5 w-2.5 transform rounded-full bg-white shadow-sm transition-transform duration-200" :class="getEffectiveSetting('ai_followup_enabled') ? 'translate-x-[14px]' : 'translate-x-[2px]'" />
              </button>
            </div>
          </div>
        </div>

        <!-- Section: Escalation & Other -->
        <div class="flex items-center gap-2 mt-3 mb-2">
          <div class="w-1 h-4 rounded-full bg-amber-500"></div>
          <div class="text-xs font-bold text-slate-500 uppercase tracking-wide">{{ $t("Escalation & Other") }}</div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1.5">
          <div class="flex items-center justify-between gap-2 py-1 px-1.5 rounded -mx-1.5" :class="{ 'bg-amber-50 ring-1 ring-amber-200': isSettingOverridden('escalation_transfer_enabled') }">
            <div class="flex items-center gap-1.5 min-w-0">
              <span class="text-xs text-slate-700 truncate">{{ $t("Escalation Transfer") }}</span>
              <span v-if="isSettingOverridden('escalation_transfer_enabled')" class="shrink-0 text-[9px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700">{{ $t("Override") }}</span>
            </div>
            <button type="button" @click="toggleSetting('escalation_transfer_enabled')"
              class="relative inline-flex h-4 w-7 items-center rounded-full transition-colors duration-200 focus:outline-none shrink-0"
              :class="getEffectiveSetting('escalation_transfer_enabled') ? 'bg-primary' : 'bg-slate-300'"
            >
              <span class="inline-block h-2.5 w-2.5 transform rounded-full bg-white shadow-sm transition-transform duration-200" :class="getEffectiveSetting('escalation_transfer_enabled') ? 'translate-x-[14px]' : 'translate-x-[2px]'" />
            </button>
          </div>
          <div class="flex items-center justify-between gap-2 py-1 px-1.5 rounded -mx-1.5" :class="{ 'bg-amber-50 ring-1 ring-amber-200': isSettingOverridden('auto_escalation_enabled') }">
            <div class="flex items-center gap-1.5 min-w-0">
              <span class="text-xs text-slate-700 truncate">{{ $t("Auto-Escalation") }}</span>
              <span v-if="isSettingOverridden('auto_escalation_enabled')" class="shrink-0 text-[9px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700">{{ $t("Override") }}</span>
            </div>
            <button type="button" @click="toggleSetting('auto_escalation_enabled')"
              class="relative inline-flex h-4 w-7 items-center rounded-full transition-colors duration-200 focus:outline-none shrink-0"
              :class="getEffectiveSetting('auto_escalation_enabled') ? 'bg-primary' : 'bg-slate-300'"
            >
              <span class="inline-block h-2.5 w-2.5 transform rounded-full bg-white shadow-sm transition-transform duration-200" :class="getEffectiveSetting('auto_escalation_enabled') ? 'translate-x-[14px]' : 'translate-x-[2px]'" />
            </button>
          </div>
          <div class="flex items-center justify-between gap-2 py-1 px-1.5 rounded -mx-1.5" :class="{ 'bg-amber-50 ring-1 ring-amber-200': isSettingOverridden('flow_rejoin_enabled') }">
            <div class="flex items-center gap-1.5 min-w-0">
              <span class="text-xs text-slate-700 truncate">{{ $t("Flow Rejoin") }}</span>
              <span v-if="isSettingOverridden('flow_rejoin_enabled')" class="shrink-0 text-[9px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700">{{ $t("Override") }}</span>
            </div>
            <button type="button" @click="toggleSetting('flow_rejoin_enabled')"
              class="relative inline-flex h-4 w-7 items-center rounded-full transition-colors duration-200 focus:outline-none shrink-0"
              :class="getEffectiveSetting('flow_rejoin_enabled') ? 'bg-primary' : 'bg-slate-300'"
            >
              <span class="inline-block h-2.5 w-2.5 transform rounded-full bg-white shadow-sm transition-transform duration-200" :class="getEffectiveSetting('flow_rejoin_enabled') ? 'translate-x-[14px]' : 'translate-x-[2px]'" />
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Triggers -->
    <div v-if="triggers.length > 0" class="px-4 py-3 border-b border-gray-100" :class="{ 'opacity-50 pointer-events-none': !effectiveAutomation }">
      <div class="flex items-center gap-2 mb-2">
        <div class="w-1 h-4 rounded-full bg-emerald-500"></div>
        <div class="text-xs font-bold text-slate-500 uppercase tracking-wide">{{ $t("Triggers") }}</div>
      </div>
      <div class="space-y-0.5 max-h-40 overflow-y-auto">
        <div
          v-for="trigger in triggers"
          :key="trigger.id"
          class="flex items-center justify-between gap-2 py-1 px-1.5 rounded -mx-1.5"
          :class="{ 'bg-amber-50 ring-1 ring-amber-200': isTriggerOverridden(trigger.id) }"
        >
          <div class="flex items-center gap-2 min-w-0">
            <button type="button" @click="toggleTrigger(trigger.id)"
              class="relative inline-flex h-4 w-7 items-center rounded-full transition-colors duration-200 focus:outline-none shrink-0"
              :class="getEffectiveTrigger(trigger.id) ? 'bg-primary' : 'bg-slate-300'"
            >
              <span class="inline-block h-2.5 w-2.5 transform rounded-full bg-white shadow-sm transition-transform duration-200" :class="getEffectiveTrigger(trigger.id) ? 'translate-x-[14px]' : 'translate-x-[2px]'" />
            </button>
            <span class="text-xs text-slate-700 truncate">{{ trigger.name }}</span>
            <span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 capitalize shrink-0">{{ trigger.type.replace('_', ' ') }}</span>
            <span v-if="isTriggerOverridden(trigger.id)" class="shrink-0 text-[9px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700">{{ $t("Override") }}</span>
          </div>
          <span v-if="trigger.flow_name" class="text-[10px] text-slate-400 truncate shrink-0">{{ trigger.flow_name }}</span>
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
      > {{ $t("Reset All Overrides") }} </button>
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

const getEffectiveSetting = (key) => {
  if (key in props.settingOverrides) return props.settingOverrides[key]
  return props.dbBotSettings[key] ?? false
}

const getEffectiveTrigger = (id) => {
  if (id in props.triggerOverrides) return props.triggerOverrides[id]
  const trigger = props.triggers.find(t => t.id === id)
  return trigger?.is_enabled ?? false
}

const effectiveAutomation = computed(() => getEffectiveSetting('automation_enabled'))
const effectiveAiEnabled = computed(() => getEffectiveSetting('ai_enabled'))
const effectiveAiOnlyMode = computed(() => getEffectiveSetting('ai_only_mode'))
const effectiveAiFallback = computed(() => getEffectiveSetting('ai_fallback_enabled'))

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