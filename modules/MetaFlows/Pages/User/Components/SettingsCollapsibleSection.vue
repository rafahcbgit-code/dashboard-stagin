<template>
    <div class="border border-slate-200 rounded-xl overflow-hidden">
        <button
            @click="open = !open"
            class="w-full flex items-center justify-between px-4 py-3 bg-white hover:bg-slate-50 text-xs text-slate-600 transition-colors"
        >
            <div class="flex items-center gap-2">
                <slot name="icon" />
                <span class="font-medium text-slate-700">{{ title }}</span>
                <span v-if="enabled" class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" :class="open ? 'rotate-180' : ''" class="transition-transform"><path d="m6 9 6 6 6-6"/></svg>
        </button>

        <div v-if="open" class="border-t border-slate-100 p-4 space-y-4 text-xs">
            <slot />
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';

const props = defineProps({
    title: { type: String, required: true },
    defaultOpen: { type: Boolean, default: false },
    enabled: { type: Boolean, default: false },
});

const open = ref(props.defaultOpen);
</script>