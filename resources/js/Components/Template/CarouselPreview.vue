<template>
    <!--
        CarouselPreview.vue
        Renders a WhatsApp-style carousel preview in the right panel.
        Receives the cards array and renders each card as a scrollable bubble.
        This component is used on BOTH the Add page and the Edit page.
    -->
    <div class="flex gap-x-2 overflow-x-auto pb-2">
        <div
            v-for="(card, index) in cards"
            :key="index"
            class="mr-auto rounded-lg rounded-tl-none my-1 p-1 text-sm bg-white flex flex-col relative speech-bubble-left flex-shrink-0"
            style="width: 22em;"
        >
            <!-- HEADER placeholder (image or video) -->
            <div class="mb-2 bg-[#ccd0d5] flex justify-center py-6 rounded">
                <img
                    v-if="card.header_type === 'IMAGE'"
                    :src="'/images/image-placeholder.png'"
                />
                <img
                    v-else
                    :src="'/images/video-placeholder.png'"
                />
            </div>

            <!-- BODY text -->
            <p
                v-if="card.body_text"
                class="px-2 normal-case whitespace-pre-wrap text-sm"
            >{{ card.body_text }}</p>

            <!-- Timestamp -->
            <div class="text-[#8c8c8c] mt-1 px-2 text-right">
                <span class="text-xs leading-none">9:15</span>
            </div>

            <!-- BUTTONS -->
            <div v-if="card.buttons && card.buttons.length" class="mt-1">
                <div
                    v-for="(btn, btnIndex) in card.buttons"
                    :key="btnIndex"
                    class="flex justify-center items-center gap-x-1 rounded-lg bg-white border-t h-9 text-sm text-[#00a5f4]"
                >
                    <!-- icon per button type -->
                    <svg v-if="btn.type === 'PHONE_NUMBER'" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"><path fill="currentColor" d="M20 16v4c-2.758 0-5.07-.495-7-1.325c-3.841-1.652-6.176-4.63-7.5-7.675C4.4 8.472 4 5.898 4 4h4l1 4l-3.5 3c1.324 3.045 3.659 6.023 7.5 7.675L16 15l4 1z"/></svg>
                    <svg v-else-if="btn.type === 'URL'" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"><path fill="currentColor" d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"><path fill="currentColor" d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                    <span>{{ btn.text || '…' }}</span>
                </div>
            </div>

            <!-- Card number badge (small, bottom-right corner) -->
            <div class="absolute -bottom-2 -right-2 bg-slate-200 text-slate-500 text-[10px] rounded-full w-5 h-5 flex items-center justify-center">
                {{ index + 1 }}
            </div>
        </div>
    </div>
</template>

<script setup>
defineProps({
    /**
     * Array of card objects.
     * Each card: { header_type: 'IMAGE'|'VIDEO', body_text: string, buttons: [] }
     * On the Add page, cards come from CarouselForm.
     * On the Edit page, cards come from the existing template metadata.
     */
    cards: {
        type: Array,
        default: () => [],
    },
});
</script>