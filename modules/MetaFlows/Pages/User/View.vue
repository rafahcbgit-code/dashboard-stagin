<template>
    <AppLayout>

        <div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] overflow-y-scroll">

        <!-- ── Page header ─────────────────────────────────────────────────── -->
        <div class="flex items-center justify-between mb-5 mt-8 md:mt-0 flex-wrap gap-3">
            <!-- Left: breadcrumb + flow name + metadata -->
            <div class="flex items-center gap-3.5">
                <a href="/automation/meta-flows"
                   class="w-8 h-8 flex items-center justify-center rounded-lg border border-slate-200 text-slate-400 hover:text-slate-700 hover:border-slate-300 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                </a>
                <div>
                    <div class="flex items-center gap-2.5">
                        <h4 class="text-base font-semibold text-slate-800">{{ flow.name }}</h4>
                        <span :class="['inline-flex items-center gap-1.5 text-[11px] font-medium px-2.5 py-0.5 rounded-full capitalize', statusColor(currentStatus)]">
                            <span :class="['w-1.5 h-1.5 rounded-full', statusDot(currentStatus)]"></span>
                            {{ currentStatus }}
                        </span>
                    </div>
                    <div class="flex items-center gap-4 mt-1 flex-wrap">
                        <span v-if="flow.categories?.length" class="flex items-center gap-1">
                            <span v-for="cat in flow.categories.slice(0, 2)" :key="cat" class="px-2 py-0.5 rounded-md bg-slate-100 text-slate-500 text-[11px] font-medium">
                                {{ cat.replace(/_/g, ' ').toLowerCase() }}
                            </span>
                            <span v-if="flow.categories.length > 2" class="text-slate-400 text-[11px]">+{{ flow.categories.length - 2 }}</span>
                        </span>
                        <span class="flex items-center gap-1.5 text-slate-500">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            <span class="text-sm font-semibold text-slate-700">{{ flow.responses_count ?? 0 }}</span>
                            <span class="text-[11px] text-slate-400">{{ (flow.responses_count ?? 0) === 1 ? $t('Response') : $t('Responses') }}</span>
                        </span>
                        <span class="flex items-center gap-1.5 text-slate-400 text-xs">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
                            {{ flow.updated_at_formatted ?? flow.updated_at }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Right: primary actions + more dropdown -->
            <div class="flex items-center gap-2">
                <!-- Primary: Save -->
                <button
                    @click="handleSave"
                    :disabled="saving"
                    class="rounded-lg bg-primary px-4 py-1.5 text-sm text-white shadow-sm hover:opacity-90 disabled:opacity-50 transition-opacity flex items-center gap-1.5"
                >
                    <svg v-if="saving" class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".4"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17,21 17,13 7,13 7,21"/><polyline points="7,3 7,8 15,8"/></svg>
                    {{ saving ? $t('Saving...') : $t('Save') }}
                </button>

                <!-- Primary: Publish (only for drafts) -->
                <button
                    v-if="currentStatus === 'draft'"
                    @click="handlePublish"
                    :disabled="acting"
                    class="rounded-lg border border-green-200 bg-green-50 px-3 py-1.5 text-sm text-green-700 hover:bg-green-100 disabled:opacity-50 transition-colors flex items-center gap-1.5"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m9 12 2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                    {{ acting ? $t('Publishing...') : $t('Publish') }}
                </button>

                <!-- More actions dropdown -->
                <div class="relative" ref="moreMenuRef">
                    <button
                        @click="moreMenuOpen = !moreMenuOpen"
                        class="w-8 h-8 flex items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-slate-700 transition-colors"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                    </button>
                    <div
                        v-if="moreMenuOpen"
                        class="absolute right-0 top-full mt-1 w-48 bg-white border border-slate-200 rounded-xl shadow-lg z-50 py-1 overflow-hidden"
                    >
                        <button
                            @click="handleSync(); moreMenuOpen = false"
                            :disabled="syncing"
                            class="w-full flex items-center gap-2.5 px-3.5 py-2 text-sm text-slate-600 hover:bg-slate-50 transition-colors disabled:opacity-50"
                        >
                            <svg class="animate-spin" v-if="syncing" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".4"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            <svg v-else xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
                            {{ syncing ? $t('Syncing...') : $t('Sync from Meta') }}
                        </button>

                        <a
                            v-if="flow.preview_url"
                            :href="flow.preview_url"
                            target="_blank"
                            class="flex items-center gap-2.5 px-3.5 py-2 text-sm text-slate-600 hover:bg-slate-50 transition-colors"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15,3 21,3 21,9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                            {{ $t('Open in WhatsApp') }}
                        </a>

                        <a
                            :href="'/automation/meta-flows/' + flow.uuid + '/responses'"
                            class="flex items-center gap-2.5 px-3.5 py-2 text-sm text-slate-600 hover:bg-slate-50 transition-colors"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            {{ flow.responses_count ?? 0 }} {{ $t('Responses') }}
                        </a>

                        <button
                            v-if="currentStatus === 'published'"
                            @click="handleDeprecate(); moreMenuOpen = false"
                            :disabled="acting"
                            class="w-full flex items-center gap-2.5 px-3.5 py-2 text-sm text-orange-600 hover:bg-orange-50 transition-colors disabled:opacity-50"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            {{ $t('Deprecate') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Feedback banner -->
        <div
            v-if="feedbackMessage"
            :class="['mb-4 flex items-center gap-2 text-sm px-4 py-2.5 rounded-lg border', feedbackIsError ? 'bg-red-50 text-red-700 border-red-200' : 'bg-green-50 text-green-700 border-green-200']"
        >
            <svg v-if="feedbackIsError" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m9 12 2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
            {{ feedbackMessage }}
            <button @click="feedbackMessage = null" class="ms-auto text-current opacity-60 hover:opacity-100">&times;</button>
        </div>

        <!-- ── Tab Navigation ──────────────────────────────────────────────── -->
        <!-- ── Tab Navigation ──────────────────────────────────────────────── -->
        <div class="flex items-center gap-1 border-b border-slate-200 mb-6">
            <button
                @click="activeTab = 'builder'"
                :class="[
                    'relative px-5 py-2.5 text-sm font-medium transition-colors flex items-center gap-2',
                    activeTab === 'builder'
                        ? 'text-primary'
                        : 'text-slate-400 hover:text-slate-600'
                ]"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                {{ $t('Builder') }}
                <span v-if="activeTab === 'builder'" class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary rounded-t-full"></span>
            </button>
            <button
                @click="activeTab = 'settings'"
                :class="[
                    'relative px-5 py-2.5 text-sm font-medium transition-colors flex items-center gap-2',
                    activeTab === 'settings'
                        ? 'text-primary'
                        : 'text-slate-400 hover:text-slate-600'
                ]"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                {{ $t('Settings') }}
                <span v-if="activeTab === 'settings'" class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary rounded-t-full"></span>
            </button>
        </div>

        <!-- ── Builder Tab ─────────────────────────────────────────────── -->
        <div v-show="activeTab === 'builder'">
                <div class="relative">
                    <!-- Show preview button (only when preview is hidden) -->
                    <button
                        v-if="!showPreview"
                        @click="showPreview = true"
                        class="absolute top-0 right-0 z-10 flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-medium text-slate-500 hover:bg-slate-50 transition-colors"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                        {{ $t('Show Preview') }}
                    </button>

                    <div class="flex gap-5 items-start">
                        <!-- Builder area -->
                        <div :class="[showPreview ? 'flex-1 min-w-0' : 'w-full']">
                            <div class="bg-white border border-slate-200 rounded-xl p-5">
                                <MetaFlowBuilder
                                    v-model="assetContent"
                                    @screenChange="activeScreenIndex = $event"
                                />
                            </div>

                            <!-- Advanced: Edit raw JSON -->
                            <div class="mt-3">
                                <button
                                    @click="showAdvanced = !showAdvanced"
                                    class="flex items-center gap-1.5 text-xs text-slate-400 hover:text-slate-600 transition-colors"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="16,18 22,12 18,6"/><polyline points="8,6 2,12 6,18"/></svg>
                                    <span>{{ $t('Advanced: Edit raw JSON') }}</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" :class="showAdvanced ? 'rotate-180' : ''" class="transition-transform"><path d="m6 9 6 6 6-6"/></svg>
                                </button>
                            </div>

                            <div v-if="showAdvanced" class="mt-3 border border-slate-200 rounded-xl overflow-hidden">
                                <div class="flex items-center justify-between px-4 py-2.5 bg-slate-50 border-b border-slate-100">
                                    <span class="font-mono text-xs font-medium text-slate-500">flow.json</span>
                                    <button @click="formatJson" class="text-primary text-[11px] font-medium hover:underline">{{ $t('Format') }}</button>
                                </div>
                                <textarea
                                    v-model="assetContent"
                                    class="w-full font-mono text-xs p-4 outline-none resize-none bg-white text-slate-700 leading-relaxed"
                                    style="min-height: 320px;"
                                    spellcheck="false"
                                    :placeholder="$t('Flow JSON will appear here...')"
                                ></textarea>
                            </div>
                        </div>

                        <!-- Phone preview (desktop: beside builder) -->
                        <Transition
                            enter-active-class="transition-all duration-200 ease-out"
                            enter-from-class="opacity-0 translate-x-4"
                            leave-active-class="transition-all duration-150 ease-in"
                            leave-to-class="opacity-0 translate-x-4"
                        >
                            <div v-if="showPreview" class="hidden lg:block w-72 shrink-0 sticky top-4">
                                <div class="bg-white border border-slate-200 rounded-xl p-4">
                                    <div class="flex items-center justify-between mb-4">
                                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-widest">{{ $t('Live Preview') }}</p>
                                        <button @click="showPreview = false" class="text-xs text-slate-400 hover:text-slate-600 transition-colors flex items-center gap-1">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                            {{ $t('Hide') }}
                                        </button>
                                    </div>
                                    <div class="flex justify-center">
                                        <FlowPhonePreview
                                            :screens="previewScreens"
                                            :active-index="activeScreenIndex"
                                        />
                                    </div>
                                </div>
                            </div>
                        </Transition>
                    </div>

                    <!-- Phone preview (mobile: below builder) -->
                    <Transition
                        enter-active-class="transition-all duration-200 ease-out"
                        enter-from-class="opacity-0 translate-y-2"
                        leave-active-class="transition-all duration-150 ease-in"
                        leave-to-class="opacity-0 translate-y-2"
                    >
                        <div v-if="showPreview" class="lg:hidden mt-6">
                            <div class="bg-white border border-slate-200 rounded-xl p-4">
                                <div class="flex items-center justify-between mb-4">
                                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-widest">{{ $t('Live Preview') }}</p>
                                    <button @click="showPreview = false" class="text-xs text-slate-400 hover:text-slate-600 transition-colors flex items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                        {{ $t('Hide') }}
                                    </button>
                                </div>
                                <div class="flex justify-center">
                                    <FlowPhonePreview
                                        :screens="previewScreens"
                                        :active-index="activeScreenIndex"
                                    />
                                </div>
                            </div>
                        </div>
                    </Transition>
                </div>
            </div>

            <!-- ── Settings Tab ─────────────────────────────────────────────── -->
            <div v-show="activeTab === 'settings'">
                <div class="max-w-3xl mx-auto space-y-5">

                    <!-- How-to + deletion warning (side by side) -->
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 text-xs text-slate-500 space-y-1.5">
                            <p class="font-semibold text-slate-600 mb-2">{{ $t('How to use') }}</p>
                            <div class="flex gap-2">
                                <span class="w-4 h-4 rounded-full bg-primary text-white text-[9px] flex items-center justify-center shrink-0 mt-0.5">1</span>
                                <span>{{ $t('Add screens and components') }}</span>
                            </div>
                            <div class="flex gap-2">
                                <span class="w-4 h-4 rounded-full bg-primary text-white text-[9px] flex items-center justify-center shrink-0 mt-0.5">2</span>
                                <span>{{ $t('Click Save to upload to Meta') }}</span>
                            </div>
                            <div class="flex gap-2">
                                <span class="w-4 h-4 rounded-full bg-primary text-white text-[9px] flex items-center justify-center shrink-0 mt-0.5">3</span>
                                <span>{{ $t('Click Publish when ready') }}</span>
                            </div>
                        </div>

                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-xs text-amber-700 space-y-1">
                            <p class="font-semibold text-amber-800">{{ $t('Deleting a flow') }}</p>
                            <p>{{ $t('Meta only allows deleting flows in Draft or Deprecated status.') }}</p>
                        </div>
                    </div>

                    <!-- Google Sheets Integration -->
                    <SettingsCollapsibleSection title="Google Sheets" :enabled="sheetEnabled" :default-open="true">
                        <template #icon>
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/></svg>
                        </template>

                        <!-- Connection Status -->
                        <div>
                            <p class="text-slate-400 text-[11px] uppercase tracking-wide font-medium mb-2">{{ $t('Google Account') }}</p>
                            <div v-if="!googleStatus.connected">
                                <a
                                    :href="`/automation/meta-flows/google/connect?flow_uuid=${flow.uuid}`"
                                    class="inline-flex items-center gap-1.5 text-primary text-xs font-medium hover:underline"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10,17 15,12 10,7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                                    {{ $t('Connect Google Account') }}
                                </a>
                            </div>
                            <div v-else class="flex items-center justify-between">
                                <div class="flex items-center gap-1.5 text-green-700">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m9 12 2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                                    <span class="truncate max-w-[110px]">{{ googleStatus.google_email }}</span>
                                </div>
                                <button
                                    @click="disconnectGoogle"
                                    class="text-red-500 hover:text-red-700 text-[11px]"
                                >{{ $t('Disconnect') }}</button>
                            </div>
                        </div>

                        <template v-if="googleStatus.connected">
                            <!-- Sheet URL -->
                            <div>
                                <label class="text-slate-400 text-[11px] uppercase tracking-wide font-medium block mb-1">{{ $t('Sheet URL') }}</label>
                                <input
                                    v-model="sheetUrl"
                                    type="url"
                                    @blur="onUrlBlur"
                                    placeholder="https://docs.google.com/spreadsheets/d/..."
                                    class="w-full border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs outline-none focus:border-primary"
                                />
                            </div>

                            <!-- Tab Name -->
                            <div>
                                <label class="text-slate-400 text-[11px] uppercase tracking-wide font-medium block mb-1">{{ $t('Tab Name') }}</label>
                                <input
                                    v-model="sheetTab"
                                    type="text"
                                    @blur="fetchHeaders"
                                    placeholder="Sheet1"
                                    class="w-full border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs outline-none focus:border-primary"
                                />
                            </div>

                            <!-- Fetch Headers Button -->
                            <button
                                v-if="sheetUrl && sheetTab && !sheetHeaders.length"
                                @click="fetchHeaders"
                                :disabled="loadingHeaders"
                                class="w-full text-center py-1.5 rounded-lg border border-primary text-primary text-xs hover:bg-primary hover:text-white disabled:opacity-50 transition-colors"
                            >
                                {{ loadingHeaders ? $t('Fetching...') : $t('Fetch Sheet Columns') }}
                            </button>

                            <!-- Field Mapping Table -->
                            <div v-if="allFlowFields.length && sheetHeaders.length">
                                <p class="text-slate-400 text-[11px] uppercase tracking-wide font-medium mb-2">{{ $t('Field Mapping') }}</p>
                                <div class="space-y-2">
                                    <div
                                        v-for="field in allFlowFields"
                                        :key="field.name"
                                        class="flex items-start gap-2"
                                    >
                                        <div class="shrink-0 w-28">
                                            <p class="text-slate-700 text-[11px] font-medium leading-tight">{{ field.label }}</p>
                                            <p v-if="field.type !== 'metadata' && field.label !== field.name" class="text-slate-400 text-[10px] leading-tight truncate" :title="field.name">{{ field.name }}</p>
                                            <span v-if="field.type === 'metadata'" class="inline-block text-[9px] px-1 py-0.5 rounded bg-slate-100 text-slate-400 font-medium mt-0.5">auto</span>
                                        </div>
                                        <select
                                            v-model="mappingFor[field.name]"
                                            class="flex-1 border border-slate-200 rounded px-1.5 py-1 text-[11px] outline-none focus:border-primary bg-white"
                                        >
                                            <option value="">— skip —</option>
                                            <option v-for="header in sheetHeaders" :key="header" :value="header">{{ header }}</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Enable Toggle + Save -->
                            <div class="flex items-center justify-between pt-1">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <div
                                        @click="sheetEnabled = !sheetEnabled"
                                        :class="['w-8 h-4 rounded-full transition-colors relative', sheetEnabled ? 'bg-green-500' : 'bg-slate-300']"
                                    >
                                        <div :class="['absolute top-0.5 w-3 h-3 rounded-full bg-white shadow transition-transform', sheetEnabled ? 'translate-x-4' : 'translate-x-0.5']"></div>
                                    </div>
                                    <span class="text-slate-600">{{ sheetEnabled ? $t('Enabled') : $t('Disabled') }}</span>
                                </label>

                                <button
                                    @click="saveSheetConfig"
                                    :disabled="savingSheet"
                                    class="px-3 py-1.5 rounded-lg bg-primary text-white text-xs hover:opacity-90 disabled:opacity-50 transition-opacity"
                                >
                                    {{ savingSheet ? $t('Saving...') : $t('Save') }}
                                </button>
                            </div>
                        </template>
                    </SettingsCollapsibleSection>

                    <!-- Reply Message Panel -->
                    <SettingsCollapsibleSection title="Reply Message" :enabled="replyEnabled">
                        <template #icon>
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        </template>

                        <div>
                            <label class="text-slate-400 text-[11px] uppercase tracking-wide font-medium block mb-1">{{ $t('Message Template') }}</label>
                            <textarea
                                v-model="replyTemplate"
                                rows="4"
                                :placeholder="replyPlaceholder"
                                class="w-full border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs outline-none focus:border-primary resize-none"
                            ></textarea>
                        </div>

                        <div class="text-[11px] text-slate-400 space-y-0.5">
                            <p class="font-medium text-slate-500">{{ $t('Available variables:') }}</p>
                            <p v-text="variableHintMeta"></p>
                            <p v-text="variableHintFields"></p>
                        </div>

                        <div class="flex items-center justify-between pt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <div
                                    @click="replyEnabled = !replyEnabled"
                                    :class="['w-8 h-4 rounded-full transition-colors relative', replyEnabled ? 'bg-green-500' : 'bg-slate-300']"
                                >
                                    <div :class="['absolute top-0.5 w-3 h-3 rounded-full bg-white shadow transition-transform', replyEnabled ? 'translate-x-4' : 'translate-x-0.5']"></div>
                                </div>
                                <span class="text-slate-600">{{ replyEnabled ? $t('Enabled') : $t('Disabled') }}</span>
                            </label>

                            <button
                                @click="saveReplyConfig"
                                :disabled="savingReply"
                                class="px-3 py-1.5 rounded-lg bg-primary text-white text-xs hover:opacity-90 disabled:opacity-50 transition-opacity"
                            >
                                {{ savingReply ? $t('Saving...') : $t('Save') }}
                            </button>
                        </div>
                    </SettingsCollapsibleSection>

                    <!-- Interactive Reply Panel -->
                    <SettingsCollapsibleSection title="Interactive Reply" :enabled="interactiveEnabled">
                        <template #icon>
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/></svg>
                        </template>

                        <div>
                            <label class="text-slate-400 text-[11px] uppercase tracking-wide font-medium block mb-1">{{ $t('Body Text') }}</label>
                            <textarea
                                v-model="interactiveBody"
                                rows="3"
                                :placeholder="$t('Thank you for your submission!')"
                                class="w-full border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs outline-none focus:border-primary resize-none"
                            ></textarea>
                        </div>

                        <div>
                            <label class="text-slate-400 text-[11px] uppercase tracking-wide font-medium block mb-1">{{ $t('Buttons') }} ({{ $t('max 3') }})</label>
                            <div class="space-y-1.5">
                                <div v-for="(btn, idx) in interactiveButtons" :key="idx" class="flex items-center gap-2">
                                    <input
                                        v-model="btn.title"
                                        type="text"
                                        maxlength="20"
                                        :placeholder="$t('Button text')"
                                        class="flex-1 border border-slate-200 rounded px-2 py-1 text-[11px] outline-none focus:border-primary"
                                    />
                                    <button
                                        v-if="interactiveButtons.length > 1"
                                        @click="interactiveButtons.splice(idx, 1)"
                                        class="text-red-400 hover:text-red-600 text-sm leading-none"
                                    >&times;</button>
                                </div>
                                <button
                                    v-if="interactiveButtons.length < 3"
                                    @click="interactiveButtons.push({ id: 'btn_' + Date.now(), title: '' })"
                                    class="text-primary text-[11px] font-medium hover:underline"
                                >+ {{ $t('Add Button') }}</button>
                            </div>
                        </div>

                        <div class="flex items-center justify-between pt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <div
                                    @click="interactiveEnabled = !interactiveEnabled"
                                    :class="['w-8 h-4 rounded-full transition-colors relative', interactiveEnabled ? 'bg-green-500' : 'bg-slate-300']"
                                >
                                    <div :class="['absolute top-0.5 w-3 h-3 rounded-full bg-white shadow transition-transform', interactiveEnabled ? 'translate-x-4' : 'translate-x-0.5']"></div>
                                </div>
                                <span class="text-slate-600">{{ interactiveEnabled ? $t('Enabled') : $t('Disabled') }}</span>
                            </label>

                            <button
                                @click="saveInteractiveConfig"
                                :disabled="savingInteractive"
                                class="px-3 py-1.5 rounded-lg bg-primary text-white text-xs hover:opacity-90 disabled:opacity-50 transition-opacity"
                            >
                                {{ savingInteractive ? $t('Saving...') : $t('Save') }}
                            </button>
                        </div>
                    </SettingsCollapsibleSection>

                    <!-- Email Notification Panel -->
                    <SettingsCollapsibleSection title="Email Notification" :enabled="emailEnabled">
                        <template #icon>
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                        </template>

                        <div>
                            <label class="text-slate-400 text-[11px] uppercase tracking-wide font-medium block mb-1">{{ $t('Recipient Emails') }}</label>
                            <div class="space-y-1.5">
                                <div v-for="(email, idx) in emailRecipients" :key="idx" class="flex items-center gap-2">
                                    <input
                                        v-model="emailRecipients[idx]"
                                        type="email"
                                        placeholder="recipient@example.com"
                                        class="flex-1 border border-slate-200 rounded px-2 py-1 text-[11px] outline-none focus:border-primary"
                                    />
                                    <button
                                        v-if="emailRecipients.length > 1"
                                        @click="emailRecipients.splice(idx, 1)"
                                        class="text-red-400 hover:text-red-600 text-sm leading-none"
                                    >&times;</button>
                                </div>
                                <button
                                    @click="emailRecipients.push('')"
                                    class="text-primary text-[11px] font-medium hover:underline"
                                >+ {{ $t('Add Email') }}</button>
                            </div>
                        </div>

                        <div>
                            <label class="text-slate-400 text-[11px] uppercase tracking-wide font-medium block mb-1">{{ $t('Subject Template') }}</label>
                            <input
                                v-model="emailSubject"
                                type="text"
                                :placeholder="emailSubjectPlaceholder"
                                class="w-full border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs outline-none focus:border-primary"
                            />
                        </div>

                        <div>
                            <label class="text-slate-400 text-[11px] uppercase tracking-wide font-medium block mb-1">{{ $t('Email Body Template') }}</label>
                            <textarea
                                v-model="emailBody"
                                rows="4"
                                :placeholder="emailBodyPlaceholder"
                                class="w-full border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs outline-none focus:border-primary resize-none"
                            ></textarea>
                            <p class="text-[11px] text-slate-400 mt-1">{{ $t('Leave empty for auto-generated list of submitted fields.') }}</p>
                        </div>

                        <div class="flex items-center justify-between pt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <div
                                    @click="emailEnabled = !emailEnabled"
                                    :class="['w-8 h-4 rounded-full transition-colors relative', emailEnabled ? 'bg-green-500' : 'bg-slate-300']"
                                >
                                    <div :class="['absolute top-0.5 w-3 h-3 rounded-full bg-white shadow transition-transform', emailEnabled ? 'translate-x-4' : 'translate-x-0.5']"></div>
                                </div>
                                <span class="text-slate-600">{{ emailEnabled ? $t('Enabled') : $t('Disabled') }}</span>
                            </label>

                            <button
                                @click="saveEmailConfig"
                                :disabled="savingEmail"
                                class="px-3 py-1.5 rounded-lg bg-primary text-white text-xs hover:opacity-90 disabled:opacity-50 transition-opacity"
                            >
                                {{ savingEmail ? $t('Saving...') : $t('Save') }}
                            </button>
                        </div>
                    </SettingsCollapsibleSection>

                </div>
            </div>

        </div><!-- end page wrapper -->
    </AppLayout>
</template>

<script setup>
    import AppLayout       from '@/Pages/User/Layout/App.vue';
    import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
    import axios           from 'axios';
    import MetaFlowBuilder from '@modules/MetaFlows/Pages/User/Components/MetaFlowBuilder.vue';
    import FlowPhonePreview from '@modules/MetaFlows/Pages/User/Components/FlowPhonePreview.vue';
    import SettingsCollapsibleSection from '@modules/MetaFlows/Pages/User/Components/SettingsCollapsibleSection.vue';

    const props = defineProps(['flow']);

    const currentStatus     = ref(props.flow.status);
    const activeTab         = ref('builder');
    const showAdvanced      = ref(false);
    const activeScreenIndex = ref(0);
    const showPreview       = ref(true);
    const moreMenuOpen      = ref(false);
    const moreMenuRef       = ref(null);

    // ── Close dropdown on click outside ──────────────────────────────────────
    const onClickOutside = (e) => {
        if (moreMenuRef.value && !moreMenuRef.value.contains(e.target)) {
            moreMenuOpen.value = false;
        }
    };
    onMounted(() => document.addEventListener('click', onClickOutside));
    onBeforeUnmount(() => document.removeEventListener('click', onClickOutside));

    // ── Google Sheets state ───────────────────────────────────────────────────
    const googleStatus     = ref({ connected: false, google_email: null });
    const sheetUrl         = ref(props.flow.google_sheet_url ?? '');
    const sheetTab         = ref(props.flow.google_sheet_tab ?? 'Sheet1');
    const sheetMapping     = ref(props.flow.google_sheet_mapping ?? []);
    const sheetEnabled     = ref(props.flow.google_sheet_enabled ?? false);
    const sheetHeaders     = ref([]);
    const flowFields       = ref([]);
    const savingSheet      = ref(false);
    const loadingHeaders   = ref(false);
    const mappingFor       = ref({});

    // ── Reply Message state ──────────────────────────────────────────────────
    const replyEnabled      = ref(props.flow.reply_message_enabled ?? false);
    const replyTemplate     = ref(props.flow.reply_message_template ?? '');
    const savingReply       = ref(false);

    // ── Interactive Reply state ────────────────────────────────────────────────
    const interactiveEnabled     = ref(props.flow.interactive_reply_enabled ?? false);
    const interactiveBody        = ref(props.flow.interactive_reply_config?.body ?? '');
    const interactiveButtons     = ref(
        props.flow.interactive_reply_config?.buttons ?? [{ id: 'btn_1', title: '' }]
    );
    const savingInteractive      = ref(false);

    // ── Email Notification state ───────────────────────────────────────────────
    const emailEnabled       = ref(props.flow.email_notification_enabled ?? false);
    const emailRecipients    = ref(
        props.flow.email_notification_config?.recipients ?? ['']
    );
    const emailSubject       = ref(props.flow.email_notification_config?.subject_template ?? '');
    const emailBody          = ref(props.flow.email_notification_config?.body_template ?? '');
    const savingEmail        = ref(false);

    // ── Variable hint strings for template UI ──────────────────────────────────
    const variableHintMeta = 'Metadata: {{__timestamp}}, {{__contact_phone}}, {{__contact_name}}, {{__contact_email}}, {{__flow_token}}, {{flow_name}}';
    const variableHintFields = computed(() => {
        if (!flowFields.value.length) return 'Form fields will appear here after saving the flow.';
        const names = flowFields.value.slice(0, 4).map(f => '{{' + f.name + '}}').join(', ');
        return 'Form fields: ' + names + (flowFields.value.length > 4 ? ', ...' : '');
    });
    const replyPlaceholder = 'Thank you {{__contact_name}} for your submission!';
    const emailBodyPlaceholder = 'A new submission was received from {{__contact_name}}...';
    const emailSubjectPlaceholder = 'New submission: {{flow_name}}';

    // All fields: metadata fields first, then flow form fields
    const allFlowFields = computed(() => {
        const meta = [
            { name: '__timestamp',     label: 'Timestamp',      type: 'metadata', screen: '' },
            { name: '__contact_phone', label: 'Contact Phone',  type: 'metadata', screen: '' },
            { name: '__contact_name',  label: 'Contact Name',   type: 'metadata', screen: '' },
            { name: '__contact_email', label: 'Contact Email',  type: 'metadata', screen: '' },
            { name: '__flow_token',    label: 'Flow Token',     type: 'metadata', screen: '' },
        ];
        return [...meta, ...flowFields.value];
    });

    const assetContent = ref(
        props.flow.asset_content
            ? tryPrettyPrint(props.flow.asset_content)
            : JSON.stringify({ version: '6.3', screens: [] }, null, 2)
    );

    const saving  = ref(false);
    const acting  = ref(false);
    const syncing = ref(false);

    const feedbackMessage = ref(null);
    const feedbackIsError = ref(false);

    // ── Status badge helpers ──────────────────────────────────────────────────
    const statusColor = (status) => {
        const map = {
            published:  'bg-green-100 text-green-700',
            draft:      'bg-slate-100 text-slate-500',
            deprecated: 'bg-orange-100 text-orange-600',
            blocked:    'bg-red-100 text-red-600',
            throttled:  'bg-yellow-100 text-yellow-700',
        };
        return map[status] ?? 'bg-slate-100 text-slate-500';
    };

    const statusDot = (status) => {
        const map = {
            published: 'bg-green-500', draft: 'bg-slate-400',
            deprecated: 'bg-orange-500', blocked: 'bg-red-500', throttled: 'bg-yellow-500',
        };
        return map[status] ?? 'bg-slate-400';
    };

    // ── Parse assetContent → preview-compatible screens ───────────────────────
    const mapPreviewComp = (comp) => {
        switch (comp.type) {
            case 'TextHeading':
            case 'TextSubheading':
            case 'TextBody':
                return { type: comp.type, text: comp.text || '' };
            case 'TextInput':
                return { type: 'TextInput', label: comp.label || '', name: comp.name || '', required: !!comp.required, inputType: comp['input-type'] || 'text' };
            case 'TextArea':
                return { type: 'TextArea', label: comp.label || '', name: comp.name || '', required: !!comp.required };
            case 'Dropdown':
            case 'CheckboxGroup':
            case 'RadioButtonsGroup':
                return { type: comp.type, label: comp.label || '', name: comp.name || '', required: !!comp.required, options: (comp['data-source'] || []).map(o => ({ id: o.id || '', title: o.title || '' })) };
            case 'Footer': {
                const ca = comp['on-click-action'] ?? {};
                return { type: 'Footer', label: comp.label || 'Continue', action: ca.name || 'complete', nextScreen: ca.next?.name || ca.next?.screen || '' };
            }
            default: return null;
        }
    };

    const flattenForPreview = (layoutChildren) => {
        const result = [];
        for (const child of (layoutChildren || [])) {
            if (child.type === 'Form') {
                for (const fc of (child.children || [])) result.push(mapPreviewComp(fc));
            } else {
                result.push(mapPreviewComp(child));
            }
        }
        return result.filter(Boolean);
    };

    const parseScreensForPreview = (jsonStr) => {
        try {
            const json = typeof jsonStr === 'string' ? JSON.parse(jsonStr) : jsonStr;
            return (json.screens || []).map(screen => ({
                id:         screen.id || 'SCREEN',
                title:      screen.title || '',
                terminal:   !!screen.terminal,
                components: flattenForPreview(screen.layout?.children),
            }));
        } catch {
            return [];
        }
    };

    const previewScreens = computed(() => parseScreensForPreview(assetContent.value));

    // ── Helpers ───────────────────────────────────────────────────────────────
    function tryPrettyPrint(content) {
        try { return JSON.stringify(JSON.parse(content), null, 2); }
        catch { return content; }
    }

    const formatJson = () => {
        try {
            assetContent.value = JSON.stringify(JSON.parse(assetContent.value), null, 2);
            showFeedback('Formatted.', false);
        } catch {
            showFeedback('Invalid JSON — cannot format.', true);
        }
    };

    // ── API actions ───────────────────────────────────────────────────────────
    const handleSave = async () => {
        saving.value = true;
        clearFeedback();
        try {
            await axios.put(`/automation/meta-flows/${props.flow.uuid}`, { asset_content: assetContent.value });
            showFeedback('Flow saved successfully.', false);
        } catch (err) {
            showFeedback(err.response?.data?.message ?? 'Failed to save flow.', true);
        } finally {
            saving.value = false;
        }
    };

    const handlePublish = async () => {
        acting.value = true;
        clearFeedback();
        try {
            const res = await axios.post(`/automation/meta-flows/${props.flow.uuid}/publish`);
            currentStatus.value = res.data.status ?? 'published';
            showFeedback('Flow published successfully!', false);
        } catch (err) {
            showFeedback(err.response?.data?.message ?? 'Failed to publish flow.', true);
        } finally {
            acting.value = false;
        }
    };

    const handleDeprecate = async () => {
        acting.value = true;
        clearFeedback();
        try {
            const res = await axios.post(`/automation/meta-flows/${props.flow.uuid}/deprecate`);
            currentStatus.value = res.data.status ?? 'deprecated';
            showFeedback('Flow deprecated.', false);
        } catch (err) {
            showFeedback(err.response?.data?.message ?? 'Failed to deprecate flow.', true);
        } finally {
            acting.value = false;
        }
    };

    const handleSync = async () => {
        syncing.value = true;
        clearFeedback();
        try {
            const res     = await axios.post(`/automation/meta-flows/${props.flow.uuid}/sync`);
            const updated = res.data.flow;
            currentStatus.value = updated.status ?? currentStatus.value;
            if (updated.asset_content) {
                assetContent.value = tryPrettyPrint(updated.asset_content);
            }
            showFeedback('Synced from Meta.', false);
        } catch (err) {
            showFeedback(err.response?.data?.message ?? 'Failed to sync from Meta.', true);
        } finally {
            syncing.value = false;
        }
    };

    const showFeedback  = (msg, isErr) => { feedbackMessage.value = msg; feedbackIsError.value = isErr; };
    const clearFeedback = () => { feedbackMessage.value = null; feedbackIsError.value = false; };

    // ── Google Sheets methods ─────────────────────────────────────────────────
    onMounted(async () => {
        try {
            const res = await axios.get('/automation/meta-flows/google/status');
            googleStatus.value = res.data;
        } catch {
            // Non-critical — ignore errors
        }

        try {
            const fieldsRes = await axios.get(`/automation/meta-flows/${props.flow.uuid}/flow-fields`);
            flowFields.value = fieldsRes.data.fields ?? [];
        } catch {
            // Non-critical
        }

        // Restore mapping from saved config
        if (sheetMapping.value?.length) {
            sheetMapping.value.forEach(m => {
                if (m.flow_field) mappingFor.value[m.flow_field] = m.sheet_column ?? '';
            });
        }
    });

    const onUrlBlur = () => {
        if (sheetUrl.value && sheetTab.value) fetchHeaders();
    };

    const fetchHeaders = async () => {
        if (!sheetUrl.value || !sheetTab.value) return;

        loadingHeaders.value = true;
        try {
            const res = await axios.get(`/automation/meta-flows/${props.flow.uuid}/sheet-headers`, {
                params: {
                    google_sheet_url: sheetUrl.value,
                    google_sheet_tab: sheetTab.value,
                },
            });
            sheetHeaders.value = res.data.headers ?? [];

            allFlowFields.value.forEach(field => {
                if (!mappingFor.value[field.name]) {
                    const match = sheetHeaders.value.find(
                        h => h.toLowerCase() === field.label.toLowerCase()
                    );
                    if (match) mappingFor.value[field.name] = match;
                }
            });
        } catch (err) {
            showFeedback(err.response?.data?.message ?? 'Failed to fetch sheet headers.', true);
        } finally {
            loadingHeaders.value = false;
        }
    };

    const disconnectGoogle = async () => {
        try {
            await axios.delete('/automation/meta-flows/google/disconnect');
            googleStatus.value = { connected: false, google_email: null };
            sheetHeaders.value = [];
        } catch (err) {
            showFeedback(err.response?.data?.message ?? 'Failed to disconnect.', true);
        }
    };

    const saveSheetConfig = async (silent = false) => {
        savingSheet.value = true;

        const mapping = allFlowFields.value
            .filter(f => mappingFor.value[f.name])
            .map(f => ({
                flow_field:   f.name,
                sheet_column: mappingFor.value[f.name],
            }));

        try {
            await axios.put(`/automation/meta-flows/${props.flow.uuid}/sheet-config`, {
                google_sheet_url:     sheetUrl.value,
                google_sheet_tab:     sheetTab.value,
                google_sheet_mapping: mapping,
                google_sheet_enabled: sheetEnabled.value,
            });
            sheetMapping.value = mapping;
            if (!silent) showFeedback('Google Sheets configuration saved.', false);
        } catch (err) {
            if (!silent) showFeedback(err.response?.data?.message ?? 'Failed to save sheet config.', true);
            else throw err;
        } finally {
            savingSheet.value = false;
        }
    };

    // ── Reply Message save ──────────────────────────────────────────────────
    const saveReplyConfig = async (silent = false) => {
        savingReply.value = true;
        try {
            await axios.put(`/automation/meta-flows/${props.flow.uuid}/submission-settings`, {
                reply_message_enabled: replyEnabled.value,
                reply_message_template: replyTemplate.value,
            });
            if (!silent) showFeedback('Reply message settings saved.', false);
        } catch (err) {
            if (!silent) showFeedback(err.response?.data?.message ?? 'Failed to save reply settings.', true);
            else throw err;
        } finally {
            savingReply.value = false;
        }
    };

    // ── Interactive Reply save ────────────────────────────────────────────────
    const saveInteractiveConfig = async (silent = false) => {
        savingInteractive.value = true;
        try {
            await axios.put(`/automation/meta-flows/${props.flow.uuid}/submission-settings`, {
                interactive_reply_enabled: interactiveEnabled.value,
                interactive_reply_config: {
                    body: interactiveBody.value,
                    buttons: interactiveButtons.value.filter(b => b.title.trim()),
                },
            });
            if (!silent) showFeedback('Interactive reply settings saved.', false);
        } catch (err) {
            if (!silent) showFeedback(err.response?.data?.message ?? 'Failed to save interactive settings.', true);
            else throw err;
        } finally {
            savingInteractive.value = false;
        }
    };

    // ── Email Notification save ───────────────────────────────────────────────
    const saveEmailConfig = async (silent = false) => {
        savingEmail.value = true;
        try {
            await axios.put(`/automation/meta-flows/${props.flow.uuid}/submission-settings`, {
                email_notification_enabled: emailEnabled.value,
                email_notification_config: {
                    recipients: emailRecipients.value.filter(r => r.trim()),
                    subject_template: emailSubject.value,
                    body_template: emailBody.value,
                },
            });
            if (!silent) showFeedback('Email notification settings saved.', false);
        } catch (err) {
            if (!silent) showFeedback(err.response?.data?.message ?? 'Failed to save email settings.', true);
            else throw err;
        } finally {
            savingEmail.value = false;
        }
    };
</script>