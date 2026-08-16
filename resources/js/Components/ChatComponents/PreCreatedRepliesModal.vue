<script setup>
import { ref, onBeforeUnmount, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    show: Boolean,
    contactUuid: String,
});

const emit = defineEmits(['close', 'send']);

const replies = ref([]);
const loading = ref(false);
const search = ref('');
const page = ref(1);
const lastPage = ref(1);
const selectedReply = ref(null);
const selectedPayload = ref(null);
let searchTimeout;

const fetchReplies = async (pageNumber = 1) => {
    loading.value = true;

    try {
        const response = await axios.get('/pre-created-replies', {
            params: {
                page: pageNumber,
                search: search.value,
            },
            headers: {
                Accept: 'application/json',
            },
        });

        replies.value = response.data.data;
        page.value = response.data.meta.current_page;
        lastPage.value = response.data.meta.last_page;
    } catch (error) {
        console.error('Error fetching replies:', error);
    } finally {
        loading.value = false;
    }
};

const buildReplyPayload = (reply) => {
    const payload = {
        uuid: props.contactUuid,
        message: null,
        type: 'text',
        header: null,
        footer: null,
        buttons: [],
        button_label: null,
    };

    const content = reply.content || {};

    if (reply.type === 'text') {
        payload.message = content.body;
    } else if (reply.type === 'quick_reply') {
        payload.type = 'interactive buttons';
        payload.message = content.body;
        payload.buttons = (content.buttons || []).map((button, index) => ({
            id: `btn_${Date.now()}_${index}`,
            title: button.text,
        }));
    } else if (reply.type === 'header_footer') {
        payload.type = 'interactive buttons';
        payload.message = content.body;
        if (content.header) {
            payload.header = { type: 'text', text: content.header };
        }
        if (content.footer) {
            payload.footer = content.footer;
        }
        payload.buttons = (content.buttons || []).map((button, index) => ({
            id: `btn_${Date.now()}_${index}`,
            title: button.text,
        }));
    } else if (reply.type === 'link') {
        payload.type = 'interactive call to action url';
        payload.message = content.body;
        payload.buttons = {
            display_text: content.link_text,
            url: content.link_url,
        };
    }

    return payload;
};

const selectReply = (reply) => {
    selectedReply.value = reply;
    selectedPayload.value = buildReplyPayload(reply);
};

const sendSelectedReply = () => {
    if (!selectedPayload.value) {
        return;
    }

    emit('send', selectedPayload.value);
    emit('close');
};

const handleSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => fetchReplies(1), 250);
};

const clearSearch = () => {
    search.value = '';
    fetchReplies(1);
};

const replyPreview = (reply) => {
    const content = reply.content || {};

    return content.body || content.link_text || content.link_url || '';
};

const nextPage = () => {
    if (page.value < lastPage.value) {
        fetchReplies(page.value + 1);
    }
};

const prevPage = () => {
    if (page.value > 1) {
        fetchReplies(page.value - 1);
    }
};

watch(() => props.show, (newValue) => {
    if (newValue) {
        selectedReply.value = null;
        selectedPayload.value = null;
        fetchReplies();
    }
});

onBeforeUnmount(() => clearTimeout(searchTimeout));
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6"
        aria-labelledby="modal-title"
        role="dialog"
        aria-modal="true"
    >
        <div
            class="absolute inset-0 bg-slate-950/45 backdrop-blur-[2px]"
            aria-hidden="true"
            @click="$emit('close')"
        ></div>

        <section class="relative flex max-h-[min(680px,calc(100vh-2rem))] w-full max-w-xl flex-col overflow-hidden rounded-2xl bg-white text-left shadow-2xl ring-1 ring-black/5">
            <header class="flex items-start justify-between gap-4 border-b border-slate-100 px-6 py-5">
                <div class="flex min-w-0 items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5m7-2a8 8 0 0 1-8 8 8.8 8.8 0 0 1-3.6-.76L4 20l.76-4.4A8 8 0 1 1 20 12Z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h3 id="modal-title" class="truncate text-lg font-semibold tracking-tight text-slate-900">
                            {{ $t('Select a Reply') }}
                        </h3>
                        <p class="mt-0.5 text-xs text-slate-500">Choose a saved response to send</p>
                    </div>
                </div>

                <button
                    type="button"
                    class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    aria-label="Close"
                    @click="$emit('close')"
                >
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" />
                    </svg>
                </button>
            </header>

            <div class="flex min-h-0 flex-1 flex-col px-6 py-5">
                <div class="relative">
                    <label for="reply-search" class="sr-only">{{ $t('Search replies...') }}</label>
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="11" cy="11" r="7" />
                        <path stroke-linecap="round" d="m20 20-4-4" />
                    </svg>
                    <input
                        id="reply-search"
                        v-model="search"
                        type="search"
                        autocomplete="off"
                        :placeholder="$t('Search replies...')"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2.5 pl-10 pr-10 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 hover:border-slate-300 focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-500/10"
                        @input="handleSearch"
                    >
                    <button
                        v-if="search"
                        type="button"
                        class="absolute right-2 top-1/2 inline-flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-md text-slate-400 hover:bg-slate-200 hover:text-slate-700"
                        aria-label="Clear search"
                        @click="clearSearch"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" />
                        </svg>
                    </button>
                </div>

                <div class="mt-4 min-h-0 flex-1">
                    <div v-if="loading" class="space-y-2" aria-live="polite" aria-busy="true">
                        <div v-for="item in 3" :key="item" class="flex animate-pulse items-center gap-3 rounded-xl border border-slate-100 p-3">
                            <div class="h-10 w-10 rounded-lg bg-slate-100"></div>
                            <div class="flex-1 space-y-2">
                                <div class="h-3 w-2/5 rounded bg-slate-100"></div>
                                <div class="h-2.5 w-4/5 rounded bg-slate-100"></div>
                            </div>
                        </div>
                    </div>

                    <div v-else-if="replies.length === 0" class="flex min-h-40 flex-col items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50 px-6 text-center">
                        <div class="mb-3 flex h-11 w-11 items-center justify-center rounded-full bg-white text-slate-400 shadow-sm ring-1 ring-slate-100">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5m7-2a8 8 0 0 1-8 8 8.8 8.8 0 0 1-3.6-.76L4 20l.76-4.4A8 8 0 1 1 20 12Z" />
                            </svg>
                        </div>
                        <p class="text-sm font-medium text-slate-700">{{ $t('No replies found.') }}</p>
                        <p class="mt-1 text-xs text-slate-400">Try a different search term</p>
                    </div>

                    <ul v-else class="max-h-[min(390px,calc(100vh-280px))] space-y-2 overflow-y-auto pr-1" aria-label="Saved replies">
                        <li v-for="reply in replies" :key="reply.uuid">
                            <button
                                type="button"
                                class="group flex w-full items-center gap-3 rounded-xl border p-3 text-left transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1"
                                :class="selectedReply?.uuid === reply.uuid ? 'border-indigo-400 bg-indigo-50 shadow-sm ring-1 ring-indigo-200' : 'border-slate-200 bg-white hover:border-indigo-200 hover:bg-indigo-50/40 hover:shadow-sm'"
                                @click="selectReply(reply)"
                            >
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 transition group-hover:bg-indigo-100 group-hover:text-indigo-600">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5m7-2a8 8 0 0 1-8 8 8.8 8.8 0 0 1-3.6-.76L4 20l.76-4.4A8 8 0 1 1 20 12Z" />
                                    </svg>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center justify-between gap-3">
                                        <span class="truncate text-sm font-semibold text-slate-800">{{ reply.title }}</span>
                                        <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium capitalize text-slate-500">{{ reply.type?.replace('_', ' ') }}</span>
                                    </span>
                                    <span class="mt-1 block truncate text-xs text-slate-500">{{ replyPreview(reply) }}</span>
                                </span>
                                <svg class="h-4 w-4 shrink-0 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-indigo-500 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
                                </svg>
                            </button>
                        </li>
                    </ul>
                </div>

                <div v-if="lastPage > 1" class="mt-4 flex items-center justify-between border-t border-slate-100 pt-4">
                    <button type="button" @click="prevPage" :disabled="page === 1" class="rounded-lg px-3 py-2 text-xs font-medium text-indigo-600 transition hover:bg-indigo-50 disabled:cursor-not-allowed disabled:text-slate-300">{{ $t('Previous') }}</button>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-500">{{ page }} / {{ lastPage }}</span>
                    <button type="button" @click="nextPage" :disabled="page === lastPage" class="rounded-lg px-3 py-2 text-xs font-medium text-indigo-600 transition hover:bg-indigo-50 disabled:cursor-not-allowed disabled:text-slate-300">{{ $t('Next') }}</button>
                </div>
            </div>

            <footer class="flex items-center justify-between gap-3 border-t border-slate-100 bg-slate-50 px-6 py-4">
                <p v-if="selectedReply" class="min-w-0 truncate text-xs text-slate-500">
                    Selected: <span class="font-medium text-slate-700">{{ selectedReply.title }}</span>
                </p>
                <span v-else></span>
                <div class="flex shrink-0 items-center gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2" @click="$emit('close')">
                        {{ $t('Cancel') }}
                    </button>
                    <button type="button" :disabled="!selectedPayload" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-slate-300" @click="sendSelectedReply">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m22 2-7 20-4-9-9-4 20-7Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M22 2 11 13" />
                        </svg>
                        {{ $t('Send') }}
                    </button>
                </div>
            </footer>
        </section>
    </div>
</template>
