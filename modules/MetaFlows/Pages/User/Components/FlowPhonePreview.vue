<template>
    <div class="flex flex-col items-center select-none">

        <!-- Phone frame -->
        <div class="w-[240px] bg-[#1a1a2e] rounded-[2.2rem] p-2.5 shadow-xl border border-[#2a2a4e]">

            <!-- Status bar -->
            <div class="flex items-center justify-between px-4 py-1 mb-1">
                <span class="text-white text-[9px] font-medium">9:41</span>
                <div class="flex items-center gap-1">
                    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="white"><path d="M1 9l2 2c4.97-4.97 13.03-4.97 18 0l2-2C16.93 2.93 7.08 2.93 1 9zm8 8l3 3 3-3c-1.65-1.66-4.34-1.66-6 0zm-4-4l2 2c2.76-2.76 7.24-2.76 10 0l2-2C15.14 9.14 8.87 9.14 5 13z"/></svg>
                    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="white"><path d="M15.67 4H14V2h-4v2H8.33C7.6 4 7 4.6 7 5.33v15.33C7 21.4 7.6 22 8.33 22h7.33c.74 0 1.34-.6 1.34-1.33V5.33C17 4.6 16.4 4 15.67 4z"/></svg>
                </div>
            </div>

            <!-- Screen area -->
            <div class="bg-[#ECE5DD] rounded-[1.7rem] overflow-hidden" style="height: 430px;">

                <!-- WhatsApp app header -->
                <div class="bg-[#034737] flex items-center px-3 py-2 gap-2">
                    <div class="w-7 h-7 rounded-full bg-[#25D366] flex items-center justify-center shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="white"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
                    </div>
                    <div>
                        <p class="text-white text-[10px] font-semibold leading-none">Business Name</p>
                        <p class="text-green-200 text-[8px]">WhatsApp Flow</p>
                    </div>
                    <svg class="ms-auto" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                </div>

                <!-- Flow dialog (bottom-sheet style) -->
                <div class="mx-1.5 mt-2">
                    <div class="bg-white rounded-xl overflow-hidden shadow-sm" style="max-height: 376px; display:flex; flex-direction:column;">

                        <!-- Dialog top bar -->
                        <div class="flex items-center justify-between px-3 py-2 border-b border-gray-100 shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                            <span class="text-[10px] font-semibold text-gray-700 truncate mx-2 max-w-[120px]">{{ currentScreen?.title || 'Flow' }}</span>
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="#6b7280"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                        </div>

                        <!-- Screen content (scrollable) -->
                        <div class="flex-1 overflow-y-auto px-3 py-2.5 space-y-2" style="min-height: 0;" v-if="currentScreen">
                            <template v-for="(comp, i) in nonFooterComponents" :key="i">

                                <!-- TextHeading -->
                                <p v-if="comp.type === 'TextHeading'"
                                   class="text-[13px] font-bold text-gray-900 leading-snug">
                                    {{ comp.text }}
                                </p>

                                <!-- TextSubheading -->
                                <p v-else-if="comp.type === 'TextSubheading'"
                                   class="text-[11px] font-semibold text-gray-700">
                                    {{ comp.text }}
                                </p>

                                <!-- TextBody -->
                                <p v-else-if="comp.type === 'TextBody'"
                                   class="text-[10px] text-gray-500 leading-relaxed">
                                    {{ comp.text }}
                                </p>

                                <!-- TextInput -->
                                <div v-else-if="comp.type === 'TextInput'">
                                    <p class="text-[9px] text-gray-500 mb-0.5">
                                        {{ comp.label }}<span v-if="comp.required" class="text-red-400 ms-0.5">*</span>
                                    </p>
                                    <div class="border border-gray-200 rounded-md px-2 py-1 text-[9px] text-gray-300 bg-gray-50">
                                        {{ inputPlaceholder(comp) }}
                                    </div>
                                </div>

                                <!-- TextArea -->
                                <div v-else-if="comp.type === 'TextArea'">
                                    <p class="text-[9px] text-gray-500 mb-0.5">
                                        {{ comp.label }}<span v-if="comp.required" class="text-red-400 ms-0.5">*</span>
                                    </p>
                                    <div class="border border-gray-200 rounded-md px-2 py-2 text-[9px] text-gray-300 bg-gray-50" style="min-height:28px;">
                                        Type here...
                                    </div>
                                </div>

                                <!-- Dropdown -->
                                <div v-else-if="comp.type === 'Dropdown'">
                                    <p class="text-[9px] text-gray-500 mb-0.5">{{ comp.label }}</p>
                                    <div class="border border-gray-200 rounded-md px-2 py-1 text-[9px] text-gray-300 bg-gray-50 flex items-center justify-between">
                                        <span>Select option</span>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="#9ca3af"><path d="m7 10 5 5 5-5z"/></svg>
                                    </div>
                                </div>

                                <!-- RadioButtonsGroup -->
                                <div v-else-if="comp.type === 'RadioButtonsGroup'">
                                    <p class="text-[9px] text-gray-500 mb-1">{{ comp.label }}</p>
                                    <div class="space-y-1">
                                        <div v-for="(opt, oi) in (comp.options || []).slice(0, 5)" :key="oi"
                                             class="flex items-center gap-1.5">
                                            <div class="w-2.5 h-2.5 rounded-full border border-gray-400 shrink-0 flex items-center justify-center">
                                                <div v-if="oi === 0" class="w-1.5 h-1.5 rounded-full bg-[#034737]"></div>
                                            </div>
                                            <span class="text-[9px] text-gray-700">{{ opt.title }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- CheckboxGroup -->
                                <div v-else-if="comp.type === 'CheckboxGroup'">
                                    <p class="text-[9px] text-gray-500 mb-1">{{ comp.label }}</p>
                                    <div class="space-y-1">
                                        <div v-for="(opt, oi) in (comp.options || []).slice(0, 4)" :key="oi"
                                             class="flex items-center gap-1.5">
                                            <div class="w-2.5 h-2.5 rounded border border-gray-400 shrink-0"></div>
                                            <span class="text-[9px] text-gray-700">{{ opt.title }}</span>
                                        </div>
                                    </div>
                                </div>

                            </template>
                        </div>

                        <!-- Empty state -->
                        <div v-else class="flex-1 flex items-center justify-center text-gray-300 text-[10px] py-6">
                            No screens yet
                        </div>

                        <!-- Footer CTA button -->
                        <div v-if="footerComponent" class="px-3 pb-2 pt-1 shrink-0">
                            <div class="bg-[#034737] text-white text-center rounded-full py-1.5 text-[10px] font-medium">
                                {{ footerComponent.label || 'Continue' }}
                            </div>
                        </div>

                        <!-- Managed by notice -->
                        <div class="text-center pb-2 text-[8px] text-gray-400 shrink-0">
                            Managed by the business.
                            <span class="text-[#034737]">Learn more</span>
                        </div>

                    </div>
                </div>

            </div>

            <!-- Home bar -->
            <div class="flex justify-center mt-2">
                <div class="w-20 h-1 bg-gray-600 rounded-full"></div>
            </div>
        </div>

        <!-- Screen navigator -->
        <div v-if="screens && screens.length > 0" class="flex items-center gap-2 mt-4">
            <button
                @click="prevScreen"
                :disabled="currentIndex === 0"
                class="w-7 h-7 flex items-center justify-center rounded border border-slate-200 text-slate-500 hover:bg-slate-100 disabled:opacity-30 transition-colors"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
            </button>

            <select
                v-model="currentIndex"
                class="text-xs border border-slate-200 rounded-md px-2 py-1 bg-white text-slate-600 max-w-[150px] focus:outline-none focus:ring-1 focus:ring-primary"
            >
                <option v-for="(sc, i) in screens" :key="i" :value="i">
                    {{ sc.id || `SCREEN_${i + 1}` }}
                </option>
            </select>

            <button
                @click="nextScreen"
                :disabled="!screens || currentIndex >= screens.length - 1"
                class="w-7 h-7 flex items-center justify-center rounded border border-slate-200 text-slate-500 hover:bg-slate-100 disabled:opacity-30 transition-colors"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
            </button>
        </div>

        <p v-if="screens && screens.length > 1" class="text-[11px] text-slate-400 mt-1">
            Screen {{ currentIndex + 1 }} of {{ screens.length }}
        </p>

    </div>
</template>

<script setup>
    import { ref, computed, watch } from 'vue';

    const props = defineProps({
        screens: {
            type: Array,
            default: () => [],
        },
        activeIndex: {
            type: Number,
            default: null,
        },
    });

    const currentIndex = ref(0);

    // When screens change (new template/flow loaded), reset to first screen
    watch(() => props.screens, () => {
        currentIndex.value = 0;
    }, { deep: true });

    // When the builder changes its active tab, sync the preview
    watch(() => props.activeIndex, (val) => {
        if (val !== null && val !== undefined) {
            currentIndex.value = val;
        }
    });

    const currentScreen = computed(() => {
        if (!props.screens?.length) return null;
        return props.screens[currentIndex.value] ?? props.screens[0];
    });

    const nonFooterComponents = computed(() =>
        (currentScreen.value?.components ?? []).filter(c => c.type !== 'Footer')
    );

    const footerComponent = computed(() =>
        (currentScreen.value?.components ?? []).find(c => c.type === 'Footer') ?? null
    );

    const inputPlaceholder = (comp) => {
        const map = {
            email:    'Enter email...',
            phone:    'Enter phone...',
            number:   'Enter number...',
            password: '••••••••',
        };
        return map[comp.inputType] ?? 'Type here...';
    };

    const prevScreen = () => { if (currentIndex.value > 0) currentIndex.value--; };
    const nextScreen = () => { if (props.screens && currentIndex.value < props.screens.length - 1) currentIndex.value++; };
</script>
