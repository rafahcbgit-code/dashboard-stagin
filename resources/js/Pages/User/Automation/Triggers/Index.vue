<template>
    <SettingLayout :aimodule="aimodule" :fbmodule="fbmodule">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center">
                <div class="md:w-[68em] w-full">
                    <div class="bg-white border border-slate-200 rounded-xl text-sm pb-6 px-6 mb-20">
                        <!-- Header -->
                        <div class="flex items-center justify-between pt-5 pb-4 border-b border-slate-100">
                            <div>
                                <h4 class="text-lg font-semibold text-slate-800">{{ $t('Triggers') }}</h4>
                                <p class="text-sm text-slate-500 mt-0.5">{{ $t('Define how your bot responds to messages.') }}</p>
                            </div>
                            <button
                                v-if="!aiOnlyMode"
                                @click="showCreateModal = true"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-[#045a47] transition-colors"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14m7-7H5"/></svg>
                                {{ $t('Create Trigger') }}
                            </button>
                        </div>

                        <!-- AI-Only Mode Banner -->
                        <div v-if="aiOnlyMode" class="mt-3 flex items-start gap-2 rounded-lg bg-indigo-50 border border-indigo-200 px-3.5 py-2.5 text-xs text-indigo-800">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 mt-0.5"><path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446A9 9 0 1 1 12 3z"/><path d="M17 4a2 2 0 0 0 2 2a2 2 0 0 0-2 2a2 2 0 0 0-2-2a2 2 0 0 0 2-2"/></svg>
                            <div>
                                <span class="font-semibold">{{ $t('AI-Only Mode is active.') }}</span>
                                {{ $t('Only AI responds to messages. All triggers are bypassed except the Fallback trigger, which serves as a safety net when AI cannot answer.') }}
                            </div>
                        </div>

                        <!-- Search -->
                        <div class="mt-4 bg-slate-100 md:bg-slate-50 flex items-center border border-primary md:border-none md:shadow-sm h-10 w-full md:w-80 rounded-[0.5rem] text-sm">
                            <span class="pl-3">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 15l6 6m-11-4a7 7 0 1 1 0-14a7 7 0 0 1 0 14Z"/></svg>
                            </span>
                            <input @input="search" v-model="params.search" type="text" class="outline-none px-4 w-full bg-slate-100 md:bg-slate-50" :placeholder="$t('Search by name')">
                            <button v-if="isSearching === false && params.search" @click="clearSearch" type="button" class="pr-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10s10-4.5 10-10S17.5 2 12 2zm3.7 12.3c.4.4.4 1 0 1.4c-.4.4-1 .4-1.4 0L12 13.4l-2.3 2.3c-.4.4-1 .4-1.4 0c-.4-.4-.4-1 0-1.4l2.3-2.3l-2.3-2.3c-.4-.4-.4-1 0-1.4c.4-.4 1-.4 1.4 0l2.3 2.3l2.3-2.3c.4-.4 1-.4 1.4 0c.4.4.4 1 0 1.4L13.4 12l2.3 2.3z"/></svg>
                            </button>
                            <span v-if="isSearching" class="pr-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" class="animate-spin"><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="0 12 12;90 12 12;180 12 12;270 12 12"/><animate attributeName="opacity" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" begin="0.2s" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="30 12 12;120 12 12;210 12 12;300 12 12"/><animate attributeName="opacity" begin="0.2s" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" begin="0.4s" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="60 12 12;150 12 12;240 12 12;330 12 12"/><animate attributeName="opacity" begin="0.4s" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle></svg>
                            </span>
                        </div>

                        <!-- System Triggers Section -->
                        <div v-if="systemTriggersList.length > 0" class="mt-4">
                            <div class="flex items-center gap-2 mb-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-sky-500"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                <h5 class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('System Triggers') }}</h5>
                            </div>
                            <div class="space-y-2">
                                <div
                                    v-for="trigger in systemTriggersList"
                                    :key="trigger.uuid"
                                    class="group relative flex items-center gap-3 rounded-lg border px-4 py-3 transition-all duration-150"
                                    :class="[
                                        (aiOnlyMode && trigger.type !== 'fallback')
                                            ? 'bg-slate-50/60 border-slate-100 opacity-50 pointer-events-none'
                                            : trigger.is_enabled
                                                ? 'bg-slate-50 border-slate-200 hover:border-slate-300'
                                                : 'bg-slate-50/60 border-slate-100 opacity-70'
                                    ]"
                                >
                                    <!-- Type Icon -->
                                    <div
                                        class="flex-shrink-0 w-9 h-9 rounded-lg flex items-center justify-center text-white text-sm font-bold"
                                        :class="typeIconColor(trigger.type)"
                                    >
                                        {{ typeIcon(trigger.type) }}
                                    </div>

                                    <!-- Trigger Info -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-sm text-slate-800 truncate">{{ $t(trigger.name) }}</span>
                                            <span class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider bg-sky-50 text-sky-600 border border-sky-100">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12c5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
                                                {{ $t('System') }}
                                            </span>
                                            <span v-if="aiOnlyMode && trigger.type === 'fallback'" class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider bg-indigo-50 text-indigo-600 border border-indigo-200">
                                                {{ $t('AI-Only') }}
                                            </span>
                                            <span v-if="aiOnlyMode && trigger.type !== 'fallback'" class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider bg-slate-100 text-slate-400 border border-slate-200">
                                                {{ $t('Bypassed') }}
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-1.5 mt-0.5 flex-wrap">
                                            <template v-if="trigger.flow">
                                                <span class="text-xs text-slate-500">
                                                    {{ $t('Flow') }}: <a :href="'/automation/flows/' + trigger.flow.uuid" target="_blank" class="font-medium text-primary hover:underline">{{ trigger.flow.name }}</a>
                                                </span>
                                            </template>
                                            <template v-else>
                                                <span class="text-xs text-slate-400 italic">{{ $t('No flow linked') }}</span>
                                            </template>

                                            <!-- Rate Limit -->
                                            <template v-if="trigger.rate_limit_type !== 'none'">
                                                <span class="text-slate-200">·</span>
                                                <span class="text-[11px] text-slate-400">{{ formatRateLimit(trigger.rate_limit_type) }}</span>
                                            </template>
                                        </div>
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity" :class="trigger.is_enabled ? '' : 'opacity-100'">
                                        <!-- Toggle -->
                                        <button
                                            @click="toggleTrigger(trigger)"
                                            class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-1"
                                            :class="trigger.is_enabled ? 'bg-primary' : 'bg-slate-300'"
                                        >
                                            <span
                                                class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition-transform duration-200"
                                                :class="trigger.is_enabled ? 'translate-x-[18px]' : 'translate-x-[3px]'"
                                            />
                                        </button>
                                        <!-- Edit -->
                                        <button
                                            @click="openEditModal(trigger)"
                                            class="p-1 rounded text-slate-400 hover:text-primary hover:bg-slate-50 transition-colors"
                                            :title="$t('Edit')"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Custom Triggers Section -->
                        <div class="mt-4">
                            <div class="flex items-center gap-2 mb-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-slate-400"><path d="M12 5v14m7-7H5"/></svg>
                                <h5 class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $t('Custom Triggers') }}</h5>
                            </div>

                            <!-- Custom Triggers Empty State -->
                            <div v-if="localTriggers.length === 0" class="flex flex-col items-center justify-center py-10 text-slate-400">
                                <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mb-2 opacity-40"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
                                <p class="text-sm font-medium text-slate-500">{{ $t('No custom triggers yet') }}</p>
                                <p class="text-xs text-slate-400 mt-1">{{ $t('Create your first trigger to control how your bot responds to specific keywords.') }}</p>
                            </div>

                            <!-- Custom Trigger List -->
                            <div v-else class="space-y-2">
                                <div
                                    v-for="trigger in localTriggers"
                                    :key="trigger.uuid"
                                    class="group relative flex items-center gap-3 rounded-lg border px-4 py-3 transition-all duration-150"
                                    :class="[
                                        (aiOnlyMode && trigger.type !== 'fallback')
                                            ? 'bg-slate-50/60 border-slate-100 opacity-50 pointer-events-none'
                                            : trigger.is_enabled
                                                ? 'bg-white border-slate-200 hover:border-slate-300 hover:shadow-sm'
                                                : 'bg-slate-50/60 border-slate-100 opacity-70'
                                    ]"
                                >
                                    <!-- Type Icon -->
                                    <div
                                        class="flex-shrink-0 w-9 h-9 rounded-lg flex items-center justify-center text-white text-sm font-bold"
                                        :class="typeIconColor(trigger.type)"
                                    >
                                        {{ typeIcon(trigger.type) }}
                                    </div>

                                    <!-- Trigger Info -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-sm text-slate-800 truncate">{{ trigger.name }}</span>
                                            <span v-if="trigger.is_system" class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider bg-sky-50 text-sky-600 border border-sky-100">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12c5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
                                                {{ $t('System') }}
                                            </span>
                                            <span v-if="aiOnlyMode && trigger.type === 'fallback'" class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider bg-indigo-50 text-indigo-600 border border-indigo-200">
                                                {{ $t('AI-Only') }}
                                            </span>
                                            <span v-if="aiOnlyMode && trigger.type !== 'fallback'" class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider bg-slate-100 text-slate-400 border border-slate-200">
                                                {{ $t('Bypassed') }}
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-1.5 mt-0.5 flex-wrap">
                                            <!-- Linked Flow -->
                                            <template v-if="trigger.flow">
                                                <span class="text-xs text-slate-500">
                                                    {{ $t('Flow') }}: <a :href="'/automation/flows/' + trigger.flow.uuid" target="_blank" class="font-medium text-primary hover:underline">{{ trigger.flow.name }}</a>
                                                </span>
                                            </template>
                                            <template v-else>
                                                <span class="text-xs text-slate-400 italic">{{ $t('No flow linked') }}</span>
                                            </template>

                                            <!-- Keyword Tags -->
                                            <template v-if="trigger.keywords && trigger.keywords.length > 0">
                                                <span class="text-slate-200">·</span>
                                                <span
                                                    v-for="(kw, i) in trigger.keywords.slice(0, 4)"
                                                    :key="i"
                                                    class="inline-flex items-center px-1.5 py-px rounded text-[11px] font-medium"
                                                    :class="trigger.match_mode === 'ai'
                                                        ? 'bg-violet-50 text-violet-700 border border-violet-100'
                                                        : 'bg-emerald-50 text-emerald-700 border border-emerald-100'"
                                                >
                                                    {{ kw }}
                                                </span>
                                                <span v-if="trigger.keywords.length > 4" class="text-[11px] text-slate-400 font-medium">
                                                    +{{ trigger.keywords.length - 4 }}
                                                </span>
                                            </template>

                                            <!-- Match Mode Badge -->
                                            <template v-if="trigger.type === 'new_message'">
                                                <span class="text-slate-200">·</span>
                                                <span
                                                    class="inline-flex items-center px-1.5 py-px rounded text-[10px] font-semibold uppercase tracking-wider"
                                                    :class="trigger.match_mode === 'ai'
                                                        ? 'bg-violet-50 text-violet-600 border border-violet-100'
                                                        : 'bg-emerald-50 text-emerald-600 border border-emerald-100'"
                                                >
                                                    {{ trigger.match_mode === 'ai' ? 'AI' : 'Exact' }}
                                                </span>
                                            </template>

                                            <!-- Rate Limit -->
                                            <template v-if="trigger.rate_limit_type !== 'none'">
                                                <span class="text-slate-200">·</span>
                                                <span class="text-[11px] text-slate-400">{{ formatRateLimit(trigger.rate_limit_type) }}</span>
                                            </template>
                                        </div>
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity" :class="trigger.is_enabled ? '' : 'opacity-100'">
                                        <!-- Toggle -->
                                        <button
                                            @click="toggleTrigger(trigger)"
                                            class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-1"
                                            :class="trigger.is_enabled ? 'bg-primary' : 'bg-slate-300'"
                                        >
                                            <span
                                                class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition-transform duration-200"
                                                :class="trigger.is_enabled ? 'translate-x-[18px]' : 'translate-x-[3px]'"
                                            />
                                        </button>
                                        <!-- Edit -->
                                        <button
                                            @click="openEditModal(trigger)"
                                            class="p-1 rounded text-slate-400 hover:text-primary hover:bg-slate-50 transition-colors"
                                            :title="$t('Edit')"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </button>
                                        <!-- Delete (only for non-system) -->
                                        <button
                                            v-if="!trigger.is_system"
                                            @click="deleteTrigger(trigger)"
                                            class="p-1 rounded text-slate-400 hover:text-red-500 hover:bg-red-50 transition-colors"
                                            :title="$t('Delete')"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <Pagination v-if="triggerGroups.last_page > 1" class="mt-3" :pagination="triggerGroups" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <Modal :isOpen="showCreateModal || showEditModal" @close="closeModal" :label="editingTrigger ? $t('Edit Trigger') : $t('Create Trigger')" :closeBtn="true">
            <form @submit.prevent="submitForm" class="space-y-5 mt-1">
                <!-- Name -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">{{ $t('Trigger Name') }}</label>
                    <input
                        v-model="form.name"
                        type="text"
                        class="w-full rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-800 placeholder:text-slate-400 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors"
                        :placeholder="$t('e.g., Refund Handler')"
                        required
                    />
                </div>

                <!-- Type badge (read-only) -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">{{ $t('Type') }}</label>
                    <div class="flex items-center gap-2">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                            :class="typeIconColor(form.type) + ' text-white'"
                        >
                            {{ typeIcon(form.type) }}
                            {{ formatType(form.type) }}
                        </span>
                        <span class="text-xs text-slate-400">{{ formatTypeDescription(form.type, form.rate_limit_type) }}</span>
                    </div>
                </div>

                <!-- Keywords (only for new_message type) -->
                <div v-if="form.type === 'new_message'" class="space-y-4">
                    <!-- Keywords Tag Input -->
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">{{ $t('Keywords') }}</label>
                        <p class="text-xs text-slate-500 mb-2">{{ $t('Type a keyword and press Enter to add it.') }}</p>
                        <div class="flex flex-wrap gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 focus-within:border-primary focus-within:ring-1 focus-within:ring-primary/30 transition-colors">
                            <span
                                v-for="(kw, i) in form.keywords"
                                :key="i"
                                class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium transition-colors"
                                :class="form.match_mode === 'ai'
                                    ? 'bg-violet-50 text-violet-700 border border-violet-200'
                                    : 'bg-emerald-50 text-emerald-700 border border-emerald-200'"
                            >
                                {{ kw }}
                                <button
                                    type="button"
                                    @click="removeKeyword(i)"
                                    class="ml-0.5 rounded-full hover:bg-black/10 p-px transition-colors"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                </button>
                            </span>
                            <input
                                v-model="keywordInput"
                                type="text"
                                class="flex-1 min-w-[120px] border-0 bg-transparent px-1 py-1 text-sm text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-0"
                                :placeholder="form.keywords.length === 0 ? $t('Type keyword and press Enter') : $t('Add another...')"
                                @keydown.enter.prevent="addKeyword"
                            />
                        </div>
                        <!-- Keyword duplicate error -->
                        <div v-if="keywordError" class="mt-1.5 flex items-start gap-1.5 text-xs text-red-600">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 mt-0.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <span>{{ keywordError }}</span>
                        </div>
                        <div v-if="keywordChecking" class="mt-1.5 flex items-center gap-1.5 text-xs text-slate-400">
                            <svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            <span>{{ $t('Checking keyword...') }}</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">{{ $t('Match Mode') }}</label>
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                @click="form.match_mode = 'exact'"
                                class="rounded-lg border px-3 py-3 text-left transition-all duration-150"
                                :class="form.match_mode === 'exact'
                                    ? 'border-emerald-300 bg-emerald-50 ring-1 ring-emerald-200'
                                    : 'border-slate-200 hover:border-slate-300'"
                            >
                                <div class="flex items-center gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full" :class="form.match_mode === 'exact' ? 'bg-emerald-500' : 'bg-slate-300'"></span>
                                    <span class="text-sm font-semibold" :class="form.match_mode === 'exact' ? 'text-emerald-700' : 'text-slate-600'">{{ $t('Exact Match') }}</span>
                                </div>
                                <p class="text-[11px] mt-1 ml-[18px]" :class="form.match_mode === 'exact' ? 'text-emerald-600' : 'text-slate-400'">{{ $t('Message must exactly equal a keyword') }}</p>
                            </button>
                            <button
                                type="button"
                                @click="form.match_mode = 'ai'"
                                class="rounded-lg border px-3 py-3 text-left transition-all duration-150"
                                :class="form.match_mode === 'ai'
                                    ? 'border-violet-300 bg-violet-50 ring-1 ring-violet-200'
                                    : 'border-slate-200 hover:border-slate-300'"
                            >
                                <div class="flex items-center gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full" :class="form.match_mode === 'ai' ? 'bg-violet-500' : 'bg-slate-300'"></span>
                                    <span class="text-sm font-semibold" :class="form.match_mode === 'ai' ? 'text-violet-700' : 'text-slate-600'">{{ $t('AI Match') }}</span>
                                </div>
                                <p class="text-[11px] mt-1 ml-[18px]" :class="form.match_mode === 'ai' ? 'text-violet-600' : 'text-slate-400'">{{ $t('AI understands intent and similar phrases') }}</p>
                            </button>
                        </div>
                    </div>

                    <!-- AI Match Explanation (only when AI mode) -->
                    <div v-if="form.match_mode === 'ai'" class="rounded-lg border border-violet-100 bg-violet-50/50 p-4">
                        <div class="flex items-start gap-2 mb-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 mt-0.5 text-violet-500"><path d="M12 2a5 5 0 0 1 5 5v3a5 5 0 0 1-10 0V7a5 5 0 0 1 5-5z"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="m4.93 19.07 1.41-1.41"/><path d="m17.66 6.34 1.41-1.41"/></svg>
                            <span class="text-sm font-semibold text-slate-700">{{ $t('How AI Match Works') }}</span>
                        </div>
                        <p class="text-xs text-slate-600 mb-2">{{ $t('AI Match understands the intent behind messages, not just exact words. When a trigger named "Orders" has keywords like [order, ordering, place order], AI will match:') }}</p>
                        <ul class="text-xs text-slate-600 space-y-1 ml-4 mb-2">
                            <li>{{ $t('Exact phrases: "order" → matches') }}</li>
                            <li>{{ $t('Synonyms & paraphrases: "I want to buy" → matches') }}</li>
                            <li>{{ $t('Natural language: "Can I place an order?" → matches') }}</li>
                            <li>{{ $t('Misspellings: "ordr" → matches') }}</li>
                        </ul>
                        <p class="text-xs text-slate-500">{{ $t('The confidence threshold is managed by your admin in Bot Settings and controls how strictly AI matches — higher means fewer but more accurate matches.') }}</p>
                    </div>
                </div>

                <!-- Linked Flow -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">{{ $t('Linked Flow') }}</label>
                    <select
                        v-model="form.flow_id"
                        class="w-full rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-800 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors"
                    >
                        <option :value="null">{{ $t('— No flow linked —') }}</option>
                        <option v-for="flow in flows" :key="flow.id" :value="flow.id">
                            {{ flow.name }} <span v-if="flow.status === 'active'" class="text-emerald-600">●</span>
                        </option>
                    </select>
                    <p class="text-[11px] text-slate-400 mt-1">{{ $t('The flow that executes when this trigger matches a message.') }}</p>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-2.5 pt-3 border-t border-slate-100">
                    <button type="button" @click="closeModal" class="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50 transition-colors">
                        {{ $t('Cancel') }}
                    </button>
                    <button type="submit" class="rounded-lg bg-primary px-5 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-[#045a47] transition-colors">
                        {{ editingTrigger ? $t('Save Changes') : $t('Create Trigger') }}
                    </button>
                </div>
            </form>
        </Modal>
    </SettingLayout>
</template>

<script setup>
import SettingLayout from './../Layout.vue';
import { Link } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import { useI18n } from 'vue-i18n';
import debounce from 'lodash/debounce';

const props = defineProps(['systemTriggers', 'triggerGroups', 'flows', 'filters', 'aimodule', 'fbmodule', 'aiOnlyMode']);
const { t } = useI18n();

const systemTriggersList = ref([...(props.systemTriggers || [])]);
const localTriggers = ref([...(props.triggerGroups?.data || [])]);

watch(() => props.triggerGroups?.data, (newGroups) => {
    localTriggers.value = [...(newGroups || [])];
}, { deep: true });

watch(() => props.systemTriggers, (newSystem) => {
    systemTriggersList.value = [...(newSystem || [])];
}, { deep: true });

const params = ref({
    search: props.filters?.search || null,
});

const isSearching = ref(false);

const clearSearch = () => {
    params.value.search = null;
    runSearch();
};

const search = debounce(() => {
    isSearching.value = true;
    runSearch();
}, 1000);

const runSearch = () => {
    isSearching.value = false;
    router.visit('/automation/triggers', {
        method: 'get',
        data: params.value,
    });
};

const showCreateModal = ref(false);
const showEditModal = ref(false);
const editingTrigger = ref(null);
const keywordInput = ref('');
const keywordError = ref('');
const keywordChecking = ref(false);
let keywordCheckTimeout = null;

const defaultForm = () => ({
    name: '',
    type: 'new_message',
    keywords: [],
    match_mode: 'exact',
    flow_id: null,
    rate_limit_type: 'none',
    is_enabled: true,
});

const form = ref(defaultForm());

function typeIcon(type) {
    const icons = {
        new_contact: '👋',
        new_message: '💬',
        greeting: '☀️',
        away: '🌙',
        fallback: '🔄',
    };
    return icons[type] || '⚡';
}

function typeIconColor(type) {
    const colors = {
        new_contact: 'bg-emerald-600',
        new_message: 'bg-blue-600',
        greeting: 'bg-amber-500',
        away: 'bg-indigo-600',
        fallback: 'bg-slate-600',
    };
    return colors[type] || 'bg-primary';
}

function formatType(type) {
    const types = {
        new_contact: 'New Contact',
        new_message: 'Keywords',
        greeting: 'Greeting',
        away: 'Away',
        fallback: 'Fallback',
    };
    return t(types[type] || type);
}

function formatRateLimit(type) {
    const limits = {
        none: 'No limit',
        lifetime: 'Once ever',
        '24h': 'Once/24h',
    };
    return t(limits[type] || type);
}

function formatTypeDescription(type, rateLimitType) {
    const descriptions = {
        new_contact: 'First message from a new contact',
        greeting: 'During working hours',
        away: 'Outside working hours',
        fallback: 'When no other trigger matches',
        new_message: rateLimitType === 'none' ? 'Fires every time' : rateLimitType === 'lifetime' ? 'Once per contact, ever' : 'Once per 24h per contact',
    };
    return descriptions[type] ? t(descriptions[type]) : '';
}

async function addKeyword() {
    const val = keywordInput.value.trim();
    if (!val) {
        keywordInput.value = '';
        return;
    }

    // Check for duplicates within the current form
    if (form.value.keywords.some(k => k.toLowerCase() === val.toLowerCase())) {
        keywordError.value = t('This keyword already exists in this trigger.');
        keywordInput.value = '';
        return;
    }

    // Check against existing triggers in the organization
    keywordChecking.value = true;
    keywordError.value = '';
    try {
        const response = await axios.get('/automation/triggers/check-keyword', {
            params: {
                keyword: val.toLowerCase(),
                exclude: editingTrigger.value?.uuid || '',
            },
        });

        if (!response.data.available) {
            keywordError.value = t('Keyword "{keyword}" is already used in trigger: {trigger}', {
                keyword: val,
                trigger: response.data.trigger_name || t('another trigger'),
            });
            keywordInput.value = '';
            return;
        }
    } catch (error) {
        // If API fails, allow the keyword — backend validation is the safety net
    } finally {
        keywordChecking.value = false;
    }

    form.value.keywords.push(val);
    keywordInput.value = '';
}

function removeKeyword(index) {
    form.value.keywords.splice(index, 1);
}

function openEditModal(trigger) {
    editingTrigger.value = trigger;
    form.value = {
        name: trigger.name,
        type: trigger.type,
        keywords: trigger.keywords ? [...trigger.keywords] : [],
        match_mode: trigger.match_mode || 'exact',
        flow_id: trigger.flow_id,
        rate_limit_type: trigger.rate_limit_type || 'none',
        is_enabled: trigger.is_enabled,
    };
    showEditModal.value = true;
}

function closeModal() {
    showCreateModal.value = false;
    showEditModal.value = false;
    editingTrigger.value = null;
    form.value = defaultForm();
    keywordInput.value = '';
    keywordError.value = '';
    keywordChecking.value = false;
}

async function submitForm() {
    const payload = { ...form.value };
    if (payload.type !== 'new_message') {
        payload.keywords = null;
        payload.match_mode = 'exact';
    }

    // Remove ai_confidence since it's no longer user-configurable
    delete payload.ai_confidence;

    // Validate keywords against organization duplicates before submitting
    if (payload.type === 'new_message' && payload.keywords && payload.keywords.length > 0) {
        for (const kw of payload.keywords) {
            try {
                const response = await axios.get('/automation/triggers/check-keyword', {
                    params: {
                        keyword: kw.toLowerCase(),
                        exclude: editingTrigger.value?.uuid || '',
                    },
                });
                if (!response.data.available) {
                    keywordError.value = t('Keyword "{keyword}" is already used in trigger: {trigger}', {
                        keyword: kw,
                        trigger: response.data.trigger_name || t('another trigger'),
                    });
                    return;
                }
            } catch (error) {
                // If API fails, continue — backend validation is the safety net
            }
        }
    }

    if (editingTrigger.value) {
        router.put(`/automation/triggers/${editingTrigger.value.uuid}`, payload, {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    } else {
        router.post('/automation/triggers', payload, {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    }
}

function toggleTrigger(trigger) {
    router.post(`/automation/triggers/${trigger.uuid}/toggle`, {
        is_enabled: !trigger.is_enabled,
    }, { preserveScroll: true });
}

function deleteTrigger(trigger) {
    if (!confirm(t('Are you sure you want to delete this trigger?'))) return;
    router.delete(`/automation/triggers/${trigger.uuid}`, { preserveScroll: true });
}

</script>
