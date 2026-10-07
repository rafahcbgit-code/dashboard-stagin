<script setup>
import { nextTick, onUnmounted, ref, watch } from 'vue';
import { default as axios } from 'axios';
import ChatBubble from '@/Components/ChatComponents/ChatBubble.vue';

const props = defineProps({
    contactId: {
        type: Number,
        required: true
    },
    contactUuid: { type: String, required: true },
    searchTarget: { type: Object, default: null },
    initialMessages: {
        type: Array,
        required: true
    },
    hasMoreMessages: {
        type: Boolean,
        required: true
    },
    initialNextPage: {
        type: Number,
        required: true
    }
});

const emit = defineEmits(['reply-selected']);

const messages = ref(props.initialMessages);
const loading = ref(false);
const nextPage = ref(props.initialNextPage);
const hasMore = ref(props.hasMoreMessages);
const messageElements = ref({});
const highlightedWamId = ref(null);
const highlightedChatId = ref(null);
const windowMode = ref(false);
const windowHasOlder = ref(false);
const windowHasNewer = ref(false);
const windowError = ref(false);
const windowLoading = ref(false);
let windowRequestId = 0;
let windowController = null;
let windowHighlightTimer = null;
let highlightTimer = null;

const parseMetadata = (metadata) => {
    try {
        if (!metadata) return {};
        return typeof metadata === 'string' ? JSON.parse(metadata) : metadata;
    } catch {
        return {};
    }
};

const messagePreview = (chat) => {
    if (!chat) return '';

    const metadata = parseMetadata(chat.metadata);
    const type = metadata.type || '';

    return metadata.text?.body
        || metadata.body?.text
        || metadata.image?.caption
        || metadata.video?.caption
        || metadata.document?.caption
        || metadata.audio?.caption
        || metadata.button?.text
        || metadata.interactive?.body?.text
        || metadata.interactive?.button_reply?.title
        || metadata.interactive?.list_reply?.title
        || (type === 'location' ? 'Location' : '')
        || (type === 'contacts' ? 'Contact' : '')
        || (type === 'image' ? 'Image' : '')
        || (type === 'video' ? 'Video' : '')
        || (type === 'audio' ? 'Audio' : '')
        || (type === 'document' ? 'Document' : '')
        || (type === 'sticker' ? 'Sticker' : '')
        || (type ? type.charAt(0).toUpperCase() + type.slice(1) : 'Message');
};

const getReplyTarget = (chat) => {
    const value = chat?.[0]?.value;
    const relatedReply = value?.reply_to;

    if (relatedReply) {
        return {
            id: relatedReply.id,
            wam_id: relatedReply.wam_id,
            type: relatedReply.type || 'inbound',
            text: relatedReply.text || messagePreview(relatedReply),
            found: true,
        };
    }

    const replyWamId = parseMetadata(value?.metadata)?.context?.id;

    if (!replyWamId) return null;

    const original = messages.value.find((item) => (
        item?.[0]?.type === 'chat'
        && item?.[0]?.value?.wam_id === replyWamId
    ))?.[0]?.value;

    return {
        wam_id: replyWamId,
        type: original?.type || 'inbound',
        text: messagePreview(original),
        found: Boolean(original),
    };
};

const selectReplyMessage = (chat) => {
    emit('reply-selected', {
        id: chat.id,
        type: chat.type,
        message_type: parseMetadata(chat.metadata).type || 'text',
        text: messagePreview(chat),
    });
};

const messageKey = (chat, index) => {
    const value = chat?.[0]?.value;
    const stableId = value?.wam_id || value?.id;

    return stableId ? `message-${stableId}` : `row-${index}`;
};

const setMessageElement = (key, element) => {
    if (element) {
        messageElements.value[key] = element;
    } else {
        delete messageElements.value[key];
    }
};

const restoreLatest = () => {
    windowRequestId++;
    windowController?.abort();
    windowMode.value = false;
    windowLoading.value = false;
    windowError.value = false;
    messages.value = props.initialMessages;
    hasMore.value = props.hasMoreMessages;
    nextPage.value = props.initialNextPage;
    highlightedChatId.value = null;
    clearTimeout(windowHighlightTimer);
};

const openSearchResult = async (target) => {
    const current = ++windowRequestId;
    windowController?.abort();
    windowController = new AbortController();
    windowError.value = false;
    windowLoading.value = true;
    try {
        const response = await axios.get(`/chats/${encodeURIComponent(props.contactUuid)}/messages/around/${target.log_id}`, { signal: windowController.signal });
        if (current !== windowRequestId) return;
        windowMode.value = true;
        messages.value = response.data.messages;
        windowHasOlder.value = response.data.hasOlder;
        windowHasNewer.value = response.data.hasNewer;
        highlightedChatId.value = target.chat_id;
        clearTimeout(windowHighlightTimer);
        windowHighlightTimer = setTimeout(() => { highlightedChatId.value = null; }, 3200);
        await nextTick();
        const item = messages.value.find((row) => row?.[0]?.value?.id === target.chat_id);
        const key = item && messageKey(item, messages.value.indexOf(item));
        messageElements.value[key]?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } catch (error) {
        if (current === windowRequestId && error.code !== 'ERR_CANCELED') windowError.value = true;
    } finally {
        if (current === windowRequestId) windowLoading.value = false;
    }
};

const extendWindow = async (direction) => {
    if (loading.value || !messages.value.length) return;
    const edge = direction === 'older' ? messages.value[0] : messages.value[messages.value.length - 1];
    const logId = edge?.[0]?.log_id;
    if (!logId) return;
    loading.value = true;
    const current = windowRequestId;
    windowController?.abort();
    windowController = new AbortController();
    try {
        const response = await axios.get(`/chats/${encodeURIComponent(props.contactUuid)}/messages/around/${logId}`, { signal: windowController.signal });
        if (current !== windowRequestId) return;
        const viewport = direction === 'older' ? document.querySelector('[data-chat-scroll-container]') : null;
        const previousHeight = viewport?.scrollHeight ?? 0;
        const previousTop = viewport?.scrollTop ?? 0;
        const existing = new Set(messages.value.map((row) => row?.[0]?.log_id));
        const additional = response.data.messages.filter((row) => !existing.has(row?.[0]?.log_id));
        messages.value = direction === 'older' ? [...additional, ...messages.value] : [...messages.value, ...additional];
        if (direction === 'older') windowHasOlder.value = response.data.hasOlder;
        else windowHasNewer.value = response.data.hasNewer;
        if (viewport) {
            await nextTick();
            viewport.scrollTop = previousTop + viewport.scrollHeight - previousHeight;
        }
    } catch (error) {
        if (current === windowRequestId && error.code !== 'ERR_CANCELED') windowError.value = true;
    } finally {
        loading.value = false;
    }
};

watch(() => props.searchTarget, (target) => target ? openSearchResult(target) : restoreLatest(), { immediate: true });
watch(() => props.contactId, restoreLatest);
onUnmounted(() => {
    windowController?.abort();
    clearTimeout(windowHighlightTimer);
    clearTimeout(highlightTimer);
});

const findMessageByWamId = (wamId) => messages.value.find((item) => (
    item?.[0]?.type === 'chat'
    && item?.[0]?.value?.wam_id === wamId
));

const highlightMessage = (wamId) => {
    highlightedWamId.value = wamId;

    if (highlightTimer) {
        clearTimeout(highlightTimer);
    }

    highlightTimer = setTimeout(() => {
        highlightedWamId.value = null;
        highlightTimer = null;
    }, 2200);
};

const scrollToReply = async (wamId) => {
    let target = findMessageByWamId(wamId);

    if (!target && windowMode.value) {
        const current = ++windowRequestId;
        windowController?.abort();
        windowController = new AbortController();
        windowError.value = false;
        try {
            const response = await axios.get(`/chats/${encodeURIComponent(props.contactUuid)}/messages/around-reply`, {
                params: { wam_id: wamId }, signal: windowController.signal,
            });
            if (current !== windowRequestId) return;
            messages.value = response.data.messages;
            windowHasOlder.value = response.data.hasOlder;
            windowHasNewer.value = response.data.hasNewer;
            target = findMessageByWamId(wamId);
        } catch (error) {
            if (current === windowRequestId && error.code !== 'ERR_CANCELED') windowError.value = true;
            return;
        }
    }

    // The original message may be on an older page. Load pages lazily only
    // when the user asks to open that reply, keeping the initial UI fast.
    while (!target && !windowMode.value && hasMore.value) {
        const previousCount = messages.value.length;
        const previousPage = nextPage.value;
        const loaded = await loadMoreMessages();

        target = findMessageByWamId(wamId);

        if (!loaded || (previousCount === messages.value.length && previousPage === nextPage.value)) {
            break;
        }
    }

    if (!target) return;

    await nextTick();

    const key = messageKey(target, messages.value.indexOf(target));
    const element = messageElements.value[key];

    if (!element) return;

    element.scrollIntoView({ behavior: 'smooth', block: 'center' });
    highlightMessage(wamId);
};

watch(
    () => props.initialMessages,
    (newMessages) => {
        if (!windowMode.value) messages.value = newMessages;
    },
    { deep: true }
);

watch(
    () => props.hasMoreMessages,
    (value) => {
        hasMore.value = value;
    }
);

watch(
    () => props.initialNextPage,
    (value) => {
        nextPage.value = value;
    }
);

const loadMoreMessages = async () => {
    if (loading.value || !hasMore.value || windowMode.value) return false;
    
    loading.value = true;
    try {
        const response = await axios.get(`/chats/${props.contactId}/messages?page=${nextPage.value}`);
        messages.value = [...response.data.messages, ...messages.value];
        hasMore.value = response.data.hasMoreMessages;
        nextPage.value = response.data.nextPage;
        return true;
    } catch (error) {
        console.error('Error loading messages:', error);
        return false;
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <div class="relative px-3 py-4 sm:px-6 md:px-10">
        <p v-if="windowLoading" class="mb-2 text-center text-xs text-slate-600" role="status">{{ $t('Opening search result') }}</p>
        <div v-if="windowMode && windowHasOlder" class="mb-3 text-center">
            <button type="button" class="min-h-9 rounded-lg border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-50" :disabled="loading" @click="extendWindow('older')">{{ $t('Older messages') }}</button>
        </div>
        <p v-if="windowError" role="alert" class="mb-2 text-center text-sm text-red-700">{{ $t('Could not open this message') }}</p>
        <div v-if="hasMore && !windowMode"
             class="text-center py-2">
            <div v-if="loading" 
                 class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150">
                <svg class="animate-spin me-3 h-5 w-5 text-gray-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                {{ $t('Loading...') }}
            </div>
            <button v-else
                    @click="loadMoreMessages"
                    class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150">
                <svg class="me-2 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                </svg>
                {{ $t('Load More Messages') }}
            </button>
        </div>
        
        <div v-for="(chat, index) in messages"
             :key="messageKey(chat, index)"
             :ref="(element) => setMessageElement(messageKey(chat, index), element)"
             class="flex flex-grow flex-col"
             :class="[
                 chat[0].type === 'ticket' ? 'justify-center' : 'justify-end',
                 (highlightedWamId && highlightedWamId === chat?.[0]?.value?.wam_id)
                     || (windowMode && highlightedChatId === chat?.[0]?.value?.id)
                     ? 'rounded-lg bg-[#e9fff7] ring-2 ring-[#00a884] transition duration-300'
                     : ''
             ]">
            <ChatBubble 
               v-if="chat[0].type === 'chat'"
               :content="chat[0].value"
                :type="chat[0].value.type"
                :reply-to="getReplyTarget(chat)"
                @reply-click="scrollToReply"
                @reply-select="selectReplyMessage" />
            <div v-if="chat[0].type === 'ticket'" class="py-2">
                <div class="text-center font-light text-sm border-b border-t py-2 border-dashed border-black">
                    <div>{{ chat[0].value.description }} </div>
                    <div class="text-xs">{{ chat[0].value.created_at }}</div>
                </div>
            </div>
            <div v-if="chat[0].type === 'notes'" class="py-2 !bg-[#152638] !text-white my-2 rounded-lg p-2 w-[fit-content] ml-auto"  >
                <div class="text-right font-light text-sm">
                    <div>{{ chat[0].value.content }}</div>
                    <div class="flex items-center justify-between mt-2 space-x-4">
                        <!--<span v-if="props.type === 'outbound' && content.user" class="text-gray-500 text-xs text-right leading-none">Sent By: {{ content.user?.first_name + ' ' + content.user?.last_name }}</span>-->
                        <p class="!text-white text-xs text-right leading-none">{{ chat[0].value.created_at }}</p>
                    </div>
                </div>
            </div>
        </div>
        <div v-if="windowMode && windowHasNewer" class="mt-3 text-center">
            <button type="button" class="rounded border bg-white px-3 py-1.5 text-sm" :disabled="loading" @click="extendWindow('newer')">{{ $t('Newer messages') }}</button>
        </div>
    </div>
</template>
