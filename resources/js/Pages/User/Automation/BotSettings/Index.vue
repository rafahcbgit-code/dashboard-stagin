<template>
    <SettingLayout :aimodule="aimodule" :fbmodule="fbmodule">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center">
                <div class="md:w-[68em] w-full">
                    <div class="bg-white border border-slate-200 rounded-xl text-sm pb-6 px-6 mb-20">
                        <!-- Header -->
                        <div class="flex items-center justify-between pt-5 pb-4 border-b border-slate-100">
                            <div>
                                <h4 class="text-lg font-semibold text-slate-800">{{ $t('Bot Settings') }}</h4>
                                <p class="text-sm text-slate-500 mt-0.5">{{ $t('Configure how your bot handles conversations.') }}</p>
                            </div>
                        </div>

                        <form @submit.prevent="submitForm" class="mt-6 space-y-6">
                            <!-- Automation Master Switch -->
                            <div class="rounded-lg border-2 p-5" :class="form.automation_enabled ? 'border-primary/30 bg-primary/[0.02]' : 'border-red-200 bg-red-50/30'">
                                <div class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-9 h-9 rounded-lg flex items-center justify-center" :class="form.automation_enabled ? 'bg-primary/10' : 'bg-red-100'">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :class="form.automation_enabled ? 'text-primary' : 'text-red-500'"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-sm font-semibold text-slate-800">{{ $t('Automation') }}</label>
                                        <p class="text-xs text-slate-500 mt-0.5">
                                            <span v-if="form.automation_enabled">{{ $t('Triggers, flows, and AI fallback are all active.') }}</span>
                                            <span v-else class="text-red-600">{{ $t('All automation is paused. Incoming messages will not trigger any flows or AI responses.') }}</span>
                                        </p>
                                    </div>
                                    <!-- Toggle -->
                                    <button
                                        type="button"
                                        @click="form.automation_enabled = !form.automation_enabled"
                                        class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-1 shrink-0"
                                        :class="form.automation_enabled ? 'bg-primary' : 'bg-red-400'"
                                    >
                                        <span
                                            class="inline-block h-4 w-4 transform rounded-full bg-white shadow-sm transition-transform duration-200"
                                            :class="form.automation_enabled ? 'translate-x-[22px]' : 'translate-x-[3px]'"
                                        />
                                    </button>
                                </div>
                            </div>

                            <!-- Settings dimmed when automation is off -->
                            <div :class="{ 'opacity-50 pointer-events-none': !form.automation_enabled }">
                            <!-- Fallback Threshold -->
                            <div class="rounded-lg border border-slate-200 p-5">
                                <div class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-amber-100 flex items-center justify-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-amber-600"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-sm font-semibold text-slate-800">{{ $t('Fallback Threshold') }}</label>
                                        <p class="text-xs text-slate-500 mt-0.5">{{ $t('After this many failed attempts, the conversation is automatically escalated to a human agent.') }}</p>
                                    </div>
                                </div>
                                <div class="mt-4 flex items-center gap-3">
                                    <input
                                        v-model.number="form.fallback_threshold"
                                        type="number"
                                        min="1"
                                        max="20"
                                        class="w-20 rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-center text-slate-800 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors"
                                        required
                                    />
                                    <span class="text-sm text-slate-500">{{ $t('attempts before escalation') }}</span>
                                </div>
                                <div class="mt-2 flex items-center gap-4 text-xs text-slate-400">
                                    <span>{{ $t('Example: After 3 unmatched messages, the conversation is automatically escalated to a human agent.') }}</span>
                                </div>
                            </div>

                            <!-- Flow Timeout -->
                            <div class="rounded-lg border border-slate-200 p-5">
                                <div class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-blue-100 flex items-center justify-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-blue-600"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-sm font-semibold text-slate-800">{{ $t('Flow Timeout') }}</label>
                                        <p class="text-xs text-slate-500 mt-0.5">{{ $t('How long a flow stays active before it expires. After timeout, the contact can start fresh with triggers.') }}</p>
                                    </div>
                                </div>
                                <div class="mt-4 flex items-center gap-3">
                                    <input
                                        v-model.number="form.flow_timeout_minutes"
                                        type="number"
                                        min="5"
                                        max="1440"
                                        class="w-20 rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-center text-slate-800 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors"
                                        required
                                    />
                                    <span class="text-sm text-slate-500">{{ $t('minutes') }}</span>
                                </div>
                                <div class="mt-2 flex items-center gap-4 text-xs text-slate-400">
                                    <span>{{ $t('Lower = faster re-evaluation. Higher = patient with slow responders.') }}</span>
                                </div>
                                <!-- Quick presets -->
                                <div class="mt-3 flex items-center gap-2">
                                    <span class="text-xs text-slate-500 mr-1">{{ $t('Quick set:') }}</span>
                                    <button type="button" @click="form.flow_timeout_minutes = 15" class="px-2.5 py-1 rounded-md border text-xs transition-colors" :class="form.flow_timeout_minutes === 15 ? 'border-primary bg-primary/5 text-primary font-medium' : 'border-slate-200 text-slate-500 hover:border-slate-300'">15 min</button>
                                    <button type="button" @click="form.flow_timeout_minutes = 30" class="px-2.5 py-1 rounded-md border text-xs transition-colors" :class="form.flow_timeout_minutes === 30 ? 'border-primary bg-primary/5 text-primary font-medium' : 'border-slate-200 text-slate-500 hover:border-slate-300'">30 min</button>
                                    <button type="button" @click="form.flow_timeout_minutes = 60" class="px-2.5 py-1 rounded-md border text-xs transition-colors" :class="form.flow_timeout_minutes === 60 ? 'border-primary bg-primary/5 text-primary font-medium' : 'border-slate-200 text-slate-500 hover:border-slate-300'">1 hr</button>
                                    <button type="button" @click="form.flow_timeout_minutes = 120" class="px-2.5 py-1 rounded-md border text-xs transition-colors" :class="form.flow_timeout_minutes === 120 ? 'border-primary bg-primary/5 text-primary font-medium' : 'border-slate-200 text-slate-500 hover:border-slate-300'">2 hr</button>
                                    <button type="button" @click="form.flow_timeout_minutes = 1440" class="px-2.5 py-1 rounded-md border text-xs transition-colors" :class="form.flow_timeout_minutes === 1440 ? 'border-primary bg-primary/5 text-primary font-medium' : 'border-slate-200 text-slate-500 hover:border-slate-300'">24 hr</button>
                                </div>
                            </div>

                            <!-- Working Hours (read-only summary) -->
                            <div class="rounded-lg border border-slate-200 p-5">
                                <div class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-emerald-100 flex items-center justify-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-emerald-600"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-sm font-semibold text-slate-800">{{ $t('Working Hours') }}</label>
                                        <p class="text-xs text-slate-500 mt-0.5">{{ $t('Controls when Greeting and Away triggers are active.') }}</p>
                                    </div>
                                    <!-- Status badge -->
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="workingHours.enabled ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-500 border border-slate-200'"
                                    >
                                        <span class="w-1.5 h-1.5 rounded-full" :class="workingHours.enabled ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                                        {{ workingHours.enabled ? $t('Enabled') : $t('Disabled') }}
                                    </span>
                                </div>

                                <!-- Schedule summary -->
                                <div v-if="workingHours.enabled && scheduleSummary.length > 0" class="mt-4 rounded-md border border-slate-100 bg-slate-50 p-3">
                                    <div class="space-y-1.5">
                                        <div v-for="day in scheduleSummary" :key="day.label" class="flex items-center justify-between text-xs">
                                            <span class="text-slate-600">{{ day.label }}</span>
                                            <span :class="day.active ? 'text-slate-800 font-medium' : 'text-slate-400'">
                                                {{ day.active ? day.time : $t('Off') }}
                                            </span>
                                        </div>
                                    </div>
                                    <div v-if="workingHours.timezone" class="mt-2 pt-2 border-t border-slate-200 text-xs text-slate-400">
                                        {{ $t('Timezone') }}: {{ workingHours.timezone }}
                                    </div>
                                </div>
                                <div v-else-if="!workingHours.enabled" class="mt-3 text-xs text-slate-400">
                                    {{ $t('Working hours are disabled. Greeting and Away triggers will not fire based on schedule.') }}
                                </div>

                                <div class="mt-3">
                                    <a href="/settings" class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:text-[#045a47] transition-colors">
                                        {{ $t('Configure in General Settings') }}
                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                                    </a>
                                </div>
                            </div>

                            <!-- AI Fallback -->
                            <div class="rounded-lg border border-slate-200 p-5">
                                <div class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-violet-100 flex items-center justify-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-violet-600"><path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446A9 9 0 1 1 12 3z"/><path d="M17 4a2 2 0 0 0 2 2a2 2 0 0 0-2 2a2 2 0 0 0-2-2a2 2 0 0 0 2-2"/></svg>
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-sm font-semibold text-slate-800">{{ $t('AI Fallback') }}</label>
                                        <p class="text-xs text-slate-500 mt-0.5">{{ $t('When no trigger matches, AI attempts to answer from your Knowledge Base.') }}</p>
                                    </div>
                                    <!-- Toggle -->
                                    <button
                                        type="button"
                                        @click="form.ai_fallback_enabled = !form.ai_fallback_enabled"
                                        class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-1 shrink-0"
                                        :class="form.ai_fallback_enabled ? 'bg-primary' : 'bg-slate-300'"
                                    >
                                        <span
                                            class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition-transform duration-200"
                                            :class="form.ai_fallback_enabled ? 'translate-x-[18px]' : 'translate-x-[3px]'"
                                        />
                                    </button>
                                </div>

                                <div v-if="form.ai_fallback_enabled" class="mt-4 space-y-4">
                                    <!-- No KB warning -->
                                    <div v-if="!hasKbEntries" class="flex items-start gap-2 rounded-md bg-amber-50 border border-amber-100 px-3 py-2 text-xs text-amber-700">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 mt-0.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                        <span>{{ $t('No Knowledge Base entries found. Add Q&A entries in Knowledge Base for AI to use.') }}</span>
                                    </div>

                                    <!-- Confidence slider -->
                                    <div class="rounded-lg border border-violet-100 bg-violet-50/50 p-4">
                                        <div class="flex items-center justify-between mb-2">
                                            <label class="text-sm font-semibold text-slate-700">{{ $t('AI Confidence Threshold') }}</label>
                                            <span class="text-sm font-bold text-primary tabular-nums">{{ form.ai_fallback_confidence }}%</span>
                                        </div>
                                        <input
                                            v-model.number="form.ai_fallback_confidence"
                                            type="range"
                                            min="0"
                                            max="100"
                                            step="5"
                                            class="w-full h-2 rounded-lg appearance-none cursor-pointer accent-primary"
                                        />
                                        <div class="flex justify-between text-[10px] text-slate-400 mt-1">
                                            <span>{{ $t('More answers') }}</span>
                                            <span>{{ $t('More accurate') }}</span>
                                        </div>
                                        <p class="text-xs text-slate-500 mt-2">{{ $t('Lower = AI answers more often but may be less accurate. Higher = stricter matching.') }}</p>
                                    </div>
                                </div>
                                <div v-else class="mt-2 text-xs text-slate-400">
                                    {{ $t('AI will not attempt to answer when no trigger matches. Messages will fall through to the fallback trigger.') }}
                                </div>
                            </div>

                            <!-- Transfer Message -->
                            <div class="rounded-lg border border-slate-200 p-5">
                                <div class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-indigo-100 flex items-center justify-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-indigo-600"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-sm font-semibold text-slate-800">{{ $t('Transfer Message') }}</label>
                                        <p class="text-xs text-slate-500 mt-0.5">{{ $t('Sent to the user when a conversation is escalated to a human agent.') }}</p>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <textarea
                                        v-model="form.escalation_transfer_message"
                                        rows="2"
                                        maxlength="500"
                                        class="w-full rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-800 placeholder:text-slate-400 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors resize-none"
                                        :placeholder="$t('e.g., You\'re being connected to a team member who can help. Please hold on a moment.')"
                                    />
                                </div>
                            </div>

                            </div><!-- end dimmed wrapper -->

                            <!-- Save -->
                            <div class="flex justify-end pt-2">
                                <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-5 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-[#045a47] transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                    {{ $t('Save Settings') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </SettingLayout>
</template>

<script setup>
import SettingLayout from './../Layout.vue';
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps(['settings', 'workingHours', 'kbEntryCount', 'aimodule', 'fbmodule']);

const form = ref({
    automation_enabled: props.settings?.automation_enabled ?? true,
    fallback_threshold: props.settings?.fallback_threshold || 3,
    flow_timeout_minutes: props.settings?.flow_timeout_minutes || 30,
    ai_fallback_enabled: props.settings?.ai_fallback_enabled || false,
    ai_fallback_confidence: props.settings?.ai_fallback_confidence || 75,
    escalation_transfer_message: props.settings?.escalation_transfer_message || "You're being connected to a team member who can help. Please hold on a moment.",
});

const hasKbEntries = computed(() => (props.kbEntryCount || 0) > 0);

const workingHours = computed(() => props.workingHours || { enabled: false, schedule: [], timezone: 'UTC' });

const scheduleSummary = computed(() => {
    const schedule = workingHours.value.schedule;
    if (!schedule || schedule.length === 0) return [];

    const dayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    return dayOrder.map(dayName => {
        const day = schedule.find(d => d.day === dayName);
        return {
            label: dayName.slice(0, 3),
            active: day ? day.active : false,
            time: day && day.from && day.to ? `${day.from} – ${day.to}` : '—',
        };
    });
});

function submitForm() {
    router.put('/automation/bot-settings', form.value, {
        preserveScroll: true,
    });
}
</script>