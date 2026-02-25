<template>
    <AppLayout>

        <div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] overflow-y-scroll">

        <!-- ── Page header ─────────────────────────────────────────────────── -->
        <div class="flex items-center justify-between mb-6 mt-8 md:mt-0 flex-wrap gap-3">
            <!-- Left: breadcrumb + flow name -->
            <div class="flex items-center gap-3">
                <a href="/automation/meta-flows"
                   class="w-8 h-8 flex items-center justify-center rounded-lg border border-slate-200 text-slate-400 hover:text-slate-700 hover:border-slate-300 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                </a>
                <div>
                    <div class="flex items-center gap-2">
                        <h4 class="text-[15px] font-semibold text-slate-800">{{ flow.name }}</h4>
                        <span :class="['inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded-full capitalize', statusColor(currentStatus)]">
                            <span :class="['w-1.5 h-1.5 rounded-full', statusDot(currentStatus)]"></span>
                            {{ currentStatus }}
                        </span>
                    </div>
                    <p class="text-xs text-slate-400 mt-0.5">{{ $t('Meta Flow ID') }}: {{ flow.meta_flow_id ?? '—' }}</p>
                </div>
            </div>

            <!-- Right: action buttons -->
            <div class="flex items-center gap-2 flex-wrap">
                <button
                    @click="handleSync"
                    :disabled="syncing"
                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50 transition-colors flex items-center gap-1.5"
                >
                    <svg v-if="syncing" class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".4"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
                    {{ syncing ? $t('Syncing...') : $t('Sync') }}
                </button>

                <a
                    v-if="flow.preview_url"
                    :href="flow.preview_url"
                    target="_blank"
                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50 transition-colors flex items-center gap-1.5"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15,3 21,3 21,9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    {{ $t('Preview') }}
                </a>

                <a
                    :href="'/automation/meta-flows/' + flow.uuid + '/responses'"
                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50 transition-colors flex items-center gap-1.5"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    {{ flow.responses_count ?? 0 }} {{ $t('Responses') }}
                </a>

                <button
                    v-if="currentStatus === 'published'"
                    @click="handleDeprecate"
                    :disabled="acting"
                    class="rounded-lg border border-orange-200 px-3 py-1.5 text-sm text-orange-600 hover:bg-orange-50 disabled:opacity-50 transition-colors"
                >
                    {{ $t('Deprecate') }}
                </button>

                <button
                    v-if="currentStatus === 'draft'"
                    @click="handlePublish"
                    :disabled="acting"
                    class="rounded-lg border border-green-200 bg-green-50 px-3 py-1.5 text-sm text-green-700 hover:bg-green-100 disabled:opacity-50 transition-colors flex items-center gap-1.5"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m9 12 2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                    {{ acting ? $t('Publishing...') : $t('Publish') }}
                </button>

                <button
                    @click="handleSave"
                    :disabled="saving"
                    class="rounded-lg bg-primary px-4 py-1.5 text-sm text-white shadow-sm hover:opacity-90 disabled:opacity-50 transition-opacity flex items-center gap-1.5"
                >
                    <svg v-if="saving" class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".4"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17,21 17,13 7,13 7,21"/><polyline points="7,3 7,8 15,8"/></svg>
                    {{ saving ? $t('Saving...') : $t('Save') }}
                </button>
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
            <button @click="feedbackMessage = null" class="ms-auto text-current opacity-60 hover:opacity-100">×</button>
        </div>

        <!-- ── Three-column layout ──────────────────────────────────────────── -->
        <div class="flex gap-5 items-start">

            <!-- Left sidebar: metadata + tips -->
            <div class="w-52 shrink-0 space-y-4 sticky top-4">
                <div class="bg-white border border-slate-200 rounded-xl p-4 text-sm space-y-4">
                    <div v-if="flow.categories?.length">
                        <p class="text-slate-400 text-[11px] uppercase tracking-wide font-medium mb-1">{{ $t('Categories') }}</p>
                        <div class="flex flex-wrap gap-1">
                            <span v-for="cat in flow.categories" :key="cat"
                                  class="text-xs px-2 py-0.5 rounded-md bg-slate-100 text-slate-600 font-medium">
                                {{ cat.replace(/_/g, ' ') }}
                            </span>
                        </div>
                    </div>
                    <div>
                        <p class="text-slate-400 text-[11px] uppercase tracking-wide font-medium mb-1">{{ $t('Responses') }}</p>
                        <p class="text-2xl font-bold text-slate-800">{{ flow.responses_count ?? 0 }}</p>
                    </div>
                    <div>
                        <p class="text-slate-400 text-[11px] uppercase tracking-wide font-medium mb-1">{{ $t('Last updated') }}</p>
                        <p class="text-slate-600 text-xs">{{ flow.updated_at }}</p>
                    </div>
                </div>

                <!-- How-to -->
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

                <!-- Deletion note -->
                <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-xs text-amber-700 space-y-1">
                    <p class="font-semibold text-amber-800">{{ $t('Deleting a flow') }}</p>
                    <p>{{ $t('Meta only allows deleting flows in Draft or Deprecated status.') }}</p>
                </div>

                <!-- Google Sheets Integration -->
                <div class="border border-slate-200 rounded-xl overflow-hidden">
                    <button
                        @click="openSheetSection"
                        class="w-full flex items-center justify-between px-4 py-3 bg-white hover:bg-slate-50 text-xs text-slate-600 transition-colors"
                    >
                        <div class="flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/></svg>
                            <span class="font-medium text-slate-700">{{ $t('Google Sheets') }}</span>
                            <span v-if="sheetEnabled" class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" :class="showSheetSection ? 'rotate-180' : ''" class="transition-transform"><path d="m6 9 6 6 6-6"/></svg>
                    </button>

                    <div v-if="showSheetSection" class="border-t border-slate-100 p-4 space-y-4 text-xs">

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
                                <div class="space-y-1.5">
                                    <div
                                        v-for="field in allFlowFields"
                                        :key="field.name"
                                        class="flex items-center gap-2"
                                    >
                                        <span class="shrink-0 px-2 py-0.5 bg-slate-100 rounded text-slate-600 text-[11px] font-medium w-24 truncate" :title="field.label">
                                            {{ field.label }}
                                        </span>
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

                    </div>
                </div>
            </div>

            <!-- Center: builder + JSON panel -->
            <div class="flex-1 min-w-0 space-y-4">
                <div class="bg-white border border-slate-200 rounded-xl p-5">
                    <MetaFlowBuilder
                        v-model="assetContent"
                        @screenChange="activeScreenIndex = $event"
                    />
                </div>

                <!-- Collapsible raw JSON -->
                <div class="border border-slate-200 rounded-xl overflow-hidden">
                    <button
                        @click="showJson = !showJson"
                        class="w-full flex items-center justify-between px-4 py-2.5 bg-slate-50 border-b border-slate-100 text-xs text-slate-500 hover:bg-slate-100 transition-colors"
                    >
                        <span class="font-mono font-medium">flow.json</span>
                        <div class="flex items-center gap-2">
                            <span v-if="showJson" class="text-primary text-[11px]" @click.stop="formatJson">{{ $t('Format') }}</span>
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" :class="showJson ? 'rotate-180' : ''" class="transition-transform"><path d="m6 9 6 6 6-6"/></svg>
                        </div>
                    </button>
                    <div v-if="showJson">
                        <textarea
                            v-model="assetContent"
                            class="w-full font-mono text-xs p-4 outline-none resize-none bg-white text-slate-700 leading-relaxed"
                            style="min-height: 320px;"
                            spellcheck="false"
                            :placeholder="$t('Flow JSON will appear here...')"
                        ></textarea>
                    </div>
                </div>
            </div>

            <!-- Right: live phone preview (hidden on small screens) -->
            <div class="hidden xl:block w-72 shrink-0 sticky top-4">
                <div class="bg-white border border-slate-200 rounded-xl p-4">
                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-widest text-center mb-4">{{ $t('Live Preview') }}</p>
                    <div class="flex justify-center">
                        <FlowPhonePreview
                            :screens="previewScreens"
                            :active-index="activeScreenIndex"
                        />
                    </div>
                </div>
            </div>

        </div>

        </div><!-- end page wrapper -->
    </AppLayout>
</template>

<script setup>
    import AppLayout       from '@/Pages/User/Layout/App.vue';
    import { ref, computed, onMounted } from 'vue';
    import axios           from 'axios';
    import MetaFlowBuilder from '@modules/MetaFlows/Pages/User/Components/MetaFlowBuilder.vue';
    import FlowPhonePreview from '@modules/MetaFlows/Pages/User/Components/FlowPhonePreview.vue';

    const props = defineProps(['flow']);

    const currentStatus     = ref(props.flow.status);
    const showJson          = ref(false);
    const activeScreenIndex = ref(0);

    // ── Google Sheets state ───────────────────────────────────────────────────
    const googleStatus     = ref({ connected: false, google_email: null });
    const sheetUrl         = ref(props.flow.google_sheet_url ?? '');
    const sheetTab         = ref(props.flow.google_sheet_tab ?? 'Sheet1');
    const sheetMapping     = ref(props.flow.google_sheet_mapping ?? []);
    const sheetEnabled     = ref(props.flow.google_sheet_enabled ?? false);
    const sheetHeaders     = ref([]);
    const flowFields       = ref([]);
    const showSheetSection = ref(false);
    const savingSheet      = ref(false);
    const loadingHeaders   = ref(false);
    // Keyed by field name, value = chosen sheet column (or '' to skip)
    const mappingFor       = ref({});

    // All fields: metadata fields first, then flow form fields
    const allFlowFields = computed(() => {
        const meta = [
            { name: '__timestamp',     label: 'Timestamp',     type: 'metadata' },
            { name: '__contact_phone', label: 'Contact Phone', type: 'metadata' },
            { name: '__contact_name',  label: 'Contact Name',  type: 'metadata' },
            { name: '__flow_token',    label: 'Flow Token',    type: 'metadata' },
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
    // WhatsApp Flow JSON wraps inputs/choices/footer inside a Form element.
    // mapPreviewComp handles individual components; flattenForPreview unwraps Form.
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
                return { type: 'Footer', label: comp.label || 'Continue', action: ca.name || 'complete', nextScreen: ca.next?.screen || ca.next?.name || '' };
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

    // Load Google connection status on mount
    onMounted(async () => {
        try {
            const res = await axios.get('/automation/meta-flows/google/status');
            googleStatus.value = res.data;
        } catch {
            // Non-critical — ignore errors
        }

        // Restore mapping from saved config
        if (sheetMapping.value?.length) {
            sheetMapping.value.forEach(m => {
                if (m.flow_field) mappingFor.value[m.flow_field] = m.sheet_column ?? '';
            });
        }
    });

    // Open the Google Sheets panel and load flow fields once
    const openSheetSection = async () => {
        showSheetSection.value = !showSheetSection.value;

        if (showSheetSection.value && !flowFields.value.length) {
            try {
                const res = await axios.get(`/automation/meta-flows/${props.flow.uuid}/flow-fields`);
                flowFields.value = res.data.fields ?? [];
                // Auto-initialize mappingFor for any new fields
                allFlowFields.value.forEach(f => {
                    if (!(f.name in mappingFor.value)) mappingFor.value[f.name] = '';
                });
            } catch {
                // Ignore
            }
        }
    };

    // Save URL on blur, then try to fetch headers if tab is also filled
    const onUrlBlur = () => {
        if (sheetUrl.value && sheetTab.value) fetchHeaders();
    };

    // Fetch column headers from the linked Google Sheet tab
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

            // Auto-map fields where label matches a column header (case-insensitive)
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

    // Disconnect the linked Google account
    const disconnectGoogle = async () => {
        try {
            await axios.delete('/automation/meta-flows/google/disconnect');
            googleStatus.value = { connected: false, google_email: null };
            sheetHeaders.value = [];
        } catch (err) {
            showFeedback(err.response?.data?.message ?? 'Failed to disconnect.', true);
        }
    };

    // Build the mapping array and save the sheet configuration
    const saveSheetConfig = async () => {
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
            showFeedback('Google Sheets configuration saved.', false);
        } catch (err) {
            showFeedback(err.response?.data?.message ?? 'Failed to save sheet config.', true);
        } finally {
            savingSheet.value = false;
        }
    };
</script>
