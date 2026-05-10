<template>
    <SettingLayout :aimodule="aimodule" :fbmodule="fbmodule">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center">
                <div class="md:w-[68em] w-full">
                    <div class="bg-white border border-slate-200 rounded-xl text-sm pb-6 px-6 mb-20">
                        <!-- Header -->
                        <div class="flex items-center justify-between pt-5 pb-4 border-b border-slate-100">
                            <div>
                                <h4 class="text-lg font-semibold text-slate-800">{{ $t('AI Settings') }}</h4>
                                <p class="text-sm text-slate-500 mt-0.5">{{ $t('Control how AI features work for your organization.') }}</p>
                            </div>
                        </div>

                        <form @submit.prevent="submitForm" class="mt-6 space-y-6">
                            <!-- AI Enabled -->
                            <div class="rounded-lg border border-slate-200 p-5">
                                <div class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-violet-100 flex items-center justify-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-violet-600"><path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446A9 9 0 1 1 12 3z"/><path d="M17 4a2 2 0 0 0 2 2a2 2 0 0 0-2 2a2 2 0 0 0-2-2a2 2 0 0 0 2-2"/></svg>
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-sm font-semibold text-slate-800">{{ $t('AI Features') }}</label>
                                        <p class="text-xs text-slate-500 mt-0.5">{{ $t('Master toggle for all AI features: keyword AI matching, AI fallback, and AI Reply Assistant.') }}</p>
                                    </div>
                                    <button
                                        type="button"
                                        @click="form.ai_enabled = !form.ai_enabled"
                                        class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-1 shrink-0"
                                        :class="form.ai_enabled ? 'bg-primary' : 'bg-slate-300'"
                                    >
                                        <span
                                            class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition-transform duration-200"
                                            :class="form.ai_enabled ? 'translate-x-[18px]' : 'translate-x-[3px]'"
                                        />
                                    </button>
                                </div>
                                <div v-if="!form.ai_enabled" class="mt-3 text-xs text-slate-400">
                                    {{ $t('All AI features are disabled. Only exact keyword matching and basic replies will work.') }}
                                </div>
                            </div>

                            <!-- AI-Only Mode -->
                            <div class="rounded-lg border border-slate-200 p-5" :class="!form.ai_enabled ? 'opacity-50 pointer-events-none' : ''">
                                <div class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-indigo-100 flex items-center justify-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-indigo-600"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 12l2 2l4-4"/></svg>
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-sm font-semibold text-slate-800">{{ $t('AI-Only Mode') }}</label>
                                        <p class="text-xs text-slate-500 mt-0.5">{{ $t('When enabled, only AI responds to messages. Triggers are bypassed — only the Fallback trigger is used as a safety net when AI cannot answer.') }}</p>
                                    </div>
                                    <button
                                        type="button"
                                        @click="form.ai_only_mode = !form.ai_only_mode"
                                        class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-1 shrink-0"
                                        :class="form.ai_only_mode ? 'bg-primary' : 'bg-slate-300'"
                                    >
                                        <span
                                            class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition-transform duration-200"
                                            :class="form.ai_only_mode ? 'translate-x-[18px]' : 'translate-x-[3px]'"
                                        />
                                    </button>
                                </div>
                            </div>

                            <!-- Default Confidence Threshold -->
                            <div class="rounded-lg border border-slate-200 p-5" :class="!form.ai_enabled ? 'opacity-50 pointer-events-none' : ''">
                                <div class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-emerald-100 flex items-center justify-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-emerald-600"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-sm font-semibold text-slate-800">{{ $t('Default Confidence Threshold') }}</label>
                                        <p class="text-xs text-slate-500 mt-0.5">{{ $t('Org-level default for AI matching and fallback. Individual triggers can override this.') }}</p>
                                    </div>
                                </div>
                                <div class="mt-4 rounded-lg border border-violet-100 bg-violet-50/50 p-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <label class="text-xs font-medium text-slate-600">{{ $t('Confidence required') }}</label>
                                        <span class="text-sm font-bold text-primary tabular-nums">{{ form.default_confidence_threshold }}%</span>
                                    </div>
                                    <input
                                        v-model.number="form.default_confidence_threshold"
                                        type="range"
                                        min="0"
                                        max="100"
                                        step="5"
                                        class="w-full h-2 rounded-lg appearance-none cursor-pointer accent-primary"
                                    />
                                    <div class="flex justify-between text-[10px] text-slate-400 mt-1">
                                        <span>{{ $t('More matches') }}</span>
                                        <span>{{ $t('More accurate') }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Knowledge Base Summary -->
                            <div class="rounded-lg border border-slate-200 p-5">
                                <div class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-blue-100 flex items-center justify-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-blue-600"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-sm font-semibold text-slate-800">{{ $t('Knowledge Base') }}</label>
                                        <p class="text-xs text-slate-500 mt-0.5">{{ $t('Q&A entries used by AI Fallback to answer questions.') }}</p>
                                    </div>
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="kbEntryCount > 0 ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-500 border border-slate-200'"
                                    >
                                        {{ kbEntryCount }} {{ $t('entries') }}
                                    </span>
                                </div>
                                <div class="mt-3">
                                    <a href="/automation/knowledge-base" class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:text-[#045a47] transition-colors">
                                        {{ $t('Manage Knowledge Base') }}
                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                                    </a>
                                </div>
                            </div>

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
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps(['settings', 'kbEntryCount', 'aimodule', 'fbmodule']);

const form = ref({
    ai_enabled: props.settings?.ai_enabled || false,
    ai_only_mode: props.settings?.ai_only_mode || false,
    default_confidence_threshold: props.settings?.default_confidence_threshold || 75,
});

function submitForm() {
    router.put('/automation/ai-settings', form.value, {
        preserveScroll: true,
    });
}
</script>