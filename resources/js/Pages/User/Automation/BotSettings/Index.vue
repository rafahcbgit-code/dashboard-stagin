<template>
    <SettingLayout :aimodule="aimodule" :fbmodule="fbmodule">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center">
                <div class="md:w-[68em] w-full">
                    <div class="bg-white border border-slate-200 rounded-xl text-sm pb-6 px-6 mb-20">
                        <!-- Header -->
                        <div class="flex items-center justify-between pt-5 pb-4 border-b border-slate-100">
                            <div>
                                <h4 class="text-lg font-semibold text-slate-800">{{ $t('Behavior & AI') }}</h4>
                                <p class="text-sm text-slate-500 mt-0.5">{{ $t('Control how your bot responds, escalates, and uses AI.') }}</p>
                            </div>
                        </div>

                        <form @submit.prevent="submitForm" class="mt-6 space-y-6">
                            <!-- ============================================ -->
                            <!-- SECTION 1: Master Controls -->
                            <!-- ============================================ -->
                            <div class="flex items-center gap-2 mb-2">
                                <div class="w-1 h-5 rounded-full bg-slate-400"></div>
                                <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wide">{{ $t('Master Controls') }}</h3>
                            </div>
                            <p class="text-xs text-slate-400 -mt-4 mb-4">{{ $t('Enable or disable automation and AI for your workspace.') }}</p>

                            <!-- Automation Master Switch -->
                            <div class="rounded-lg border-2 p-5" :class="form.automation_enabled ? 'border-primary/30 bg-primary/[0.02]' : 'border-red-200 bg-red-50/30'">
                                <div class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-9 h-9 rounded-lg flex items-center justify-center" :class="form.automation_enabled ? 'bg-primary/10' : 'bg-red-100'">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :class="form.automation_enabled ? 'text-primary' : 'text-red-500'"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-sm font-semibold text-slate-800">{{ $t('Automation') }}</label>
                                        <p class="text-xs text-slate-500 mt-0.5">
                                            <span v-if="form.automation_enabled">{{ $t('Your bot responds automatically to messages using triggers, flows, and AI.') }}</span>
                                            <span v-else class="text-red-600">{{ $t('All automation is paused. Incoming messages won\'t trigger any flows or AI responses.') }}</span>
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

                            <!-- AI Enabled -->
                            <div :class="{ 'opacity-50 pointer-events-none': !form.automation_enabled }">
                                <div class="rounded-lg border border-slate-200 p-5">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-violet-100 flex items-center justify-center">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-violet-600"><path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446A9 9 0 1 1 12 3z"/><path d="M17 4a2 2 0 0 0 2 2a2 2 0 0 0-2 2a2 2 0 0 0-2-2a2 2 0 0 0 2-2"/></svg>
                                        </div>
                                        <div class="flex-1">
                                            <label class="block text-sm font-semibold text-slate-800">{{ $t('AI Assistant') }}</label>
                                            <p class="text-xs text-slate-500 mt-0.5">
                                                <span v-if="form.ai_enabled">{{ $t('AI can understand messages, match keywords intelligently, and answer from your Knowledge Base.') }}</span>
                                                <span v-else>{{ $t('AI is completely disabled. Only exact keyword matching and manual replies will work.') }}</span>
                                            </p>
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
                                </div>

                                <!-- AI-Only Mode -->
                                <div class="rounded-lg border border-slate-200 p-5 mt-6" :class="!form.ai_enabled ? 'opacity-50 pointer-events-none' : ''">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-indigo-100 flex items-center justify-center">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-indigo-600"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 12l2 2l4-4"/></svg>
                                        </div>
                                        <div class="flex-1">
                                            <label class="block text-sm font-semibold text-slate-800">{{ $t('AI-Only Mode') }}</label>
                                            <p class="text-xs text-slate-500 mt-0.5">{{ $t('When ON, AI handles all incoming messages directly. Triggers and flows are bypassed — only the Fallback trigger is used as a safety net when AI cannot answer.') }}</p>
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
                                    <!-- AI-Only mode active banner -->
                                    <div v-if="form.ai_only_mode" class="mt-3 flex items-start gap-2 rounded-md bg-indigo-50 border border-indigo-100 px-3 py-2 text-xs text-indigo-700">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 mt-0.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                                        <span>{{ $t('AI-Only Mode is active. Triggers and flows are bypassed — only the Fallback trigger is used as a safety net.') }}</span>
                                    </div>
                                </div>

                                <!-- Dependency callout -->
                                <div class="rounded-lg bg-slate-50 border border-slate-200 p-4 text-xs text-slate-600 mt-4">
                                    <div class="flex items-start gap-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 mt-0.5 text-slate-400"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                                        <div>
                                            <span class="font-semibold">{{ $t('How they work together:') }}</span>
                                            <ul class="mt-1 space-y-0.5 list-disc list-inside">
                                                <li><strong>{{ $t('Automation') }}</strong> {{ $t('OFF disables everything below it.') }}</li>
                                                <li><strong>{{ $t('AI Assistant') }}</strong> {{ $t('OFF disables all AI features (matching, fallback, reply assist).') }}</li>
                                                <li><strong>{{ $t('AI-Only Mode') }}</strong> {{ $t('requires AI Assistant to be ON.') }}</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ============================================ -->
                            <!-- SECTION 2: When No Trigger Matches -->
                            <!-- ============================================ -->
                            <div :class="{ 'opacity-50 pointer-events-none': !form.automation_enabled }">
                                <div class="flex items-center gap-2 mt-4 mb-2">
                                    <div class="w-1 h-5 rounded-full bg-violet-500"></div>
                                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wide">{{ $t('When No Trigger Matches') }}</h3>
                                </div>
                                <p class="text-xs text-slate-400 mb-4">{{ $t('What happens when a customer message doesn\'t match any trigger.') }}</p>

                                <!-- AI Fallback -->
                                <div class="rounded-lg border border-slate-200 p-5" :class="!form.ai_enabled ? 'opacity-50 pointer-events-none' : ''">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-violet-100 flex items-center justify-center">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-violet-600"><path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446A9 9 0 1 1 12 3z"/><path d="M17 4a2 2 0 0 0 2 2a2 2 0 0 0-2 2a2 2 0 0 0-2-2a2 2 0 0 0 2-2"/></svg>
                                        </div>
                                        <div class="flex-1">
                                            <label class="block text-sm font-semibold text-slate-800">{{ $t('AI Answers from Knowledge Base') }}</label>
                                            <p class="text-xs text-slate-500 mt-0.5">{{ $t('When no trigger matches a message, AI searches your Knowledge Base for an answer. If AI isn\'t confident enough, the message falls through to the Fallback trigger.') }}</p>
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
                                            <span>{{ $t('Your Knowledge Base is empty. Add Q&A entries so AI has content to search.') }}
                                                <a href="/automation/knowledge-base" class="text-primary hover:underline">{{ $t('Manage Knowledge Base') }} &rarr;</a>
                                            </span>
                                        </div>

                                        <!-- Follow-up Message Toggle -->
                                        <div class="rounded-lg border border-slate-200 p-4">
                                            <div class="flex items-start gap-3">
                                                <div class="flex-shrink-0 w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-blue-600"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                                </div>
                                                <div class="flex-1">
                                                    <label class="block text-sm font-semibold text-slate-800">{{ $t('Ask for Feedback After AI Answers') }}</label>
                                                    <p class="text-xs text-slate-500 mt-0.5">{{ $t('Send a "Was this helpful?" prompt after the first AI answer in each conversation. Feedback is only asked once per conversation.') }}</p>
                                                </div>
                                                <button
                                                    type="button"
                                                    @click="form.ai_followup_enabled = !form.ai_followup_enabled"
                                                    class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-1 shrink-0"
                                                    :class="form.ai_followup_enabled ? 'bg-primary' : 'bg-slate-300'"
                                                >
                                                    <span
                                                        class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition-transform duration-200"
                                                        :class="form.ai_followup_enabled ? 'translate-x-[18px]' : 'translate-x-[3px]'"
                                                    />
                                                </button>
                                            </div>

                                            <!-- Customization fields when enabled -->
                                            <div v-if="form.ai_followup_enabled" class="mt-4 space-y-3">
                                                <div>
                                                    <label class="block text-xs font-medium text-slate-700 mb-1">{{ $t('Question text') }}</label>
                                                    <input
                                                        v-model="form.ai_followup_question"
                                                        type="text"
                                                        maxlength="60"
                                                        class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder:text-slate-400 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors"
                                                        :placeholder="$t('Was this helpful?')"
                                                    />
                                                    <p class="text-[10px] text-slate-400 mt-1">{{ form.ai_followup_question.length }}/60</p>
                                                </div>
                                                <div class="grid grid-cols-2 gap-3">
                                                    <div>
                                                        <label class="block text-xs font-medium text-slate-700 mb-1">{{ $t('Yes button') }}</label>
                                                        <input
                                                            v-model="form.ai_followup_yes_text"
                                                            type="text"
                                                            maxlength="20"
                                                            class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder:text-slate-400 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors"
                                                            :placeholder="$t('Yes, this helped')"
                                                        />
                                                        <p class="text-[10px] text-slate-400 mt-1">{{ form.ai_followup_yes_text.length }}/20</p>
                                                    </div>
                                                    <div>
                                                        <label class="block text-xs font-medium text-slate-700 mb-1">{{ $t('No button') }}</label>
                                                        <input
                                                            v-model="form.ai_followup_no_text"
                                                            type="text"
                                                            maxlength="20"
                                                            class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 placeholder:text-slate-400 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors"
                                                            :placeholder="$t('No, I need more help')"
                                                        />
                                                        <p class="text-[10px] text-slate-400 mt-1">{{ form.ai_followup_no_text.length }}/20</p>
                                                    </div>
                                                </div>
                                                <div class="rounded-md bg-slate-50 border border-slate-200 px-3 py-2 text-xs text-slate-500">
                                                    <div class="flex items-start gap-2">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 mt-0.5 text-slate-400"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                                                        <div>
                                                            <p>{{ $t('Feedback buttons are shown once per conversation. After the first AI answer, subsequent answers won\'t include them until the conversation times out.') }}</p>
                                                            <p class="mt-1">{{ $t('When the customer taps "No", the existing escalation behavior applies — the fallback counter increments and the conversation may be auto-assigned to an agent.') }}</p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- AI Escalation Toggle -->
                                        <div class="rounded-lg border border-slate-200 p-4">
                                            <div class="flex items-start gap-3">
                                                <div class="flex-shrink-0 w-8 h-8 rounded-lg bg-amber-50 flex items-center justify-center">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-amber-600"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                                </div>
                                                <div class="flex-1">
                                                    <label class="block text-sm font-semibold text-slate-800">{{ $t('AI-Powered Escalation Rules') }}</label>
                                                    <p class="text-xs text-slate-500 mt-0.5">{{ $t('AI evaluates incoming messages against your escalation rules and routes matching ones directly to a human agent, skipping the KB answer.') }}</p>
                                                </div>
                                                <button
                                                    type="button"
                                                    @click="form.ai_escalation_enabled = !form.ai_escalation_enabled"
                                                    class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-1 shrink-0"
                                                    :class="form.ai_escalation_enabled ? 'bg-primary' : 'bg-slate-300'"
                                                >
                                                    <span
                                                        class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition-transform duration-200"
                                                        :class="form.ai_escalation_enabled ? 'translate-x-[18px]' : 'translate-x-[3px]'"
                                                    />
                                                </button>
                                            </div>
                                            <div v-if="form.ai_escalation_enabled" class="mt-3">
                                                <a href="/automation/escalation" class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:text-[#045a47] transition-colors">
                                                    {{ $t('Configure escalation rules') }} &rarr;
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-else class="mt-2 text-xs text-slate-400">
                                        {{ $t('AI will not attempt to answer when no trigger matches. Messages will fall through to the Fallback trigger.') }}
                                    </div>
                                </div>
                            </div>

                            <!-- ============================================ -->
                            <!-- SECTION 3: Escalation to Humans -->
                            <!-- ============================================ -->
                            <div :class="{ 'opacity-50 pointer-events-none': !form.automation_enabled }">
                                <div class="flex items-center gap-2 mt-4 mb-2">
                                    <div class="w-1 h-5 rounded-full bg-amber-500"></div>
                                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wide">{{ $t('Escalation to Humans') }}</h3>
                                </div>
                                <p class="text-xs text-slate-400 mb-4">{{ $t('How conversations get handed off to your team.') }}</p>

                                <!-- Fallback Threshold -->
                                <div class="rounded-lg border border-slate-200 p-5">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-amber-100 flex items-center justify-center">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-amber-600"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                        </div>
                                        <div class="flex-1">
                                            <label class="block text-sm font-semibold text-slate-800">{{ $t('Auto-Escalation After') }}</label>
                                            <p class="text-xs text-slate-500 mt-0.5">{{ $t('After this many unanswered messages, the conversation is automatically sent to a human agent.') }}</p>
                                        </div>
                                        <!-- Toggle -->
                                        <button
                                            type="button"
                                            @click="form.auto_escalation_enabled = !form.auto_escalation_enabled"
                                            class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-1 shrink-0"
                                            :class="form.auto_escalation_enabled ? 'bg-primary' : 'bg-slate-300'"
                                        >
                                            <span
                                                class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition-transform duration-200"
                                                :class="form.auto_escalation_enabled ? 'translate-x-[18px]' : 'translate-x-[3px]'"
                                            />
                                        </button>
                                    </div>
                                    <div v-if="form.auto_escalation_enabled" class="mt-4 flex items-center gap-3">
                                        <input
                                            v-model.number="form.fallback_threshold"
                                            type="number"
                                            min="1"
                                            max="20"
                                            class="w-20 rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-center text-slate-800 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors"
                                            required
                                        />
                                        <span class="text-sm text-slate-500">{{ $t('unanswered messages') }}</span>
                                    </div>
                                    <div v-if="form.auto_escalation_enabled" class="mt-2 flex items-center gap-4 text-xs text-slate-400">
                                        <span>{{ $t('Example: After unanswered messages, the conversation is automatically escalated to a human agent.') }}</span>
                                    </div>
                                    <div v-else class="mt-2 text-xs text-slate-400">
                                        {{ $t('Conversations won\'t automatically escalate to a human agent. The fallback flow will keep running instead.') }}
                                    </div>
                                    <div v-if="form.auto_escalation_enabled" class="mt-3 hidden">
                                        <a href="/automation/escalation" class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:text-[#045a47] transition-colors">
                                            {{ $t('Configure specific escalation rules') }} &rarr;
                                        </a>
                                    </div>
                                </div>

                                <!-- Transfer Message -->
                                <div class="rounded-lg border border-slate-200 p-5 mt-6">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-indigo-100 flex items-center justify-center">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-indigo-600"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                        </div>
                                        <div class="flex-1">
                                            <label class="block text-sm font-semibold text-slate-800">{{ $t('Handoff Message') }}</label>
                                            <p class="text-xs text-slate-500 mt-0.5">{{ $t('This message is sent to the customer when their conversation is transferred to a human agent. Helps set expectations while they wait.') }}</p>
                                        </div>
                                        <!-- Toggle -->
                                        <button
                                            type="button"
                                            @click="form.escalation_transfer_message_enabled = !form.escalation_transfer_message_enabled"
                                            class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-1 shrink-0"
                                            :class="form.escalation_transfer_message_enabled ? 'bg-primary' : 'bg-slate-300'"
                                        >
                                            <span
                                                class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition-transform duration-200"
                                                :class="form.escalation_transfer_message_enabled ? 'translate-x-[18px]' : 'translate-x-[3px]'"
                                            />
                                        </button>
                                    </div>
                                    <div v-if="form.escalation_transfer_message_enabled" class="mt-4">
                                        <textarea
                                            v-model="form.escalation_transfer_message"
                                            rows="2"
                                            maxlength="500"
                                            class="w-full rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-800 placeholder:text-slate-400 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors resize-none"
                                            :placeholder="$t('e.g., You\'re being connected to a team member who can help. Please hold on a moment.')"
                                        />
                                    </div>
                                    <div v-else class="mt-2 text-xs text-slate-400">
                                        {{ $t('No message will be sent when escalating to a human agent.') }}
                                    </div>
                                </div>

                                <!-- Auto Re-enable Bot After Escalation -->
                                <div class="rounded-lg border border-slate-200 p-5 mt-6">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-amber-100 flex items-center justify-center">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-amber-600"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                        </div>
                                        <div class="flex-1">
                                            <label class="block text-sm font-semibold text-slate-800">{{ $t('Auto Re-enable Bot') }}</label>
                                            <p class="text-xs text-slate-500 mt-0.5">{{ $t('After a conversation is escalated to a human, the bot is automatically disabled. Choose how long to wait before re-enabling it, or leave it off until an agent manually re-enables it.') }}</p>
                                        </div>
                                    </div>
                                    <div class="mt-4 flex items-center gap-3">
                                        <select v-model.number="form.auto_reenable_bot_minutes"
                                            class="w-28 rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-800 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors">
                                            <option :value="0">{{ $t('Off') }}</option>
                                            <option :value="10">{{ $t('10 min') }}</option>
                                            <option :value="30">{{ $t('30 min') }}</option>
                                            <option :value="60">{{ $t('1 hour') }}</option>
                                            <option :value="120">{{ $t('2 hours') }}</option>
                                        </select>
                                        <span class="text-sm text-slate-500">{{ $t('after escalation') }}</span>
                                    </div>
                                    <div v-if="form.auto_reenable_bot_minutes > 0" class="mt-3 flex items-start gap-2 rounded-md bg-amber-50 border border-amber-100 px-3 py-2 text-xs text-amber-700">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0 mt-0.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                                        <span>{{ $t("If an agent manually toggles the bot before the timer expires, the auto-reenable is cancelled and the agent's choice takes priority.") }}</span>
                                    </div>
                                    <div v-else class="mt-3 text-xs text-slate-400">
                                        {{ $t('The bot will stay disabled after escalation until an agent manually re-enables it from the chat thread.') }}
                                    </div>
                                </div>
                            </div>

                            <!-- ============================================ -->
                            <!-- SECTION 4: Flow Behavior -->
                            <!-- ============================================ -->
                            <div :class="{ 'opacity-50 pointer-events-none': !form.automation_enabled }">
                                <div class="flex items-center gap-2 mt-4 mb-2">
                                    <div class="w-1 h-5 rounded-full bg-blue-500"></div>
                                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wide">{{ $t('Flow Behavior') }}</h3>
                                </div>
                                <p class="text-xs text-slate-400 mb-4">{{ $t('How long conversations stay active inside flows.') }}</p>

                                <!-- Flow Timeout -->
                                <div class="rounded-lg border border-slate-200 p-5">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-blue-100 flex items-center justify-center">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-blue-600"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                        </div>
                                        <div class="flex-1">
                                            <label class="block text-sm font-semibold text-slate-800">{{ $t('Flow Expiration Time') }}</label>
                                            <p class="text-xs text-slate-500 mt-0.5">{{ $t('How long a conversation stays in an active flow before it expires. Shorter times let customers re-trigger faster. Longer times give slow responders more time.') }}</p>
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
                                <div class="rounded-lg border border-slate-200 p-5 mt-6">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-emerald-100 flex items-center justify-center">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-emerald-600"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                        </div>
                                        <div class="flex-1">
                                            <label class="block text-sm font-semibold text-slate-800">{{ $t('Working Hours') }}</label>
                                            <p class="text-xs text-slate-500 mt-0.5">{{ $t('Controls when Greeting and Away triggers are active. Configure in General Settings.') }}</p>
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
                            </div>

                            <!-- ============================================ -->
                            <!-- SECTION 5: AI Matching Defaults (now managed by site admin) -->
                            <!-- ============================================ -->
                            <div :class="{ 'opacity-50 pointer-events-none': !form.automation_enabled, 'hidden': true } ">
                                <div class="flex items-center gap-2 mt-4 mb-2">
                                    <div class="w-1 h-5 rounded-full bg-emerald-500"></div>
                                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wide">{{ $t('AI Matching Defaults') }}</h3>
                                </div>

                                <!-- Info callout explaining this is now a global setting -->
                                <div class="rounded-lg border border-slate-200 p-5">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-emerald-100 flex items-center justify-center">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-emerald-600"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                        </div>
                                        <div class="flex-1">
                                            <label class="block text-sm font-semibold text-slate-800">{{ $t('AI Confidence Threshold') }}</label>
                                            <p class="text-xs text-slate-500 mt-0.5">{{ $t('The confidence threshold for AI trigger matching and Knowledge Base answers is managed by your site admin in the Admin Settings. This ensures consistent behavior across all organizations.') }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Save -->
                            <div class="flex justify-end pt-2">
                                <button type="submit" :disabled="saving" class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-5 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-[#045a47] transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                                    <svg v-if="!saving" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                    <svg v-else class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                    {{ saving ? $t('Saving...') : $t('Save Settings') }}
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

const props = defineProps(['settings', 'aiSettings', 'workingHours', 'kbEntryCount', 'aimodule', 'fbmodule']);

const saving = ref(false);

const form = ref({
    // Bot Settings fields
    automation_enabled: props.settings?.automation_enabled ?? true,
    fallback_threshold: props.settings?.fallback_threshold || 3,
    flow_timeout_minutes: props.settings?.flow_timeout_minutes || 30,
    ai_fallback_enabled: props.settings?.ai_fallback_enabled || false,
    ai_fallback_confidence: props.settings?.ai_fallback_confidence || 85,
    escalation_transfer_message_enabled: props.settings?.escalation_transfer_message_enabled ?? false,
    escalation_transfer_message: props.settings?.escalation_transfer_message || "You're being connected to a team member who can help. Please hold on a moment.",
    auto_reenable_bot_minutes: props.settings?.auto_reenable_bot_minutes || 0,
    ai_followup_enabled: props.settings?.ai_followup_enabled ?? false,
    ai_followup_question: props.settings?.ai_followup_question || 'Was this helpful?',
    ai_followup_yes_text: props.settings?.ai_followup_yes_text || 'Yes, this helped',
    ai_followup_no_text: props.settings?.ai_followup_no_text || 'No, I need more help',
    auto_escalation_enabled: props.settings?.auto_escalation_enabled ?? false,
    ai_escalation_enabled: props.settings?.ai_escalation_enabled ?? false,
    // AI Settings fields
    ai_enabled: props.aiSettings?.ai_enabled || false,
    ai_only_mode: props.aiSettings?.ai_only_mode || false,
    default_confidence_threshold: props.aiSettings?.default_confidence_threshold || 85,
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

async function submitForm() {
    saving.value = true;

    const payload = {
        // Bot settings
        automation_enabled: form.value.automation_enabled,
        fallback_threshold: form.value.fallback_threshold,
        flow_timeout_minutes: form.value.flow_timeout_minutes,
        ai_fallback_enabled: form.value.ai_fallback_enabled,
        ai_fallback_confidence: form.value.ai_fallback_confidence,
        escalation_transfer_message_enabled: form.value.escalation_transfer_message_enabled,
        escalation_transfer_message: form.value.escalation_transfer_message,
        auto_reenable_bot_minutes: form.value.auto_reenable_bot_minutes,
        ai_followup_enabled: form.value.ai_followup_enabled,
        ai_followup_question: form.value.ai_followup_question,
        ai_followup_yes_text: form.value.ai_followup_yes_text,
        ai_followup_no_text: form.value.ai_followup_no_text,
        auto_escalation_enabled: form.value.auto_escalation_enabled,
        ai_escalation_enabled: form.value.ai_escalation_enabled,
        // AI settings
        ai_enabled: form.value.ai_enabled,
        ai_only_mode: form.value.ai_only_mode,
        default_confidence_threshold: form.value.default_confidence_threshold,
    };

    try {
        await router.put('/automation/bot-settings', payload, { preserveScroll: true });
    } finally {
        saving.value = false;
    }
}
</script>
