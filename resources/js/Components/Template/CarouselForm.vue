<template>
    <div>
        <!-- ── TOP-LEVEL MESSAGE BODY (required by Meta for carousel) ────── -->
        <div class="mb-6">
            <h3 class="text-slate-600 text-sm mb-1">
                {{ $t('Message body') }} <span class="text-xs">({{ $t('Required') }})</span>
            </h3>
            <span class="text-slate-500 text-xs">
                {{ $t('This text appears above the carousel cards and is shared across all cards') }}
            </span>
            <textarea
                :value="messageBody"
                @input="$emit('update:messageBody', $event.target.value)"
                rows="3"
                class="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm outline-none ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 sm:text-sm sm:leading-6 mt-2"
                :placeholder="$t('Enter message body text…')"
                maxlength="1024"
            ></textarea>
            <span class="text-xs text-slate-400">
                {{ (messageBody || '').length }}/1024
            </span>
        </div>

        <!-- ── CARDS ────────────────────────────────────────────────────── -->
        <div
            v-for="(card, index) in cards"
            :key="card._uid || index"
            class="bg-[#f9f9fa] border rounded-lg mb-4 overflow-hidden"
        >
            <!-- Card header bar -->
            <div
                class="flex items-center justify-between px-4 py-2 cursor-pointer select-none"
                @click="toggleCard(index)"
            >
                <span class="text-sm font-medium text-slate-700">
                    {{ $t('Card') }} {{ index + 1 }}
                    <span v-if="!expandedCards[index] && card.body_text" class="font-normal text-slate-400 ms-2">
                        – {{ card.body_text.slice(0, 30) }}{{ card.body_text.length > 30 ? '…' : '' }}
                    </span>
                </span>
                <div class="flex items-center gap-x-2">
                    <button
                        v-if="cards.length > 2"
                        type="button"
                        @click.stop="removeCard(index)"
                        class="text-slate-400 hover:text-red-500 transition"
                        :title="$t('Remove card')"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" fill-rule="evenodd" d="M17.707 7.707a1 1 0 0 0-1.414-1.414L12 10.586L7.707 6.293a1 1 0 0 0-1.414 1.414L10.586 12l-4.293 4.293a1 1 0 1 0 1.414 1.414L12 13.414l4.293 4.293a1 1 0 1 0 1.414-1.414L13.414 12l4.293-4.293Z" clip-rule="evenodd"/></svg>
                    </button>
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" class="text-slate-400 transition-transform" :class="expandedCards[index] ? 'rotate-180' : ''">
                        <path fill="currentColor" d="M7 10l5 5 5-5z"/>
                    </svg>
                </div>
            </div>

            <!-- Card body (collapsible) -->
            <div v-if="expandedCards[index]" class="px-4 pb-4 border-t pt-3">

                <!-- HEADER TYPE -->
                <h3 class="text-slate-600 text-sm mb-1">{{ $t('Header') }} <span class="text-xs">({{ $t('Required') }})</span></h3>
                <span class="text-slate-500 text-xs">{{ $t('Each card must have an image or video header') }}</span>
                <div class="grid grid-cols-2 mt-2 bg-white border rounded-lg mb-3">
                    <button
                        type="button"
                        @click="updateCardField(index, 'header_type', 'IMAGE')"
                        class="text-center py-2 text-sm text-slate-800 m-1"
                        :class="card.header_type === 'IMAGE' ? 'bg-[#f0f4ff] shadow rounded-lg border border-indigo-200' : ''"
                    >{{ $t('Image') }}</button>
                    <button
                        type="button"
                        @click="updateCardField(index, 'header_type', 'VIDEO')"
                        class="text-center py-2 text-sm text-slate-800 m-1"
                        :class="card.header_type === 'VIDEO' ? 'bg-[#f0f4ff] shadow rounded-lg border border-indigo-200' : ''"
                    >{{ $t('Video') }}</button>
                </div>

                <!-- FILE UPLOAD -->
                <div class="mb-4">
                    <div v-if="card.file" class="flex justify-center items-center">
                        <div class="flex items-center gap-x-2 py-1 border bg-slate-100 rounded-lg px-3">
                            <svg v-if="card.header_type === 'IMAGE'" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" class="text-gray-400"><path fill="currentColor" d="M14 9a1.5 1.5 0 1 1 3 0a1.5 1.5 0 0 1-3 0Z"/><path fill="currentColor" fill-rule="evenodd" d="M7.268 4.658a54.647 54.647 0 0 1 9.465 0l1.51.132a3.138 3.138 0 0 1 2.831 2.66a30.604 30.604 0 0 1 0 9.1a3.138 3.138 0 0 1-2.831 2.66l-1.51.131c-3.15.274-6.316.274-9.465 0l-1.51-.131a3.138 3.138 0 0 1-2.832-2.66a30.601 30.601 0 0 1 0-9.1a3.138 3.138 0 0 1 2.831-2.66l1.51-.132Z" clip-rule="evenodd"/></svg>
                            <svg v-else xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" class="text-gray-400"><path fill="none" stroke="currentColor" stroke-width="1.5" d="M2 11.5c0-3.287 0-4.931.908-6.038a4 4 0 0 1 .554-.554C4.57 4 6.212 4 9.5 4c3.287 0 4.931 0 6.038.908a4 4 0 0 1 .554.554C17 6.57 17 8.212 17 11.5v1c0 3.287 0 4.931-.908 6.038a4.001 4.001 0 0 1-.554.554C14.43 20 12.788 20 9.5 20c-3.287 0-4.931 0-6.038-.908a4 4 0 0 1-.554-.554C2 17.43 2 15.788 2 12.5v-1Zm15-2l.658-.329c1.946-.973 2.92-1.46 3.63-1.02c.712.44.712 1.528.712 3.703v.292c0 2.176 0 3.263-.711 3.703c-.712.44-1.685-.047-3.63-1.02L17 14.5v-5Z"/></svg>
                            <span class="text-sm">{{ card.file.name }}</span>
                            <button type="button" @click="removeCardFile(index)" class="text-slate-400 hover:text-red-500">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" fill-rule="evenodd" d="M17.707 7.707a1 1 0 0 0-1.414-1.414L12 10.586L7.707 6.293a1 1 0 0 0-1.414 1.414L10.586 12l-4.293 4.293a1 1 0 1 0 1.414 1.414L12 13.414l4.293 4.293a1 1 0 1 0 1.414-1.414L13.414 12l4.293-4.293Z" clip-rule="evenodd"/></svg>
                            </button>
                        </div>
                    </div>
                    <div v-else-if="existingMediaUrl(card)" class="relative overflow-hidden rounded-lg bg-slate-100">
                        <img v-if="card.header_type === 'IMAGE'" :src="existingMediaUrl(card)" class="max-h-48 w-full object-contain" alt="">
                        <video v-else :src="existingMediaUrl(card)" class="max-h-48 w-full" controls></video>
                        <button type="button" @click="removeCardFile(index)" class="absolute end-2 top-2 rounded-full bg-white p-1 text-slate-600 shadow hover:text-red-500">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" fill-rule="evenodd" d="M17.707 7.707a1 1 0 0 0-1.414-1.414L12 10.586L7.707 6.293a1 1 0 0 0-1.414 1.414L10.586 12l-4.293 4.293a1 1 0 1 0 1.414 1.414L12 13.414l4.293 4.293a1 1 0 1 0 1.414-1.414L13.414 12l4.293-4.293Z" clip-rule="evenodd"/></svg>
                        </button>
                    </div>
                    <div v-else class="flex justify-center px-4 pt-4 pb-4 border-2 border-gray-300 border-dashed rounded-md">
                        <input
                            type="file"
                            class="sr-only"
                            :accept="card.header_type === 'IMAGE' ? '.jpg,.jpeg,.png' : '.mp4'"
                            :ref="el => setFileInputRef(el, index)"
                            @change="handleCardFileUpload($event, index)"
                        />
                        <div class="text-center">
                            <div class="cursor-pointer" @click="triggerFileInput(index)">
                                <svg v-if="card.header_type === 'IMAGE'" class="mx-auto h-10 w-10 text-gray-400" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M14 9a1.5 1.5 0 1 1 3 0a1.5 1.5 0 0 1-3 0Z"/><path fill="currentColor" fill-rule="evenodd" d="M7.268 4.658a54.647 54.647 0 0 1 9.465 0l1.51.132a3.138 3.138 0 0 1 2.831 2.66a30.604 30.604 0 0 1 0 9.1a3.138 3.138 0 0 1-2.831 2.66l-1.51.131c-3.15.274-6.316.274-9.465 0l-1.51-.131a3.138 3.138 0 0 1-2.832-2.66a30.601 30.601 0 0 1 0-9.1a3.138 3.138 0 0 1 2.831-2.66l1.51-.132Z" clip-rule="evenodd"/></svg>
                                <svg v-else class="mx-auto h-10 w-10 text-gray-400" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.5" d="M2 11.5c0-3.287 0-4.931.908-6.038a4 4 0 0 1 .554-.554C4.57 4 6.212 4 9.5 4c3.287 0 4.931 0 6.038.908a4 4 0 0 1 .554.554C17 6.57 17 8.212 17 11.5v1c0 3.287 0 4.931-.908 6.038a4.001 4.001 0 0 1-.554.554C14.43 20 12.788 20 9.5 20c-3.287 0-4.931 0-6.038-.908a4 4 0 0 1-.554-.554C2 17.43 2 15.788 2 12.5v-1Zm15-2l.658-.329c1.946-.973 2.92-1.46 3.63-1.02c.712.44.712 1.528.712 3.703v.292c0 2.176 0 3.263-.711 3.703c-.712.44-1.685-.047-3.63-1.02L17 14.5v-5Z"/></svg>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">
                                {{ card.header_type === 'IMAGE' ? $t('JPG or PNG') : $t('MP4') }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- BODY TEXT (per card) -->
                <h3 class="text-slate-600 text-sm mb-1">{{ $t('Card body') }} <span class="text-xs">({{ $t('Optional') }})</span></h3>
                <textarea
                    :value="card.body_text"
                    @input="updateCardField(index, 'body_text', $event.target.value)"
                    rows="3"
                    class="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm outline-none ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 sm:text-sm sm:leading-6 mb-3"
                    :placeholder="$t('Card body text…')"
                    :maxlength="1024"
                ></textarea>

                <!-- BUTTONS -->
                <h3 class="text-slate-600 text-sm mb-1">{{ $t('Buttons') }} <span class="text-xs">({{ $t('Required, max 2') }})</span></h3>
                <!-- Warning shown only when the card has zero buttons -->
                <p v-if="card.buttons && card.buttons.length === 0" class="text-xs text-red-600 mb-2">{{ $t('Each card must have at least one button') }}</p>
                <div v-for="(btn, btnIndex) in card.buttons" :key="btnIndex" class="bg-white border rounded-lg p-3 mb-2">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs text-slate-500">{{ btn.is_unsubscribe ? $t('Unsubscribe button') : btn.type === 'QUICK_REPLY' ? $t('Custom') : btn.type === 'URL' ? $t('Website') : $t('Phone') }}</span>
                        <button type="button" @click="removeCardButton(index, btnIndex)" :aria-label="$t('Remove button')" class="text-slate-400 hover:text-red-500">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"><path fill="currentColor" fill-rule="evenodd" d="M17.707 7.707a1 1 0 0 0-1.414-1.414L12 10.586L7.707 6.293a1 1 0 0 0-1.414 1.414L10.586 12l-4.293 4.293a1 1 0 1 0 1.414 1.414L12 13.414l4.293 4.293a1 1 0 1 0 1.414-1.414L13.414 12l4.293-4.293Z" clip-rule="evenodd"/></svg>
                        </button>
                    </div>
                    <div class="flex gap-x-2">
                        <div class="flex-1">
                            <input v-model="btn.text" type="text" class="w-full rounded-md border-0 py-1 px-3 text-sm text-gray-900 shadow-sm outline-none ring-1 ring-inset ring-gray-300 placeholder:text-gray-400" :placeholder="btn.is_unsubscribe ? $t('Unsubscribe button text') : $t('Button text')" :maxlength="btn.type === 'QUICK_REPLY' ? 25 : BUTTON_TEXT_LIMIT" />
                            <div class="mt-1 text-end text-[11px] text-slate-400">{{ textLength(btn.text) }}/{{ btn.type === 'QUICK_REPLY' ? 25 : BUTTON_TEXT_LIMIT }}</div>
                        </div>
                        <input v-if="btn.type === 'URL'" v-model="btn.url" type="url" class="flex-1 rounded-md border-0 py-1 px-3 text-sm text-gray-900 shadow-sm outline-none ring-1 ring-inset ring-gray-300 placeholder:text-gray-400" :placeholder="$t('https://…')" />
                        <template v-if="btn.type === 'PHONE_NUMBER'">
                            <input v-model="btn.country" type="text" class="w-20 rounded-md border-0 py-1 px-3 text-start text-sm text-gray-900 shadow-sm outline-none ring-1 ring-inset ring-gray-300 placeholder:text-gray-400" placeholder="+1" />
                            <input v-model="btn.phone_number" type="text" class="flex-1 rounded-md border-0 py-1 px-3 text-sm text-gray-900 shadow-sm outline-none ring-1 ring-inset ring-gray-300 placeholder:text-gray-400" :placeholder="$t('Phone number')" />
                        </template>
                    </div>
                </div>
                <div v-if="card.buttons && card.buttons.length < 2" class="flex gap-x-2 mt-1">
                    <button type="button" @click="addCardButton(index, 'QUICK_REPLY')" class="flex-1 text-xs text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg py-1.5 px-2">{{ $t('Custom') }}</button>
                    <button type="button" @click="addCardButton(index, 'URL')"         class="flex-1 text-xs text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg py-1.5 px-2">{{ $t('Website') }}</button>
                    <button type="button" @click="addCardButton(index, 'PHONE_NUMBER')" class="flex-1 text-xs text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg py-1.5 px-2">{{ $t('Phone') }}</button>
                </div>
                <button
                    v-if="card.buttons && card.buttons.length < 2 && !card.buttons.some(button => button.is_unsubscribe)"
                    type="button"
                    @click="addCardButton(index, 'UNSUBSCRIBE')"
                    class="mt-2 w-full rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-800 hover:bg-emerald-100"
                >{{ $t('Add unsubscribe button') }}</button>
            </div>
        </div>

        <!-- ADD CARD -->
        <button
            v-if="cards.length < 10"
            type="button"
            @click="addCard"
            class="w-full border-2 border-dashed border-gray-300 rounded-lg py-2 text-sm text-slate-500 hover:border-indigo-300 hover:text-indigo-500 transition mt-2"
        >
            + {{ $t('Add card') }} ({{ cards.length }}/10)
        </button>
    </div>
</template>

<script setup>
import { ref, watch, onErrorCaptured, nextTick } from 'vue';

const props = defineProps({
    cards: {
        type: Array,
        required: true,
    },
    messageBody: {
        type: String,
        default: '',
    },
    unsubscribeKeyword: {
        type: String,
        default: 'stop',
    },
});

const emit = defineEmits([
    'update:cards',
    'update:messageBody',
]);

const MAX_HEADER_IMAGE_SIZE = 5 * 1024 * 1024;
const MAX_HEADER_VIDEO_SIZE = 16 * 1024 * 1024;
const BUTTON_TEXT_LIMIT = 40;
const HEADER_IMAGE_SIZE_ERROR = 'Image size must not exceed 5MB.';
const HEADER_VIDEO_SIZE_ERROR = 'Video size must not exceed 16MB.';

const textLength = (value) => Array.from(String(value ?? '')).length;

/* ── Unique ID counter for stable v-for keys ────────────────────────── */
let uidCounter = 0;
function ensureUid(card) {
    if (!card._uid) {
        card._uid = '__card_' + (++uidCounter);
    }
    return card;
}

/* ── Expanded / collapsed state per card index ───────────────────────── */
const expandedCards = ref({});

// Initialize expandedCards from the current cards prop
function syncExpandedCards() {
    const current = expandedCards.value;
    const next = {};
    props.cards.forEach((_, i) => {
        // Keep existing expand state, default first card open
        next[i] = current[i] !== undefined ? current[i] : (i === 0);
    });
    expandedCards.value = next;
}
syncExpandedCards();

// Re-sync whenever cards length changes (add/remove)
watch(() => props.cards.length, () => {
    syncExpandedCards();
});

/* ── File input refs (one per card index) ────────────────────────────── */
const fileInputRefs = {};

function setFileInputRef(el, index) {
    if (el) {
        fileInputRefs[index] = el;
    } else {
        delete fileInputRefs[index];
    }
}

function triggerFileInput(index) {
    const input = fileInputRefs[index];
    if (input) {
        input.click();
    }
}

function existingMediaUrl(card) {
    if (!card?.reuse_media) return null;
    return card.local_url || card.source_media_url || card.header_handle || null;
}

/* ── Error boundary — prevents white screen on unexpected errors ───── */
onErrorCaptured((err) => {
    console.error('CarouselForm: caught render error', err);
    return false;   // stop propagation, prevent crash
});

/* ── Card mutation helpers ───────────────────────────────────────────── */

/**
 * Emit a fresh copy of the cards array.
 * Each card gets a stable _uid for the v-for key.
 */
function emitCards(cards) {
    cards.forEach(ensureUid);
    emit('update:cards', [...cards]);
}

/**
 * Update a single field on a card without replacing the whole object.
 * Prevents accidental loss of other reactive properties.
 */
function updateCardField(index, field, value) {
    const updated = props.cards.map((c, i) =>
        i === index
            ? {
                ...c,
                [field]: value,
                ...(field === 'header_type' && value !== c.header_type
                    ? { reuse_media: false, discard_media: true, local_url: null, source_media_url: null, header_handle: null }
                    : {}),
            }
            : c
    );
    emitCards(updated);
}

function addCard() {
    if (props.cards.length >= 10) return;
    const newCard = ensureUid({
        header_type: 'IMAGE',
        file: null,
        body_text: '',
        buttons: [
            { type: 'QUICK_REPLY', text: '' },
            { type: 'QUICK_REPLY', text: props.unsubscribeKeyword, is_unsubscribe: true },
        ],
    });
    const updated = [...props.cards, newCard];
    // Expand the new card
    expandedCards.value[updated.length - 1] = true;
    emitCards(updated);
}

function removeCard(index) {
    if (props.cards.length <= 2) return;
    const updated = [...props.cards];
    updated.splice(index, 1);
    // Rebuild expanded state with shifted indices
    const newExpanded = {};
    updated.forEach((_, i) => {
        newExpanded[i] = expandedCards.value[i + (i >= index ? 1 : 0)] ?? false;
    });
    expandedCards.value = newExpanded;
    emitCards(updated);
}

function toggleCard(index) {
    expandedCards.value[index] = !expandedCards.value[index];
}

function handleCardFileUpload(event, cardIndex) {
    const file = event.target.files?.[0];
    if (!file) return;

    console.debug('CarouselForm: card file selected', {
        cardIndex,
        cardsLength: props.cards.length,
        fileName: file.name,
        fileSize: file.size,
        fileType: file.type,
    });

    // Guard: index within bounds
    if (cardIndex < 0 || cardIndex >= props.cards.length) {
        console.error('CarouselForm: cardIndex out of bounds', cardIndex, props.cards.length);
        return;
    }

    if (props.cards[cardIndex]?.header_type === 'IMAGE' && file.size > MAX_HEADER_IMAGE_SIZE) {
        event.target.value = '';
        alert(HEADER_IMAGE_SIZE_ERROR);
        return;
    }

    if (props.cards[cardIndex]?.header_type === 'VIDEO' && file.size > MAX_HEADER_VIDEO_SIZE) {
        event.target.value = '';
        alert(HEADER_VIDEO_SIZE_ERROR);
        return;
    }

    const updated = props.cards.map((c, i) =>
        i === cardIndex ? { ...c, file, reuse_media: false, discard_media: true } : c
    );
    emitCards(updated);
}

function removeCardFile(cardIndex) {
    if (cardIndex < 0 || cardIndex >= props.cards.length) return;
    const updated = props.cards.map((c, i) =>
        i === cardIndex ? {
            ...c,
            file: null,
            reuse_media: false,
            discard_media: true,
            local_url: null,
            source_media_url: null,
            header_handle: null,
        } : c
    );
    emitCards(updated);
}

function addCardButton(cardIndex, type) {
    if (cardIndex < 0 || cardIndex >= props.cards.length) return;
    if ((props.cards[cardIndex].buttons?.length ?? 0) >= 2) return;

    const btn = type === 'UNSUBSCRIBE'
        ? { type: 'QUICK_REPLY', text: props.unsubscribeKeyword, is_unsubscribe: true }
        : { type, text: '' };
    if (type === 'URL')          btn.url = '';
    if (type === 'PHONE_NUMBER') { btn.country = ''; btn.phone_number = ''; }

    const updated = props.cards.map((c, i) =>
        i === cardIndex
            ? {
                ...c,
                buttons: (() => {
                    const buttons = [...(c.buttons || [])];
                    const unsubscribeIndex = buttons.findIndex(button => button.is_unsubscribe);
                    if (unsubscribeIndex === -1) buttons.push(btn);
                    else buttons.splice(unsubscribeIndex, 0, btn);
                    return buttons;
                })(),
            }
            : c
    );
    emitCards(updated);
}

function removeCardButton(cardIndex, btnIndex) {
    if (cardIndex < 0 || cardIndex >= props.cards.length) return;
    const buttons = [...(props.cards[cardIndex].buttons || [])];
    buttons.splice(btnIndex, 1);
    const updated = props.cards.map((c, i) =>
        i === cardIndex ? { ...c, buttons } : c
    );
    emitCards(updated);
}
</script>
