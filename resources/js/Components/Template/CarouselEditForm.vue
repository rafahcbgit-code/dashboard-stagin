<template>
    <!--
        CarouselEditForm.vue
        Shown on the Edit page when the template is a carousel.

        RESTRICTIONS (intentional, shown to the user):
          - Cannot add or remove cards
          - Cannot change or re-upload media
          - Can only edit: body text and button text per card

        The original card structure (number of cards, media, button types/urls)
        is locked in at creation time. This keeps editing simple and avoids
        the complexity of re-uploading media handles.
    -->
    <div>
        <!-- Info banner explaining the edit restrictions -->
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 mb-4">
            <div class="flex items-start gap-x-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" class="text-amber-600 flex-shrink-0 mt-0.5"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3"/></svg>
                <div class="text-sm text-amber-800">
                    <p class="font-medium">{{ $t('Carousel edit restrictions') }}</p>
                    <p class="text-amber-700 mt-0.5">
                        {{ $t('You can only update the body text and button labels on each card. Adding or removing cards and changing media is not allowed after creation.') }}
                    </p>
                </div>
            </div>
        </div>

        <!-- One block per card -->
        <div
            v-for="(card, index) in editableCards"
            :key="index"
            class="bg-[#f9f9fa] border rounded-lg mb-3 overflow-hidden"
        >
            <!-- Card title bar -->
            <div class="flex items-center px-4 py-2 bg-white border-b">
                <span class="text-sm font-medium text-slate-700">{{ $t('Card') }} {{ index + 1 }}</span>
                <!-- Read-only media type badge -->
                <span class="ml-2 text-[10px] uppercase bg-slate-200 text-slate-500 rounded px-1.5 py-0.5">
                    {{ card.header_type }}
                </span>
                <!-- Locked icon -->
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" class="text-slate-400 ml-auto"><path fill="currentColor" d="M12 1a5 5 0 0 0-5 5v2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2h-2V6a5 5 0 0 0-5-5zm3 7V6a3 3 0 1 0-6 0v2z"/></svg>
            </div>

            <div class="px-4 py-3">
                <!-- Media preview (read-only, shows actual media) -->
                <div class="mb-3 bg-[#ccd0d5] flex justify-center overflow-hidden rounded" :class="hasMedia(card) ? '' : 'py-4'">
                    <!-- Show actual media -->
                    <template v-if="hasMedia(card)">
                        <img
                            v-if="card.header_type === 'IMAGE'"
                            :src="getMediaUrl(card)"
                            class="w-full h-auto object-cover max-h-48"
                            @error="handleImageError"
                        />
                        <video
                            v-else-if="card.header_type === 'VIDEO'"
                            :src="getMediaUrl(card)"
                            class="w-full h-auto object-cover max-h-48"
                            controls
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
                <p class="text-[11px] text-slate-400 text-center -mt-1 mb-3">{{ $t('Media cannot be changed') }}</p>

                <!-- Body text – EDITABLE -->
                <label class="text-slate-600 text-sm">{{ $t('Body text') }}</label>
                <textarea
                    v-model="card.body_text"
                    rows="3"
                    class="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm outline-none ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 sm:text-sm sm:leading-6 mt-1 mb-3"
                    :placeholder="$t('Card body text…')"
                    :maxlength="1024"
                ></textarea>

                <!-- Buttons – only TEXT is editable, type/url/phone are locked -->
                <div v-if="card.buttons && card.buttons.length" class="mt-2">
                    <label class="text-slate-600 text-sm">{{ $t('Buttons') }}</label>
                    <div v-for="(btn, btnIndex) in card.buttons" :key="btnIndex" class="flex items-center gap-x-2 mt-1">
                        <!-- Locked type badge -->
                        <span class="text-[10px] uppercase bg-slate-200 text-slate-500 rounded px-1.5 py-0.5 flex-shrink-0">
                            {{ btn.type === 'QUICK_REPLY' ? $t('Custom') : btn.type === 'URL' ? $t('URL') : $t('Phone') }}
                        </span>
                        <!-- Editable button TEXT -->
                        <input
                            v-model="btn.text"
                            type="text"
                            class="flex-1 rounded-md border-0 py-1 px-3 text-sm text-gray-900 shadow-sm outline-none ring-1 ring-inset ring-gray-300 placeholder:text-gray-400"
                            :placeholder="$t('Button label…')"
                            :maxlength="BUTTON_TEXT_LIMIT"
                        />
                        <!-- Locked icon per button -->
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" class="text-slate-300"><path fill="currentColor" d="M12 1a5 5 0 0 0-5 5v2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2h-2V6a5 5 0 0 0-5-5zm3 7V6a3 3 0 1 0-6 0v2z"/></svg>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { defineProps } from 'vue';

const BUTTON_TEXT_LIMIT = 40;

const props = defineProps({
    /**
     * editableCards – array of card objects populated from the template's
     * metadata.carousel_cards when the Edit page loads.
     * Each card: { header_type, header_handle, local_path, local_url, body_text, buttons[] }
     */
    editableCards: {
        type: Array,
        required: true,
    },
});

/**
 * Check if card has media available
 */
function hasMedia(card) {
    return !!(card.local_url || card.header_handle);
}

/**
 * Get the media URL to display
 * Priority: local_url > header_handle (CDN)
 */
function getMediaUrl(card) {
    if (card.local_url) {
        return card.local_url;
    }
    
    if (card.header_handle && card.header_handle.startsWith('http')) {
        return card.header_handle;
    }
    
    return null;
}

/**
 * Handle image load errors
 */
function handleImageError(event) {
    event.target.src = '/images/image-placeholder.png';
    console.warn('Failed to load carousel image');
}
</script>
