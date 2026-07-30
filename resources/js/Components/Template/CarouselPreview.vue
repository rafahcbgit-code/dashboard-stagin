<template>
    <!--
        CarouselPreview.vue
        Renders a WhatsApp-style carousel preview in the right panel.
        Receives the cards array and renders each card as a scrollable bubble.
        This component is used on BOTH the Add page and the Edit page.

        On Add page: shows placeholders (no media uploaded yet)
        On Edit page: shows actual media from local_url or header_handle
    -->
    <div class="flex gap-x-2 overflow-x-auto pb-2">
        <div
            v-for="(card, index) in cards"
            :key="index"
            class="mr-auto rounded-lg rounded-tl-none my-1 p-1 text-sm bg-white flex flex-col relative speech-bubble-left flex-shrink-0"
            style="width: 22em;"
        >
            <!-- HEADER with actual media or placeholder -->
            <div class="mb-2 bg-[#ccd0d5] flex justify-center overflow-hidden rounded" :class="hasMedia(card, index) ? 'py-0' : 'py-6'">
                <!-- Show actual media if available -->
                <template v-if="hasMedia(card, index)">
                    <img
                        v-if="card.header_type === 'IMAGE'"
                        :src="getMediaUrl(card, index)"
                        class="w-full h-auto object-cover max-h-64"
                        @error="handleImageError($event, index)"
                    />
                    <video
                        v-else-if="card.header_type === 'VIDEO'"
                        :src="getMediaUrl(card, index)"
                        class="w-full h-auto object-cover max-h-64"
                        controls
                        @error="handleVideoError($event, index)"
                    >
                        {{ $t('Your browser does not support the video tag.') }}
                    </video>
                </template>

                <!-- Fallback placeholders -->
                <template v-else>
                    <img
                        v-if="card.header_type === 'IMAGE'"
                        :src="'/images/image-placeholder.png'"
                    />
                    <img
                        v-else
                        :src="'/images/video-placeholder.png'"
                    />
                </template>
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
import { computed, onBeforeUnmount } from 'vue';

const props = defineProps({
    /**
     * Array of card objects.
     * Each card: {
     *   header_type: 'IMAGE'|'VIDEO',
     *   header_handle: 'CDN_URL' (on edit),
     *   local_url: 'LOCAL_URL' (on edit),
     *   file: File (on add),
     *   body_text: string,
     *   buttons: []
     * }
     */
    cards: {
        type: Array,
        default: () => [],
    },
});

/**
 * Cache blob URLs so we don't call URL.createObjectURL on every render.
 * The WeakMap is keyed by the File object so each file only gets one URL.
 */
const blobUrlCache = new WeakMap();

function getOrCreateBlobUrl(file) {
    if (!file) return null;
    if (blobUrlCache.has(file)) {
        return blobUrlCache.get(file);
    }
    const url = URL.createObjectURL(file);
    blobUrlCache.set(file, url);
    return url;
}

/**
 * Build a map of card-index → resolved media URL so the template can
 * consume it without side-effects during the render phase.
 */
const cardMediaUrls = computed(() => {
    return props.cards.map((card) => {
        if (card.local_url) return card.local_url;
        if (card.header_handle && card.header_handle.startsWith('http')) return card.header_handle;
        if (card.file) return getOrCreateBlobUrl(card.file);
        return null;
    });
});

/**
 * Check if card has media available (either local_url, header_handle, or file)
 */
function hasMedia(card, index) {
    return !!cardMediaUrls.value[index];
}

/**
 * Get the cached / computed media URL for a card by its index.
 */
function getMediaUrl(card, index) {
    return cardMediaUrls.value[index] ?? null;
}

/**
 * Handle image load errors - fallback to placeholder
 */
function getRemoteFallback(card) {
    const fallback = Array.isArray(card.header_handle)
        ? card.header_handle[0]
        : card.header_handle;

    return typeof fallback === 'string' && fallback.startsWith('http')
        ? fallback
        : null;
}

function handleImageError(event, index) {
    const card = props.cards[index];
    const fallback = getRemoteFallback(card || {});

    // A local copy may not have been deployed to production. Try Meta's
    // template media before showing the placeholder.
    if (fallback && event.target.dataset.carouselFallback !== '1') {
        event.target.dataset.carouselFallback = '1';
        event.target.src = fallback;
        return;
    }

    event.target.src = '/images/image-placeholder.png';
    console.warn('Failed to load carousel image');
}

/**
 * Handle video load errors - fallback to placeholder
 */
function handleVideoError(event, index) {
    const card = props.cards[index];
    const fallback = getRemoteFallback(card || {});

    if (fallback && event.target.dataset.carouselFallback !== '1') {
        event.target.dataset.carouselFallback = '1';
        event.target.src = fallback;
        return;
    }

    const placeholder = document.createElement('img');
    placeholder.src = '/images/video-placeholder.png';
    placeholder.className = event.target.className;
    event.target.parentNode.replaceChild(placeholder, event.target);
    console.warn('Failed to load carousel video');
}
</script>
