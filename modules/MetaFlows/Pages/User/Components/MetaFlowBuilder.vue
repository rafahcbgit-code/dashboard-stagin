<template>
    <div class="space-y-4">

        <!-- Empty state shown before any screen has been added -->
        <div v-if="screens.length === 0" class="text-center py-12 border-2 border-dashed border-slate-200 rounded-lg">
            <p class="text-slate-400 text-sm mb-3">No screens yet. Add your first screen to start building the flow.</p>
            <button @click="addScreen" class="rounded-md bg-primary px-4 py-2 text-sm text-white hover:bg-indigo-500">
                + Add Screen
            </button>
        </div>

        <!-- Screen cards -->
        <div
            v-for="(screen, screenIndex) in screens"
            :key="screenIndex"
            class="border border-slate-200 rounded-lg bg-white overflow-hidden"
        >
            <!-- Screen header row -->
            <div class="flex items-start gap-3 px-4 py-3 bg-slate-50 border-b border-slate-200">
                <div class="flex-1 grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Screen ID <span class="text-slate-400">(uppercase, no spaces)</span></label>
                        <input
                            v-model="screen.id"
                            type="text"
                            placeholder="WELCOME"
                            class="w-full text-sm border border-slate-200 rounded px-2 py-1.5 font-mono"
                            @input="screen.id = screen.id.toUpperCase().replace(/\s+/g, '_')"
                        />
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Screen title</label>
                        <input
                            v-model="screen.title"
                            type="text"
                            placeholder="e.g. Welcome"
                            class="w-full text-sm border border-slate-200 rounded px-2 py-1.5"
                        />
                    </div>
                </div>
                <div class="flex items-center gap-3 pt-5 shrink-0">
                    <!-- Terminal means this is the last screen that ends the flow -->
                    <label class="flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer select-none">
                        <input type="checkbox" v-model="screen.terminal" class="rounded" />
                        Terminal screen
                    </label>
                    <button
                        @click="removeScreen(screenIndex)"
                        class="text-xs text-red-400 hover:text-red-600 border border-red-200 hover:border-red-400 rounded px-2 py-1"
                    >
                        Remove
                    </button>
                </div>
            </div>

            <!-- Components list for this screen -->
            <div class="p-4 space-y-2">
                <div
                    v-for="(comp, compIndex) in screen.components"
                    :key="compIndex"
                    class="border border-slate-100 rounded-lg bg-slate-50 p-3"
                >
                    <!-- Component type badge + move/remove controls -->
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-xs font-medium text-slate-500 bg-slate-200 px-2 py-0.5 rounded">
                            {{ labelForType(comp.type) }}
                        </span>
                        <div class="ml-auto flex items-center gap-1">
                            <button
                                v-if="compIndex > 0"
                                @click="moveComponent(screenIndex, compIndex, -1)"
                                class="text-slate-400 hover:text-slate-600 text-sm w-6 h-6 flex items-center justify-center rounded hover:bg-slate-100"
                                title="Move up"
                            >↑</button>
                            <button
                                v-if="compIndex < screen.components.length - 1"
                                @click="moveComponent(screenIndex, compIndex, 1)"
                                class="text-slate-400 hover:text-slate-600 text-sm w-6 h-6 flex items-center justify-center rounded hover:bg-slate-100"
                                title="Move down"
                            >↓</button>
                            <button
                                @click="removeComponent(screenIndex, compIndex)"
                                class="text-red-400 hover:text-red-600 text-base w-6 h-6 flex items-center justify-center rounded hover:bg-red-50"
                                title="Remove"
                            >×</button>
                        </div>
                    </div>

                    <!-- TextHeading / TextSubheading / TextBody -->
                    <div v-if="['TextHeading','TextSubheading','TextBody'].includes(comp.type)">
                        <input
                            v-model="comp.text"
                            type="text"
                            placeholder="Text content"
                            class="w-full text-sm border border-slate-200 rounded px-2 py-1.5 bg-white"
                        />
                    </div>

                    <!-- TextInput / TextArea -->
                    <div v-else-if="['TextInput','TextArea'].includes(comp.type)">
                        <div class="grid grid-cols-2 gap-2 mb-2">
                            <div>
                                <label class="text-xs text-slate-400 block mb-1">Label</label>
                                <input
                                    v-model="comp.label"
                                    type="text"
                                    placeholder="e.g. First Name"
                                    class="w-full text-sm border border-slate-200 rounded px-2 py-1.5 bg-white"
                                />
                            </div>
                            <div>
                                <label class="text-xs text-slate-400 block mb-1">Field name <span class="text-slate-300">(snake_case)</span></label>
                                <input
                                    v-model="comp.name"
                                    type="text"
                                    placeholder="first_name"
                                    class="w-full text-sm border border-slate-200 rounded px-2 py-1.5 bg-white font-mono"
                                />
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div v-if="comp.type === 'TextInput'">
                                <label class="text-xs text-slate-400 block mb-1">Input type</label>
                                <select
                                    v-model="comp.inputType"
                                    class="text-sm border border-slate-200 rounded px-2 py-1.5 bg-white"
                                >
                                    <option v-for="t in inputTypes" :key="t" :value="t">{{ t }}</option>
                                </select>
                            </div>
                            <label class="flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer select-none mt-4">
                                <input type="checkbox" v-model="comp.required" class="rounded" />
                                Required
                            </label>
                        </div>
                    </div>

                    <!-- Dropdown / CheckboxGroup / RadioButtonsGroup -->
                    <div v-else-if="['Dropdown','CheckboxGroup','RadioButtonsGroup'].includes(comp.type)">
                        <div class="grid grid-cols-2 gap-2 mb-2">
                            <div>
                                <label class="text-xs text-slate-400 block mb-1">Label</label>
                                <input
                                    v-model="comp.label"
                                    type="text"
                                    placeholder="e.g. Country"
                                    class="w-full text-sm border border-slate-200 rounded px-2 py-1.5 bg-white"
                                />
                            </div>
                            <div>
                                <label class="text-xs text-slate-400 block mb-1">Field name</label>
                                <input
                                    v-model="comp.name"
                                    type="text"
                                    placeholder="country"
                                    class="w-full text-sm border border-slate-200 rounded px-2 py-1.5 bg-white font-mono"
                                />
                            </div>
                        </div>
                        <label class="flex items-center gap-1.5 text-xs text-slate-500 cursor-pointer select-none mb-3">
                            <input type="checkbox" v-model="comp.required" class="rounded" />
                            Required
                        </label>
                        <div>
                            <label class="text-xs text-slate-400 block mb-1.5">Options</label>
                            <div
                                v-for="(opt, optIndex) in comp.options"
                                :key="optIndex"
                                class="flex gap-2 mb-1.5"
                            >
                                <input
                                    v-model="opt.id"
                                    type="text"
                                    placeholder="ID"
                                    class="w-1/3 text-xs border border-slate-200 rounded px-2 py-1 bg-white font-mono"
                                />
                                <input
                                    v-model="opt.title"
                                    type="text"
                                    placeholder="Display label"
                                    class="flex-1 text-sm border border-slate-200 rounded px-2 py-1 bg-white"
                                />
                                <button
                                    @click="comp.options.splice(optIndex, 1)"
                                    class="text-red-400 hover:text-red-600 text-lg leading-none px-1"
                                >×</button>
                            </div>
                            <button
                                @click="comp.options.push({ id: '', title: '' })"
                                class="text-xs text-primary hover:underline mt-1"
                            >+ Add option</button>
                        </div>
                    </div>

                    <!-- Footer (submit/navigate button at the bottom of a screen) -->
                    <div v-else-if="comp.type === 'Footer'" class="grid grid-cols-3 gap-2">
                        <div>
                            <label class="text-xs text-slate-400 block mb-1">Button label</label>
                            <input
                                v-model="comp.label"
                                type="text"
                                placeholder="Continue"
                                class="w-full text-sm border border-slate-200 rounded px-2 py-1.5 bg-white"
                            />
                        </div>
                        <div>
                            <label class="text-xs text-slate-400 block mb-1">Action</label>
                            <select
                                v-model="comp.action"
                                class="w-full text-sm border border-slate-200 rounded px-2 py-1.5 bg-white"
                            >
                                <option value="complete">Submit / complete flow</option>
                                <option value="navigate">Navigate to next screen</option>
                            </select>
                        </div>
                        <div v-if="comp.action === 'navigate'">
                            <label class="text-xs text-slate-400 block mb-1">Target screen ID</label>
                            <input
                                v-model="comp.nextScreen"
                                type="text"
                                placeholder="SCREEN_2"
                                class="w-full text-sm border border-slate-200 rounded px-2 py-1.5 bg-white font-mono"
                                @input="comp.nextScreen = comp.nextScreen.toUpperCase().replace(/\s+/g, '_')"
                            />
                        </div>
                    </div>
                </div>

                <!-- Add component picker -->
                <div v-if="addingToScreen !== screenIndex">
                    <button
                        @click="addingToScreen = screenIndex"
                        class="w-full text-sm text-slate-400 border border-dashed border-slate-200 rounded-lg px-4 py-2.5 hover:border-primary hover:text-primary transition-colors"
                    >
                        + Add Component
                    </button>
                </div>
                <div v-else class="border border-slate-200 rounded-lg p-3 bg-white">
                    <p class="text-xs text-slate-400 mb-2">Select a component type to add:</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="ctype in componentTypes"
                            :key="ctype.value"
                            @click="addComponent(screenIndex, ctype.value); addingToScreen = null"
                            class="text-xs px-3 py-1.5 border border-slate-200 rounded-md hover:bg-primary hover:text-white hover:border-primary transition-colors"
                        >
                            {{ ctype.label }}
                        </button>
                        <button
                            @click="addingToScreen = null"
                            class="text-xs px-3 py-1.5 text-slate-400 hover:text-slate-600"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add screen button (only shown after at least one screen exists) -->
        <button
            v-if="screens.length > 0"
            @click="addScreen"
            class="w-full py-3 border-2 border-dashed border-slate-200 rounded-lg text-sm text-slate-400 hover:border-primary hover:text-primary transition-colors"
        >
            + Add Screen
        </button>
    </div>
</template>

<script setup>
    import { ref, watch } from 'vue';

    const props  = defineProps(['modelValue']);
    const emit   = defineEmits(['update:modelValue']);

    const addingToScreen = ref(null);

    const componentTypes = [
        { value: 'TextHeading',       label: 'Heading' },
        { value: 'TextSubheading',    label: 'Subheading' },
        { value: 'TextBody',          label: 'Body Text' },
        { value: 'TextInput',         label: 'Text Input' },
        { value: 'TextArea',          label: 'Text Area' },
        { value: 'Dropdown',          label: 'Dropdown' },
        { value: 'CheckboxGroup',     label: 'Checkboxes' },
        { value: 'RadioButtonsGroup', label: 'Radio Buttons' },
        { value: 'Footer',            label: 'Button / Footer' },
    ];

    const inputTypes = ['text', 'number', 'email', 'phone', 'password'];

    // Human-friendly label for the component type badge
    const labelForType = (type) => {
        const found = componentTypes.find(c => c.value === type);
        return found ? found.label : type;
    };

    // Convert a raw WhatsApp Flow JSON component to the internal editing format
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
                    name:     comp.name || '',
                    required: !!comp.required,
                    options:  (comp['data-source'] || []).map(o => ({ id: o.id || '', title: o.title || '' })),
                };
            case 'Footer': {
                const clickAction = comp['on-click-action'] ?? {};
                return {
                    type:       'Footer',
                    label:      comp.label || 'Continue',
                    action:     clickAction.name || 'complete',
                    nextScreen: clickAction.next?.name || '',
                };
            }
            default:
                // Keep unknown component types as-is so we don't lose them on save
                return { type: comp.type, _raw: comp };
        }
    };

    // Parse a WhatsApp Flow JSON string into the internal screens array
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

    // Convert an internal component back to the WhatsApp Flow JSON format
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
                // Pass unknown components through unchanged
                return comp._raw || null;
        }
    };

    // Build a new Flow JSON string from the current screens array
    const toFlowJson = (screensArr) => {
        return JSON.stringify({
            version: '6.3',
            screens: screensArr.map(screen => ({
                id:       screen.id || 'SCREEN',
                title:    screen.title || '',
                terminal: screen.terminal || false,
                layout: {
                    type:     'SingleColumnLayout',
                    children: screen.components.map(compToJson).filter(Boolean),
                },
            })),
        }, null, 2);
    };

    // Return a blank component object for a given type
    const defaultForType = (type) => {
        switch (type) {
            case 'TextHeading':
            case 'TextSubheading':
            case 'TextBody':
                return { type, text: '' };
            case 'TextInput':
                return { type, label: '', name: '', required: false, inputType: 'text' };
            case 'TextArea':
                return { type, label: '', name: '', required: false };
            case 'Dropdown':
            case 'CheckboxGroup':
            case 'RadioButtonsGroup':
                return { type, label: '', name: '', required: false, options: [{ id: '', title: '' }] };
            case 'Footer':
                return { type, label: 'Continue', action: 'complete', nextScreen: '' };
            default:
                return { type };
        }
    };

    const screens = ref(parseFromJson(props.modelValue));

    // Push JSON updates upward whenever the screens data changes
    watch(screens, () => {
        emit('update:modelValue', toFlowJson(screens.value));
    }, { deep: true });

    // Re-parse when a parent (e.g. Sync from Meta) pushes a new JSON value in
    watch(() => props.modelValue, (newVal) => {
        const reparsed = parseFromJson(newVal);
        if (JSON.stringify(reparsed) !== JSON.stringify(screens.value)) {
            screens.value = reparsed;
        }
    });

    const addScreen = () => {
        screens.value.push({
            id:         `SCREEN_${screens.value.length + 1}`,
            title:      '',
            terminal:   false,
            components: [],
        });
    };

    const removeScreen = (index) => {
        screens.value.splice(index, 1);
    };

    const addComponent = (screenIndex, type) => {
        screens.value[screenIndex].components.push(defaultForType(type));
    };

    const removeComponent = (screenIndex, compIndex) => {
        screens.value[screenIndex].components.splice(compIndex, 1);
    };

    const moveComponent = (screenIndex, compIndex, direction) => {
        const arr    = screens.value[screenIndex].components;
        const target = compIndex + direction;
        if (target < 0 || target >= arr.length) return;
        [arr[compIndex], arr[target]] = [arr[target], arr[compIndex]];
    };
</script>
