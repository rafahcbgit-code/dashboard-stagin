<template>
    <div class="flex flex-col gap-0">

        <!-- ── Screen Tab Bar ───────────────────────────────────────────── -->
        <div class="flex items-center gap-1 overflow-x-auto pb-2 scrollbar-none border-b border-slate-200 mb-4">
            <button
                v-for="(screen, i) in screens"
                :key="i"
                @click="setActiveScreen(i)"
                :class="[
                    'shrink-0 px-3 py-1.5 rounded-md text-xs font-medium transition-colors whitespace-nowrap',
                    activeScreenIndex === i
                        ? 'bg-primary text-white shadow-sm'
                        : 'bg-slate-100 text-slate-500 hover:bg-slate-200',
                ]"
            >
                {{ screen.id || `Screen ${i + 1}` }}
            </button>

            <!-- Add screen tab -->
            <button
                @click="addScreen"
                class="shrink-0 px-3 py-1.5 rounded-md text-xs font-medium border border-dashed border-slate-300 text-slate-400 hover:border-primary hover:text-primary transition-colors whitespace-nowrap"
            >
                + {{ $t('Add Screen') }}
            </button>
        </div>

        <!-- ── Empty State (no screens) ────────────────────────────────── -->
        <div v-if="screens.length === 0" class="text-center py-16 border-2 border-dashed border-slate-200 rounded-xl">
            <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center mx-auto mb-3">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6M9 13h4"/></svg>
            </div>
            <p class="text-sm text-slate-500 mb-1">{{ $t('No screens yet') }}</p>
            <p class="text-xs text-slate-400 mb-4">{{ $t('Add your first screen to start building the flow.') }}</p>
            <button @click="addScreen" class="rounded-md bg-primary px-4 py-2 text-sm text-white hover:opacity-90 transition-opacity">
                + {{ $t('Add Screen') }}
            </button>
        </div>

        <!-- ── Active Screen Editor ─────────────────────────────────────── -->
        <div v-else-if="activeScreen" class="space-y-4">

            <!-- Screen settings -->
            <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
                <div class="flex items-center justify-between px-4 py-2.5 bg-slate-50 border-b border-slate-200">
                    <span class="text-xs font-semibold text-slate-600 uppercase tracking-wide">{{ $t('Screen Settings') }}</span>
                    <button
                        @click="removeScreen(activeScreenIndex)"
                        class="text-xs text-red-400 hover:text-red-600 border border-red-200 hover:border-red-400 rounded px-2 py-0.5 transition-colors"
                    >
                        {{ $t('Remove screen') }}
                    </button>
                </div>
                <div class="p-4 grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">
                            {{ $t('Screen ID') }}
                            <span class="text-slate-400 font-normal">(UPPERCASE)</span>
                        </label>
                        <input
                            v-model="activeScreen.id"
                            type="text"
                            placeholder="WELCOME"
                            class="w-full text-sm border border-slate-200 rounded-md px-3 py-1.5 font-mono focus:outline-none focus:ring-1 focus:ring-primary"
                            @input="activeScreen.id = activeScreen.id.toUpperCase().replace(/\s+/g, '_')"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">{{ $t('Screen title') }}</label>
                        <input
                            v-model="activeScreen.title"
                            type="text"
                            placeholder="e.g. Welcome"
                            class="w-full text-sm border border-slate-200 rounded-md px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-primary"
                        />
                    </div>
                    <div class="col-span-2">
                        <label class="flex items-center gap-2 text-xs text-slate-500 cursor-pointer select-none">
                            <input type="checkbox" v-model="activeScreen.terminal" class="rounded accent-primary" />
                            <span>{{ $t('Terminal screen') }}</span>
                            <span class="text-slate-400">({{ $t('marks the end of the flow') }})</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Components list -->
            <div class="space-y-2">
                <div
                    v-for="(comp, compIndex) in activeScreen.components"
                    :key="compIndex"
                    :class="['border rounded-xl overflow-hidden transition-shadow hover:shadow-sm', componentBorder(comp.type)]"
                >
                    <!-- Component header -->
                    <div :class="['flex items-center gap-2 px-3 py-2', componentHeaderBg(comp.type)]">
                        <!-- Type icon + label -->
                        <span :class="['inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-md border', componentBadge(comp.type)]">
                            <span v-html="componentIcon(comp.type)" class="w-3 h-3 flex items-center justify-center"></span>
                            {{ labelForType(comp.type) }}
                        </span>

                        <!-- Quick preview of content -->
                        <span class="text-xs text-slate-400 truncate flex-1 ms-1">
                            {{ compPreview(comp) }}
                        </span>

                        <!-- Controls -->
                        <div class="flex items-center gap-0.5 ms-auto shrink-0">
                            <button
                                v-if="compIndex > 0"
                                @click="moveComponent(activeScreenIndex, compIndex, -1)"
                                class="w-6 h-6 flex items-center justify-center text-slate-400 hover:text-slate-700 hover:bg-white rounded transition-colors text-sm"
                                title="Move up"
                            >↑</button>
                            <button
                                v-if="compIndex < activeScreen.components.length - 1"
                                @click="moveComponent(activeScreenIndex, compIndex, 1)"
                                class="w-6 h-6 flex items-center justify-center text-slate-400 hover:text-slate-700 hover:bg-white rounded transition-colors text-sm"
                                title="Move down"
                            >↓</button>
                            <button
                                @click="removeComponent(activeScreenIndex, compIndex)"
                                class="w-6 h-6 flex items-center justify-center text-red-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors text-base leading-none"
                                title="Remove"
                            >×</button>
                        </div>
                    </div>

                    <!-- Component fields -->
                    <div class="px-3 py-3 bg-white">

                        <!-- Text types -->
                        <div v-if="['TextHeading','TextSubheading','TextBody'].includes(comp.type)">
                            <input
                                v-model="comp.text"
                                type="text"
                                :placeholder="comp.type === 'TextHeading' ? 'Enter heading...' : comp.type === 'TextSubheading' ? 'Enter subheading...' : 'Enter body text...'"
                                class="w-full text-sm border border-slate-200 rounded-md px-3 py-2 focus:outline-none focus:ring-1 focus:ring-primary bg-slate-50"
                            />
                        </div>

                        <!-- TextInput / TextArea -->
                        <div v-else-if="['TextInput','TextArea'].includes(comp.type)">
                            <div class="grid grid-cols-2 gap-2 mb-2">
                                <div>
                                    <label class="text-xs text-slate-400 block mb-1">{{ $t('Label') }}</label>
                                    <input
                                        v-model="comp.label"
                                        type="text"
                                        placeholder="e.g. First Name"
                                        class="w-full text-sm border border-slate-200 rounded-md px-2.5 py-1.5 focus:outline-none focus:ring-1 focus:ring-primary"
                                    />
                                </div>
                                <div>
                                    <label class="text-xs text-slate-400 block mb-1">
                                        {{ $t('Field name') }}
                                        <span class="text-slate-300">(snake_case)</span>
                                    </label>
                                    <input
                                        v-model="comp.name"
                                        type="text"
                                        placeholder="first_name"
                                        class="w-full text-sm border border-slate-200 rounded-md px-2.5 py-1.5 font-mono focus:outline-none focus:ring-1 focus:ring-primary"
                                    />
                                </div>
                            </div>
                            <div class="flex items-center gap-4 flex-wrap">
                                <div v-if="comp.type === 'TextInput'">
                                    <label class="text-xs text-slate-400 block mb-1">{{ $t('Input type') }}</label>
                                    <select
                                        v-model="comp.inputType"
                                        class="text-sm border border-slate-200 rounded-md px-2.5 py-1.5 bg-white focus:outline-none focus:ring-1 focus:ring-primary"
                                    >
                                        <option v-for="t in inputTypes" :key="t" :value="t">{{ t }}</option>
                                    </select>
                                </div>
                                <label class="flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer select-none mt-3">
                                    <input type="checkbox" v-model="comp.required" class="rounded accent-primary" />
                                    {{ $t('Required') }}
                                </label>
                            </div>
                        </div>

                        <!-- Dropdown / CheckboxGroup / RadioButtonsGroup -->
                        <div v-else-if="['Dropdown','CheckboxGroup','RadioButtonsGroup'].includes(comp.type)">
                            <div class="grid grid-cols-2 gap-2 mb-2">
                                <div>
                                    <label class="text-xs text-slate-400 block mb-1">{{ $t('Label') }}</label>
                                    <input
                                        v-model="comp.label"
                                        type="text"
                                        placeholder="e.g. Select country"
                                        class="w-full text-sm border border-slate-200 rounded-md px-2.5 py-1.5 focus:outline-none focus:ring-1 focus:ring-primary"
                                    />
                                </div>
                                <div>
                                    <label class="text-xs text-slate-400 block mb-1">{{ $t('Field name') }}</label>
                                    <input
                                        v-model="comp.name"
                                        type="text"
                                        placeholder="country"
                                        class="w-full text-sm border border-slate-200 rounded-md px-2.5 py-1.5 font-mono focus:outline-none focus:ring-1 focus:ring-primary"
                                    />
                                </div>
                            </div>
                            <label class="flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer select-none mb-3">
                                <input type="checkbox" v-model="comp.required" class="rounded accent-primary" />
                                {{ $t('Required') }}
                            </label>

                            <!-- Options list -->
                            <div>
                                <label class="text-xs text-slate-400 block mb-1.5">{{ $t('Options') }}</label>
                                <div class="space-y-1.5">
                                    <div
                                        v-for="(opt, optIndex) in comp.options"
                                        :key="optIndex"
                                        class="flex gap-2 items-center"
                                    >
                                        <input
                                            v-model="opt.id"
                                            type="text"
                                            placeholder="id"
                                            class="w-1/3 text-xs border border-slate-200 rounded-md px-2 py-1.5 font-mono focus:outline-none focus:ring-1 focus:ring-primary bg-slate-50"
                                        />
                                        <input
                                            v-model="opt.title"
                                            type="text"
                                            placeholder="Display label"
                                            class="flex-1 text-sm border border-slate-200 rounded-md px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-primary"
                                        />
                                        <button
                                            @click="comp.options.splice(optIndex, 1)"
                                            class="text-red-400 hover:text-red-600 w-6 h-6 flex items-center justify-center text-lg leading-none shrink-0"
                                        >×</button>
                                    </div>
                                </div>
                                <button
                                    @click="comp.options.push({ id: '', title: '' })"
                                    class="mt-2 text-xs text-primary hover:underline"
                                >+ {{ $t('Add option') }}</button>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div v-else-if="comp.type === 'Footer'" class="grid grid-cols-3 gap-2">
                            <div>
                                <label class="text-xs text-slate-400 block mb-1">{{ $t('Button label') }}</label>
                                <input
                                    v-model="comp.label"
                                    type="text"
                                    placeholder="Continue"
                                    class="w-full text-sm border border-slate-200 rounded-md px-2.5 py-1.5 focus:outline-none focus:ring-1 focus:ring-primary"
                                />
                            </div>
                            <div>
                                <label class="text-xs text-slate-400 block mb-1">{{ $t('Action') }}</label>
                                <select
                                    v-model="comp.action"
                                    class="w-full text-sm border border-slate-200 rounded-md px-2.5 py-1.5 bg-white focus:outline-none focus:ring-1 focus:ring-primary"
                                >
                                    <option value="complete">{{ $t('Submit / complete') }}</option>
                                    <option value="navigate">{{ $t('Navigate to screen') }}</option>
                                </select>
                            </div>
                            <div v-if="comp.action === 'navigate'">
                                <label class="text-xs text-slate-400 block mb-1">{{ $t('Target screen ID') }}</label>
                                <input
                                    v-model="comp.nextScreen"
                                    type="text"
                                    placeholder="SCREEN_2"
                                    class="w-full text-sm border border-slate-200 rounded-md px-2.5 py-1.5 font-mono focus:outline-none focus:ring-1 focus:ring-primary"
                                    @input="comp.nextScreen = comp.nextScreen.toUpperCase().replace(/\s+/g, '_')"
                                />
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Empty components state -->
                <div v-if="activeScreen.components.length === 0" class="text-center py-8 border-2 border-dashed border-slate-200 rounded-xl text-slate-400 text-sm">
                    {{ $t('No components yet. Add one below.') }}
                </div>
            </div>

            <!-- ── Add Component Picker ────────────────────────────────── -->
            <div v-if="!showingPicker" class="mt-2">
                <button
                    @click="showingPicker = true"
                    class="w-full py-2.5 border-2 border-dashed border-slate-200 rounded-xl text-sm text-slate-400 hover:border-primary hover:text-primary transition-colors"
                >
                    + {{ $t('Add Component') }}
                </button>
            </div>

            <div v-else class="border border-slate-200 rounded-xl overflow-hidden bg-white">
                <div class="flex items-center justify-between px-4 py-2.5 bg-slate-50 border-b border-slate-200">
                    <span class="text-xs font-semibold text-slate-600">{{ $t('Choose component type') }}</span>
                    <button @click="showingPicker = false" class="text-xs text-slate-400 hover:text-slate-600">
                        {{ $t('Cancel') }}
                    </button>
                </div>
                <div class="p-4 space-y-3">
                    <div
                        v-for="group in componentGroups"
                        :key="group.label"
                    >
                        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide mb-2">{{ $t(group.label) }}</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="ctype in group.types"
                                :key="ctype.value"
                                @click="addComponent(activeScreenIndex, ctype.value); showingPicker = false"
                                :class="[
                                    'flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-lg border font-medium transition-colors',
                                    componentBadge(ctype.value),
                                    'hover:opacity-80'
                                ]"
                            >
                                <span v-html="componentIcon(ctype.value)" class="w-3 h-3 flex items-center"></span>
                                {{ ctype.label }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</template>

<script setup>
    import { ref, computed, watch } from 'vue';

    const props = defineProps(['modelValue']);
    const emit  = defineEmits(['update:modelValue', 'screenChange']);

    // ── State ────────────────────────────────────────────────────────────────
    const activeScreenIndex = ref(0);
    const showingPicker     = ref(false);

    // ── Component type metadata ──────────────────────────────────────────────
    const componentGroups = [
        {
            label: 'Text Content',
            types: [
                { value: 'TextHeading',    label: 'Heading' },
                { value: 'TextSubheading', label: 'Subheading' },
                { value: 'TextBody',       label: 'Body Text' },
            ],
        },
        {
            label: 'Input Fields',
            types: [
                { value: 'TextInput', label: 'Text Input' },
                { value: 'TextArea',  label: 'Text Area' },
            ],
        },
        {
            label: 'Choice Fields',
            types: [
                { value: 'Dropdown',          label: 'Dropdown' },
                { value: 'CheckboxGroup',     label: 'Checkboxes' },
                { value: 'RadioButtonsGroup', label: 'Radio Buttons' },
            ],
        },
        {
            label: 'Action',
            types: [
                { value: 'Footer', label: 'Footer Button' },
            ],
        },
    ];

    const componentTypes = componentGroups.flatMap(g => g.types);
    const inputTypes     = ['text', 'number', 'email', 'phone', 'password'];

    const labelForType = (type) => componentTypes.find(c => c.value === type)?.label ?? type;

    const componentBadge = (type) => {
        const map = {
            TextHeading:       'bg-blue-50 text-blue-600 border-blue-200',
            TextSubheading:    'bg-blue-50 text-blue-500 border-blue-100',
            TextBody:          'bg-slate-50 text-slate-500 border-slate-200',
            TextInput:         'bg-green-50 text-green-600 border-green-200',
            TextArea:          'bg-green-50 text-green-600 border-green-200',
            Dropdown:          'bg-purple-50 text-purple-600 border-purple-200',
            CheckboxGroup:     'bg-purple-50 text-purple-600 border-purple-200',
            RadioButtonsGroup: 'bg-purple-50 text-purple-600 border-purple-200',
            Footer:            'bg-orange-50 text-orange-600 border-orange-200',
        };
        return map[type] ?? 'bg-slate-50 text-slate-500 border-slate-200';
    };

    const componentHeaderBg = (type) => {
        const map = {
            TextHeading:       'bg-blue-50/60',
            TextSubheading:    'bg-blue-50/40',
            TextBody:          'bg-slate-50',
            TextInput:         'bg-green-50/60',
            TextArea:          'bg-green-50/60',
            Dropdown:          'bg-purple-50/60',
            CheckboxGroup:     'bg-purple-50/60',
            RadioButtonsGroup: 'bg-purple-50/60',
            Footer:            'bg-orange-50/60',
        };
        return map[type] ?? 'bg-slate-50';
    };

    const componentBorder = (type) => {
        const map = {
            TextHeading:       'border-blue-100',
            TextSubheading:    'border-blue-100',
            TextBody:          'border-slate-200',
            TextInput:         'border-green-100',
            TextArea:          'border-green-100',
            Dropdown:          'border-purple-100',
            CheckboxGroup:     'border-purple-100',
            RadioButtonsGroup: 'border-purple-100',
            Footer:            'border-orange-100',
        };
        return map[type] ?? 'border-slate-200';
    };

    const componentIcon = (type) => {
        const icons = {
            TextHeading:       '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M4 6h16M4 12h8"/></svg>',
            TextSubheading:    '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M4 7h16M4 12h10"/></svg>',
            TextBody:          '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 10h16M4 14h12"/></svg>',
            TextInput:         '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="7" width="20" height="10" rx="2"/><path d="M7 12h2"/></svg>',
            TextArea:          '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M7 8h10M7 12h10M7 16h6"/></svg>',
            Dropdown:          '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>',
            CheckboxGroup:     '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="m9 12 2 2 4-4"/></svg>',
            RadioButtonsGroup: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4" fill="currentColor"/></svg>',
            Footer:            '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="8" width="18" height="8" rx="4"/></svg>',
        };
        return icons[type] ?? '';
    };

    const compPreview = (comp) => {
        if (comp.text)  return comp.text;
        if (comp.label) return comp.label;
        return '';
    };

    // ── Screen navigation ────────────────────────────────────────────────────
    const setActiveScreen = (index) => {
        activeScreenIndex.value = index;
        showingPicker.value     = false;
        emit('screenChange', index);
    };

    const activeScreen = computed(() => screens.value[activeScreenIndex.value] ?? null);

    // ── Parse / emit Flow JSON ───────────────────────────────────────────────
    const parseComponent = (comp) => {
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
                return {
                    type:     comp.type,
                    label:    comp.label || '',
                    name:     comp.name  || '',
                    required: !!comp.required,
                    options:  (comp['data-source'] || []).map(o => ({ id: o.id || '', title: o.title || '' })),
                };
            case 'Footer': {
                const ca = comp['on-click-action'] ?? {};
                return { type: 'Footer', label: comp.label || 'Continue', action: ca.name || 'complete', nextScreen: ca.next?.name || '' };
            }
            default:
                return { type: comp.type, _raw: comp };
        }
    };

    const parseFromJson = (jsonStr) => {
        if (!jsonStr) return [];
        try {
            const json = typeof jsonStr === 'string' ? JSON.parse(jsonStr) : jsonStr;
            return (json.screens || []).map(screen => ({
                id:         screen.id || '',
                title:      screen.title || '',
                terminal:   !!screen.terminal,
                components: (screen.layout?.children || []).map(parseComponent),
            }));
        } catch {
            return [];
        }
    };

    const compToJson = (comp) => {
        switch (comp.type) {
            case 'TextHeading':
            case 'TextSubheading':
            case 'TextBody':
                return { type: comp.type, text: comp.text };
            case 'TextInput':
                return { type: 'TextInput', label: comp.label, name: comp.name, required: comp.required, 'input-type': comp.inputType || 'text' };
            case 'TextArea':
                return { type: 'TextArea', label: comp.label, name: comp.name, required: comp.required };
            case 'Dropdown':
            case 'CheckboxGroup':
            case 'RadioButtonsGroup':
                return { type: comp.type, label: comp.label, name: comp.name, required: comp.required, 'data-source': comp.options };
            case 'Footer': {
                const action = comp.action === 'navigate'
                    ? { name: 'navigate', next: { type: 'screen', name: comp.nextScreen } }
                    : { name: 'complete', payload: {} };
                return { type: 'Footer', label: comp.label, 'on-click-action': action };
            }
            default:
                return comp._raw || null;
        }
    };

    const toFlowJson = (screensArr) => JSON.stringify({
        version: '6.3',
        screens: screensArr.map(screen => ({
            id:       screen.id    || 'SCREEN',
            title:    screen.title || '',
            terminal: screen.terminal || false,
            layout:   { type: 'SingleColumnLayout', children: screen.components.map(compToJson).filter(Boolean) },
        })),
    }, null, 2);

    const defaultForType = (type) => {
        switch (type) {
            case 'TextHeading':
            case 'TextSubheading':
            case 'TextBody':    return { type, text: '' };
            case 'TextInput':   return { type, label: '', name: '', required: false, inputType: 'text' };
            case 'TextArea':    return { type, label: '', name: '', required: false };
            case 'Dropdown':
            case 'CheckboxGroup':
            case 'RadioButtonsGroup': return { type, label: '', name: '', required: false, options: [{ id: '', title: '' }] };
            case 'Footer':      return { type, label: 'Continue', action: 'complete', nextScreen: '' };
            default:            return { type };
        }
    };

    const screens = ref(parseFromJson(props.modelValue));

    // Emit updated JSON whenever screens change
    watch(screens, () => {
        emit('update:modelValue', toFlowJson(screens.value));
    }, { deep: true });

    // Re-parse when parent pushes a new JSON (e.g. after Sync from Meta)
    watch(() => props.modelValue, (newVal) => {
        const reparsed = parseFromJson(newVal);
        if (JSON.stringify(reparsed) !== JSON.stringify(screens.value)) {
            screens.value = reparsed;
        }
    });

    // ── CRUD ─────────────────────────────────────────────────────────────────
    const addScreen = () => {
        screens.value.push({ id: `SCREEN_${screens.value.length + 1}`, title: '', terminal: false, components: [] });
        setActiveScreen(screens.value.length - 1);
    };

    const removeScreen = (index) => {
        screens.value.splice(index, 1);
        const next = Math.max(0, Math.min(index, screens.value.length - 1));
        setActiveScreen(next);
    };

    const addComponent    = (si, type) => screens.value[si].components.push(defaultForType(type));
    const removeComponent = (si, ci)   => screens.value[si].components.splice(ci, 1);

    const moveComponent = (si, ci, dir) => {
        const arr = screens.value[si].components;
        const tgt = ci + dir;
        if (tgt < 0 || tgt >= arr.length) return;
        [arr[ci], arr[tgt]] = [arr[tgt], arr[ci]];
    };
</script>
