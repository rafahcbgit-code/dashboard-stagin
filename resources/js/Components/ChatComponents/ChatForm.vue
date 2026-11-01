<script setup>
import axios from 'axios';
import { ref, watch, watchEffect, computed, onMounted, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { getEchoInstance } from '../../echo';
import EmojiPicker from 'vue3-emoji-picker';
import 'vue3-emoji-picker/css';
import MicRecorder from 'mic-recorder-to-mp3-fixed';
import PreCreatedRepliesModal from './PreCreatedRepliesModal.vue';
import MetaFlowPickerModal from './MetaFlowPickerModal.vue';

const recorder = ref(null);
const props = defineProps([
    'contact',
    'chatThread',
    'chatLimitReached',
    'simpleForm',
    'pusherSettings',
    'replyingTo',
]);
const isContactBlocked = computed(() => Boolean(props.contact?.is_blocked));

const showMetaFlowModal = ref(false);
const metaFlowsModule   = computed(() => usePage().props.meta_flows_module ?? false);

const processingForm = ref(null);
const processingAISuggestions = ref(false);
const outboundError = ref(null);
const formTextInput = ref(null);
const showRepliesModal = ref(false);
const isRecording = ref(false);
const mediaRecorder = ref(null);
const audioChunks = ref([]);
const recordingTime = ref(0);
const timerInterval = ref(null);
const playbackTime = ref(0);
const playbackInterval = ref(null);
const audioDuration = ref(0);
const audioPreviewUrl = ref(null);
const isPlaying = ref(false);
const audioPlayer = ref(null);
const fileInputRef = ref(null);
const pendingMediaPreviewUrl = ref(null);
const isAudioRecording = ref(false);
const form = ref({
    'uuid': props.contact.uuid,
    'message': null,
    'type': null,
    'file': null
})
const form2 = ref({
    'uuid': props.contact.uuid,
    'message': null,
    'type': null,
    'file': null
})
const emojiPicker = ref(false);
const emojiPickerRef = ref(null);

const typingChannel = ref(null);
const typingChannelName = ref(null);
const typingEntries = ref(new Map());
const presenceMembers = ref(new Map());
const typingExpiryTimers = new Map();
const typingStopTimer = ref(null);
const typingHeartbeatTimer = ref(null);
const isTyping = ref(false);
const typingTabId = typeof crypto !== 'undefined' && crypto.randomUUID
    ? crypto.randomUUID()
    : Math.random().toString(36).slice(2);
const typingExpiryMs = 4500;
const typingIdleMs = 2500;
const typingHeartbeatMs = 2000;
const replyWindowNow = ref(Date.now());
const replyWindowTimeout = ref(null);
const showReplyWindowHelp = ref(false);

const currentUser = computed(() => usePage().props.auth?.user);
const currentUserId = computed(() => Number(currentUser.value?.id || 0));
const typingAgentNames = computed(() => {
    const namesByUser = new Map();

    typingEntries.value.forEach((entry) => {
        const userId = Number(entry.userId || 0);

        if (!userId || userId === currentUserId.value || namesByUser.has(userId)) {
            return;
        }

        namesByUser.set(userId, entry.userName || 'An agent');
    });

    return Array.from(namesByUser.values());
});

const typingIndicatorText = computed(() => {
    const names = typingAgentNames.value;

    if (names.length === 0) {
        return '';
    }

    if (names.length === 1) {
        return `${names[0]} is typing...`;
    }

    if (names.length === 2) {
        return `${names[0]} and ${names[1]} are typing...`;
    }

    return `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]} are typing...`;
});

watchEffect(() => {
    form.value.uuid = props.contact.uuid;
});

const emit = defineEmits(['response', 'viewTemplate', 'reply-cleared']);

const messageFromError = (error) => {
    return error?.response?.data?.message
        || error?.response?.data?.errors?.contacts?.[0]
        || error?.response?.data?.errors?.message?.[0]
        || 'Unable to send message.';
};

const viewTemplate = () => {
    if (isContactBlocked.value) {
        return;
    }

    emit('viewTemplate', true);
}

const replaceTypingEntries = (entries) => {
    typingEntries.value = new Map(entries);
};

const clearTypingExpiry = (entryKey) => {
    const timer = typingExpiryTimers.get(entryKey);

    if (timer) {
        clearTimeout(timer);
        typingExpiryTimers.delete(entryKey);
    }
};

const removeTypingEntry = (entryKey) => {
    clearTypingExpiry(entryKey);

    if (!typingEntries.value.has(entryKey)) {
        return;
    }

    const entries = new Map(typingEntries.value);
    entries.delete(entryKey);
    replaceTypingEntries(entries);
};

const removeTypingEntriesForUser = (userId) => {
    const entries = new Map(typingEntries.value);
    let changed = false;

    entries.forEach((entry, entryKey) => {
        if (Number(entry.userId || 0) === Number(userId)) {
            clearTypingExpiry(entryKey);
            entries.delete(entryKey);
            changed = true;
        }
    });

    if (changed) {
        replaceTypingEntries(entries);
    }
};

const scheduleTypingExpiry = (entryKey) => {
    clearTypingExpiry(entryKey);

    typingExpiryTimers.set(entryKey, setTimeout(() => {
        removeTypingEntry(entryKey);
    }, typingExpiryMs));
};

const handleTypingWhisper = (payload = {}) => {
    const userId = Number(payload.userId || 0);

    if (!userId || userId === currentUserId.value) {
        return;
    }

    const tabId = payload.tabId || 'unknown-tab';
    const entryKey = `${userId}:${tabId}`;

    if (payload.action === 'stop') {
        removeTypingEntry(entryKey);
        return;
    }

    const entries = new Map(typingEntries.value);
    entries.set(entryKey, {
        userId,
        userName: presenceMembers.value.get(userId) || 'An agent',
    });
    replaceTypingEntries(entries);
    scheduleTypingExpiry(entryKey);
};

const sendTypingWhisper = (action) => {
    if (!typingChannel.value) {
        return;
    }

    typingChannel.value.whisper('typing', {
        action,
        userId: currentUserId.value,
        tabId: typingTabId,
    });
};

const clearTypingTimers = () => {
    if (typingStopTimer.value) {
        clearTimeout(typingStopTimer.value);
        typingStopTimer.value = null;
    }

    if (typingHeartbeatTimer.value) {
        clearInterval(typingHeartbeatTimer.value);
        typingHeartbeatTimer.value = null;
    }
};

const stopTyping = () => {
    clearTypingTimers();

    if (isTyping.value) {
        sendTypingWhisper('stop');
    }

    isTyping.value = false;
};

const startTyping = () => {
    if (!typingChannel.value || !currentUserId.value) {
        return;
    }

    if (!isTyping.value) {
        isTyping.value = true;
        sendTypingWhisper('start');
    }

    if (!typingHeartbeatTimer.value) {
        typingHeartbeatTimer.value = setInterval(() => {
            if (isTyping.value) {
                sendTypingWhisper('start');
            }
        }, typingHeartbeatMs);
    }

    if (typingStopTimer.value) {
        clearTimeout(typingStopTimer.value);
    }

    typingStopTimer.value = setTimeout(stopTyping, typingIdleMs);
};

const handleComposerInput = (event) => {
    adjustTextareaHeight();

    if (event.target.value && event.target.value.trim() !== '') {
        startTyping();
    } else {
        stopTyping();
    }
};

const leaveTypingChannel = () => {
    stopTyping();

    if (typingChannel.value) {
        typingChannel.value.stopListeningForWhisper('typing');
    }

    if (typingChannelName.value) {
        const echo = getEchoInstance(
            props.pusherSettings?.pusher_app_key,
            props.pusherSettings?.pusher_app_cluster
        );
        echo.leave(typingChannelName.value);
    }

    typingChannel.value = null;
    typingChannelName.value = null;
    typingExpiryTimers.forEach((timer) => clearTimeout(timer));
    typingExpiryTimers.clear();
    replaceTypingEntries([]);
    presenceMembers.value = new Map();
};

const normalizePresenceMember = (member) => ({
    id: Number(member?.id || member?.user_id || member?.userId || member?.info?.id || 0),
    name: member?.name || member?.info?.name || 'An agent',
});

const joinTypingChannel = () => {
    const pusherKey = props.pusherSettings?.pusher_app_key;
    const pusherCluster = props.pusherSettings?.pusher_app_cluster;
    const contactUuid = props.contact?.uuid;

    if (!pusherKey || !pusherCluster || !contactUuid || !currentUserId.value) {
        return;
    }

    const echo = getEchoInstance(pusherKey, pusherCluster);
    const channelName = `conversation.${contactUuid}`;
    const channel = echo.join(channelName);

    typingChannel.value = channel;
    typingChannelName.value = channelName;

    channel.listenForWhisper('typing', handleTypingWhisper);
    channel.here((members) => {
        const nextMembers = new Map();

        members.forEach((member) => {
            const normalizedMember = normalizePresenceMember(member);

            if (normalizedMember.id) {
                nextMembers.set(normalizedMember.id, normalizedMember.name);
            }
        });

        presenceMembers.value = nextMembers;

        if (formTextInput.value && formTextInput.value.trim() !== '') {
            startTyping();
        }
    });
    channel.joining((member) => {
        const normalizedMember = normalizePresenceMember(member);

        if (normalizedMember.id) {
            const members = new Map(presenceMembers.value);
            members.set(normalizedMember.id, normalizedMember.name);
            presenceMembers.value = members;
        }
    });
    channel.leaving((member) => {
        const normalizedMember = normalizePresenceMember(member);

        if (normalizedMember.id) {
            const members = new Map(presenceMembers.value);
            members.delete(normalizedMember.id);
            presenceMembers.value = members;
            removeTypingEntriesForUser(normalizedMember.id);
        }
    });
};

watch(
    () => props.contact?.uuid,
    (newUuid, oldUuid) => {
        if (!newUuid || newUuid === oldUuid) {
            return;
        }

        leaveTypingChannel();
        joinTypingChannel();
    }
);

const sendMessage = async () => {
    stopTyping();

    if (isContactBlocked.value) {
        processingForm.value = false;
        return;
    }

    form.value.message = formTextInput.value;

    const hasText = Boolean(form.value.message?.trim());
    const hasMedia = Boolean(form.value.file || form2.value.file);

    if (!hasText && !hasMedia) {
        processingForm.value = false;
        return;
    }

    processingForm.value = true;
    outboundError.value = null;

    if (hasText || form.value.file) {
        const formData = new FormData();
        // A caption must keep the selected media type, while a message with no
        // attachment must always be sent as text.
        const messageType = form.value.file ? form.value.type : 'text';

        formData.append('message', form.value.message ?? '');
        formData.append('type', messageType);
        formData.append('uuid', form.value.uuid);

        if (form.value.file) {
            formData.append('file', form.value.file);
        }

        if (props.replyingTo?.id && messageType === 'text') {
            formData.append('reply_to_message_id', props.replyingTo.id);
        }

        try {
            const response = await axios.post('/chats', formData);

            if (form2.value.file) {
                await sendAudioMessage();
            }

            form.value.message = null;
            formTextInput.value = null;
            clearSelectedFile();
            emit('reply-cleared');

            processingForm.value = false;
        } catch (error) {
            outboundError.value = messageFromError(error);
            processingForm.value = false;
        }
    } else {
        if (form2.value.file) {
            await sendAudioMessage();
        }

        processingForm.value = false;
    }
}

const sendPreCreatedReply = async (payload) => {
    stopTyping();

    if (isContactBlocked.value) {
        processingForm.value = false;
        return;
    }

    processingForm.value = true;
    outboundError.value = null;
    try {
        await axios.post('/chats', payload);
        
        // Reset form if needed or show success
        processingForm.value = false;
    } catch (error) {
        outboundError.value = messageFromError(error);
        processingForm.value = false;
    }
}

const sendAudioMessage = async () => {
    stopTyping();

    if (isContactBlocked.value) {
        return;
    }

    if (!form2.value.file) {
        return;
    }

    const formData = new FormData();
    formData.append('type', form2.value.type);
    formData.append('uuid', form2.value.uuid);

    if (form2.value.file) {
        formData.append('file', form2.value.file);
    }

    try {
        const response = await axios.post('/chats', formData);

        deleteRecording();
    } catch (error) {
        outboundError.value = messageFromError(error);
    }
}

const textInputRef = ref(null);
const adjustTextareaHeight = () => {
    const textInput = textInputRef.value;
    textInput.style.height = 'auto';
    textInput.style.height = textInput.scrollHeight + 'px';
};

const handleEnterKey = (event) => {
    if (isContactBlocked.value) {
        return;
    }

    if (formTextInput.value != null && formTextInput.value.trim() != '') {
        sendMessage();
    }
};

const latestInboundChatTimeFromThread = () => {
    if (!Array.isArray(props.chatThread)) {
        return null;
    }

    let latestTime = null;

    props.chatThread.forEach((item) => {
        const chat = item?.[0]?.value;

        if (!chat || chat.type !== 'inbound') {
            return;
        }

        // `metadata.timestamp` comes from the WhatsApp payload and can be
        // stale when a webhook is replayed or when the provider payload is
        // reused. The Chat record's created_at is the timestamp assigned by
        // this application when the inbound message is actually stored, so it
        // is the authoritative value for the local 24-hour window.
        const candidate = new Date(chat.created_at);

        if (!Number.isNaN(candidate.getTime()) && (!latestTime || candidate > latestTime)) {
            latestTime = candidate;
        }
    });

    return latestTime;
};

const latestInboundAt = computed(() => {
    const threadTimestamp = latestInboundChatTimeFromThread();

    if (threadTimestamp) {
        return threadTimestamp;
    }

    const contactTimestamp = props.contact?.last_inbound_chat_at || props.contact?.last_inbound_chat?.created_at;
    if (!contactTimestamp) {
        return null;
    }

    const parsed = new Date(contactTimestamp);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
});

const replyWindowExpiresAt = computed(() => {
    const serverExpiry = props.contact?.reply_window_expires_at;
    let parsedServerExpiry = null;
    if (serverExpiry) {
        const parsed = new Date(serverExpiry);
        if (!Number.isNaN(parsed.getTime())) {
            parsedServerExpiry = parsed;
        }
    }

    const inferredExpiry = latestInboundAt.value
        ? new Date(latestInboundAt.value.getTime() + (24 * 60 * 60 * 1000))
        : null;

    if (parsedServerExpiry && inferredExpiry) {
        return inferredExpiry > parsedServerExpiry ? inferredExpiry : parsedServerExpiry;
    }

    return inferredExpiry || parsedServerExpiry;
});

const replyWindowRemainingMs = computed(() => replyWindowExpiresAt.value
    ? Math.max(0, replyWindowExpiresAt.value.getTime() - replyWindowNow.value)
    : 0);
const replyWindowRemainingMinutes = computed(() => Math.ceil(replyWindowRemainingMs.value / 60000));
const replyWindowHours = computed(() => Math.floor(replyWindowRemainingMinutes.value / 60));
const replyWindowMinutes = computed(() => replyWindowRemainingMinutes.value % 60);
const replyWindowElapsedPercent = computed(() => {
    if (!replyWindowExpiresAt.value) return 0;

    const elapsed = replyWindowNow.value - (replyWindowExpiresAt.value.getTime() - (24 * 60 * 60 * 1000));
    return Math.min(100, Math.max(0, Math.round((elapsed / (24 * 60 * 60 * 1000)) * 100)));
});
const isInboundChatWithin24Hours = computed(() => {
    if (replyWindowExpiresAt.value) {
        return replyWindowExpiresAt.value.getTime() > replyWindowNow.value;
    }

    return typeof props.contact?.can_send_session_message === 'boolean'
        ? props.contact.can_send_session_message
        : false;
});
const isReplyWindowExpiringSoon = computed(() => replyWindowRemainingMs.value <= (4 * 60 * 60 * 1000));

const scheduleReplyWindowClock = () => {
    if (replyWindowTimeout.value) {
        clearTimeout(replyWindowTimeout.value);
        replyWindowTimeout.value = null;
    }

    replyWindowNow.value = Date.now();
    const expiresAt = replyWindowExpiresAt.value?.getTime();
    if (!expiresAt || expiresAt <= replyWindowNow.value) {
        return;
    }

    const remaining = expiresAt - replyWindowNow.value;
    const untilNextDisplayedMinute = remaining % 60000 || 60000;
    replyWindowTimeout.value = setTimeout(scheduleReplyWindowClock, Math.min(remaining, untilNextDisplayedMinute));
};

watch(() => replyWindowExpiresAt.value?.getTime() ?? null, scheduleReplyWindowClock);

const handleFileUpload = (event) => {
    if (isContactBlocked.value) {
        return;
    }

    const file = event.target.files[0];
    if (!file) {
        return;
    }

    // Selecting a file only prepares it in the composer. The form is sent
    // explicitly by the user through the Send button.
    form.value.file = file;
    updatePendingMediaPreview(file);
};

const updatePendingMediaPreview = (file) => {
    clearPendingMediaPreview();

    if (file?.type?.startsWith('image/') || file?.type?.startsWith('video/')) {
        pendingMediaPreviewUrl.value = URL.createObjectURL(file);
    }
};

const clearPendingMediaPreview = () => {
    if (pendingMediaPreviewUrl.value) {
        URL.revokeObjectURL(pendingMediaPreviewUrl.value);
        pendingMediaPreviewUrl.value = null;
    }
};

const formatFileSize = (bytes) => {
    if (!Number.isFinite(bytes) || bytes <= 0) {
        return '';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    const unitIndex = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    const size = bytes / (1024 ** unitIndex);

    return `${size >= 10 || unitIndex === 0 ? Math.round(size) : size.toFixed(1)} ${units[unitIndex]}`;
};

const selectMediaType = (type) => {
    form.value.type = type;
    form.value.file = null;
    clearPendingMediaPreview();

    if (fileInputRef.value) {
        fileInputRef.value.value = '';
    }
};

const clearSelectedFile = () => {
    form.value.file = null;
    form.value.type = null;
    clearPendingMediaPreview();

    if (fileInputRef.value) {
        fileInputRef.value.value = '';
    }
};

const hasPendingContent = computed(() => Boolean(
    formTextInput.value?.trim()
    || form.value.file
    || form2.value.file
));

const getAcceptedFileTypes = () => {
    switch (form.value.type) {
        case 'image':
            return '.jpg, .jpeg, .png';
        case 'document':
            return '.txt, .pdf, .ppt, .doc, .xls, .docx, .pptx, .xlsx';
        case 'audio':
            return '.mp3, .ogg';
        case 'video':
            return '.mp4';
        default:
            return ''; // Empty string allows all file types
    }
}

const toggleEmojiPicker = (e) => {
    e.stopPropagation();
    emojiPicker.value = !emojiPicker.value;
};

const closeEmojiPicker = () => {
    emojiPicker.value = false;
};

const addEmoji = (emoji) => {
    const textarea = textInputRef.value;
    const currentValue = formTextInput.value || '';
    const start = textarea.selectionStart || 0;
    const end = textarea.selectionEnd || 0;

    const newText =
        currentValue.substring(0, start) +
        emoji.i +
        currentValue.substring(end);

    formTextInput.value = newText;

    // Focus the textarea and place the cursor at the correct position
    setTimeout(() => {
        textarea.focus();
        textarea.setSelectionRange(start + emoji.i.length, start + emoji.i.length);
    }, 0);
};

const handleClickOutside = (event) => {
    if (
        emojiPickerRef.value &&
        !emojiPickerRef.value.contains(event.target) &&
        !textInputRef.value.contains(event.target)
    ) {
        closeEmojiPicker();
    }
};

const startRecording = async () => {
    if (isContactBlocked.value) {
        return;
    }

    try {
        isAudioRecording.value = true;
        await recorder.value.start();
        isRecording.value = true;
        startTimer();
    } catch (error) {
        console.error('Error accessing microphone:', error);
    }
};

const stopRecording = async () => {
    if (isRecording.value) {
        try {
            const [buffer, blob] = await recorder.value.stop().getMp3();
            const audioFile = new File(buffer, 'audio-message.mp3', {
                type: blob.type,
                lastModified: Date.now()
            });

            audioPreviewUrl.value = URL.createObjectURL(blob);
            form2.value.type = 'audio';
            form2.value.file = audioFile;

            isRecording.value = false;
            stopTimer();
        } catch (error) {
            console.error('Error stopping recording:', error);
        }
    }
};

const deleteRecording = () => {
    if (audioPreviewUrl.value) {
        URL.revokeObjectURL(audioPreviewUrl.value);
    }
    audioPreviewUrl.value = null;
    recordingTime.value = 0;
    playbackTime.value = 0;
    audioDuration.value = 0;
    audioChunks.value = [];
    form2.value.type = null;
    form2.value.file = null;
    isAudioRecording.value = false;
    stopPlaybackTimer();
};

const togglePlayback = () => {
    if (!audioPlayer.value) return;

    if (isPlaying.value) {
        audioPlayer.value.pause();
        stopPlaybackTimer();
    } else {
        audioPlayer.value.play();
        startPlaybackTimer();
    }
    isPlaying.value = !isPlaying.value;
};

const startTimer = () => {
    recordingTime.value = 0;
    timerInterval.value = setInterval(() => {
        recordingTime.value++;
    }, 1000);
};

const startPlaybackTimer = () => {
    playbackTime.value = 0;
    playbackInterval.value = setInterval(() => {
        if (audioPlayer.value) {
            playbackTime.value = Math.floor(audioPlayer.value.currentTime);
        }
    }, 100);
};

const stopTimer = () => {
    if (timerInterval.value) {
        clearInterval(timerInterval.value);
        timerInterval.value = null;
    }
};

const stopPlaybackTimer = () => {
    if (playbackInterval.value) {
        clearInterval(playbackInterval.value);
        playbackInterval.value = null;
    }
};

const formatTime = (seconds) => {
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;
    return `${minutes.toString().padStart(2, '0')}:${remainingSeconds.toString().padStart(2, '0')}`;
};

const handleAudioLoaded = () => {
    if (audioPlayer.value) {
        audioDuration.value = Math.floor(audioPlayer.value.duration);
    }
};

const getSuggestion = async () => {
    if (isContactBlocked.value) {
        return;
    }

    processingAISuggestions.value = true;
    try {
        const response = await axios.get('/automation/chat/suggestion', {
            params: {
                contact: props.contact.uuid
            }
        });

        if (response.data.success) {
            form.value.type = 'text';
            formTextInput.value = response.data.data.text;
        } else {
            console.error('Failed to get suggestion:', response.data.message);
        }
    } catch (error) {
        console.error('Error getting suggestion:', error.response?.data?.message || error.message);
    } finally {
        processingAISuggestions.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
    recorder.value = new MicRecorder({
        bitRate: 128
    });
    joinTypingChannel();
    scheduleReplyWindowClock();
});

onBeforeUnmount(() => {
    leaveTypingChannel();
    document.removeEventListener('click', handleClickOutside);
    stopTimer();
    stopPlaybackTimer();
    if (replyWindowTimeout.value) {
        clearTimeout(replyWindowTimeout.value);
        replyWindowTimeout.value = null;
    }
    if (audioPreviewUrl.value) {
        URL.revokeObjectURL(audioPreviewUrl.value);
    }
    clearPendingMediaPreview();
});
</script>
<template>
    <div v-if="outboundError" class="flex justify-center items-center w-full px-6 md:px-4">
        <div class="w-full rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 mb-2">
            {{ $t(outboundError) }}
        </div>
    </div>
    <div v-if="props.chatLimitReached" class="flex justify-center items-center w-full px-6 md:px-4">
        <div class="flex items-start space-x-4 bg-orange-100 rounded-lg p-2 mb-2 px-4">
            <span class="text-red-700">
                <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 36 36">
                    <path fill="currentColor"
                        d="M18 21.32a1.3 1.3 0 0 0 1.3-1.3V14a1.3 1.3 0 1 0-2.6 0v6a1.3 1.3 0 0 0 1.3 1.32Z"
                        class="clr-i-outline clr-i-outline-path-1" />
                    <circle cx="17.95" cy="24.27" r="1.5" fill="currentColor"
                        class="clr-i-outline clr-i-outline-path-2" />
                    <path fill="currentColor"
                        d="M30.33 25.54L20.59 7.6a3 3 0 0 0-5.27 0L5.57 25.54A3 3 0 0 0 8.21 30h19.48a3 3 0 0 0 2.64-4.43Zm-1.78 1.94a1 1 0 0 1-.86.49H8.21a1 1 0 0 1-.88-1.48l9.74-17.94a1 1 0 0 1 1.76 0l9.74 17.94a1 1 0 0 1-.02.99Z"
                        class="clr-i-outline clr-i-outline-path-3" />
                    <path fill="none" d="M0 0h36v36H0z" />
                </svg>
            </span>
            <div>
                <div class="text-sm">{{ $t('Maximum chat limit reached') }}</div>
                <div class="text-sm">{{ $t(`You have reached the maximum chat limit for your subscription! Please
                    upgrade to send / receive more messages`) }}</div>
            </div>
        </div>
    </div>
    <div v-if="isContactBlocked && !props.chatLimitReached" class="flex justify-center items-center w-full px-6 md:px-4">
        <div class="flex items-start space-x-4 bg-red-100 rounded-lg p-2 mb-2 px-4 border border-red-200">
            <span class="text-red-700">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24">
                    <path fill="currentColor" d="M12 2a10 10 0 1 0 10 10A10.011 10.011 0 0 0 12 2m4.293 12.879l-1.414 1.414L12 13.414l-2.879 2.879l-1.414-1.414L10.586 12L7.707 9.121l1.414-1.414L12 10.586l2.879-2.879l1.414 1.414L13.414 12Z"/>
                </svg>
            </span>
            <div>
                <div class="text-sm font-medium">{{ $t('Blocked contact') }}</div>
                <div class="text-sm">{{ $t('This contact is blocked. Sending messages and templates is disabled.') }}</div>
            </div>
        </div>
    </div>
    <div v-if="!isContactBlocked && !props.chatLimitReached" class="w-full px-2 pb-2 md:px-10">
        <section class="rounded-2xl border p-3 shadow-sm" :class="isInboundChatWithin24Hours ? (isReplyWindowExpiringSoon ? 'border-amber-300 bg-amber-50/80' : 'border-emerald-200 bg-emerald-50/70') : (!latestInboundAt ? 'border-blue-200 bg-blue-50/70' : 'border-slate-300 bg-slate-50')" :aria-label="$t('Free reply window')">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-start gap-2">
                    <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full" :class="isInboundChatWithin24Hours ? (isReplyWindowExpiringSoon ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-700') : (!latestInboundAt ? 'bg-blue-100 text-blue-700' : 'bg-slate-200 text-slate-700')" aria-hidden="true">
                        <svg v-if="isInboundChatWithin24Hours" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-5"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/></svg>
                        <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-5"><rect x="4" y="5" width="16" height="15" rx="2"/><path stroke-linecap="round" d="M8 3v4m8-4v4M4 10h16"/></svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-semibold" :class="isInboundChatWithin24Hours ? (isReplyWindowExpiringSoon ? 'text-amber-950' : 'text-emerald-950') : 'text-slate-900'">
                                {{ $t(isInboundChatWithin24Hours ? 'You can reply to this customer now' : (latestInboundAt ? 'Regular reply time has ended' : 'The customer has not messaged yet')) }}
                            </h3>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="isInboundChatWithin24Hours ? (isReplyWindowExpiringSoon ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-900') : (!latestInboundAt ? 'bg-blue-100 text-blue-800' : 'bg-slate-200 text-slate-800')">
                                {{ $t(isInboundChatWithin24Hours ? 'You can reply now' : (latestInboundAt ? '24 hours passed — approved message needed' : 'Approved message needed to start')) }}
                            </span>
                        </div>
                        <p v-if="isInboundChatWithin24Hours && replyWindowExpiresAt" class="mt-1 text-sm leading-6" :class="isReplyWindowExpiringSoon ? 'text-amber-900' : 'text-emerald-900'">
                            {{ replyWindowRemainingMinutes === 0
                                ? $t('Less than one minute remains in the reply window.')
                                : (replyWindowHours === 0
                                    ? $t('Reply window minutes remaining', { minutes: replyWindowMinutes })
                                    : (replyWindowMinutes === 0
                                        ? $t('Reply window hours remaining', { hours: replyWindowHours })
                                        : $t('Reply window time remaining', { hours: replyWindowHours, minutes: replyWindowMinutes }))) }}
                        </p>
                        <p v-else-if="isInboundChatWithin24Hours" class="mt-1 text-sm leading-6 text-emerald-900">{{ $t('You can write and send a normal message now.') }}</p>
                        <p v-else-if="latestInboundAt" class="mt-1 text-sm leading-6 text-slate-700">{{ $t('More than 24 hours have passed since the customer messaged you. Choose an approved WhatsApp message to contact them now.') }}</p>
                        <p v-else class="mt-0.5 text-xs leading-5 text-slate-700">{{ $t('The customer has not sent a message yet. Choose an approved WhatsApp message to start the conversation.') }}</p>
                    </div>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
                    <button type="button" class="inline-flex min-h-8 items-center justify-center rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" :aria-expanded="showReplyWindowHelp" aria-controls="reply-window-explanation" @click="showReplyWindowHelp = !showReplyWindowHelp">
                        {{ $t(showReplyWindowHelp ? 'Hide explanation' : 'How does reply time work?') }}
                    </button>
                    <button v-if="!isInboundChatWithin24Hours" type="button" @click="viewTemplate()" class="inline-flex min-h-8 items-center justify-center rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:brightness-95 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                        {{ $t(latestInboundAt ? 'Send an approved message after 24 hours' : 'Choose an approved message to start') }}
                    </button>
                </div>
            </div>

            <div v-if="isInboundChatWithin24Hours && replyWindowExpiresAt" class="mt-3" role="progressbar" :aria-valuenow="replyWindowElapsedPercent" aria-valuemin="0" aria-valuemax="100" :aria-label="$t('Reply window elapsed')">
                <div class="mb-1.5 flex items-center justify-between gap-3 text-xs font-medium" :class="isReplyWindowExpiringSoon ? 'text-amber-900' : 'text-emerald-900'">
                    <span>{{ $t('Reply time (24 hours)') }}</span><span>{{ 100 - replyWindowElapsedPercent }}% {{ $t('left') }}</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-white/80">
                    <div class="h-full rounded-full transition-[width] duration-500" :class="isReplyWindowExpiringSoon ? 'bg-amber-500' : 'bg-emerald-600'" :style="{ width: `${100 - replyWindowElapsedPercent}%` }"></div>
                </div>
            </div>

            <div v-if="showReplyWindowHelp" id="reply-window-explanation" class="mt-3 rounded-lg border border-slate-200 bg-white p-3 text-sm leading-6 text-slate-700">
                <p>{{ $t('When the customer messages you, you can reply with your own message for 24 hours.') }}</p>
                <p class="mt-1">{{ $t('If the customer sends another message, the 24 hours start again.') }}</p>
                <p class="mt-1">{{ $t('After the 24-hour period ends, send a message approved by WhatsApp to contact the customer.') }}</p>
            </div>
        </section>
    </div>
    <form v-if="!isContactBlocked && isInboundChatWithin24Hours && !props.chatLimitReached" @submit.prevent="sendMessage()"
        class="flex items-center px-2 md:px-10 space-x-2">
        <div class="cb-card w-full p-3 sm:p-4">
            <div v-if="props.replyingTo" class="mb-3 flex items-start gap-3 rounded-md border-l-4 border-[#00a884] bg-slate-50 px-3 py-2">
                <div class="min-w-0 flex-1">
                    <div class="text-xs font-semibold text-[#008f72]">{{ $t('Replying to') }}</div>
                    <div class="truncate text-sm text-slate-600">{{ props.replyingTo.text || $t('Message') }}</div>
                </div>
                <button type="button" class="shrink-0 text-slate-500 hover:text-slate-900" :title="$t('Cancel')" @click="emit('reply-cleared')">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                        <path fill="currentColor" d="m12 10.586l4.95-4.95l1.414 1.414l-4.95 4.95l4.95 4.95l-1.414 1.414l-4.95-4.95l-4.95 4.95l-1.414-1.414l4.95-4.95l-4.95-4.95L7.05 5.636z" />
                    </svg>
                </button>
            </div>
            <div>
                <textarea ref="textInputRef" @keydown.enter.exact.prevent="handleEnterKey"
                    class="w-full outline-none resize-none text-sm rounded-xl p-3 bg-slate-50/70"
                    :class="processingForm ? 'bg-gray-100' : 'bg-white'" v-model="formTextInput"
                    @input="handleComposerInput" @blur="stopTyping" type="text" rows="3" :placeholder="$t('Type your message...')"
                    :disabled="processingForm">
                </textarea>
                <input ref="fileInputRef" type="file" class="sr-only" :accept="getAcceptedFileTypes()" id="file-upload"
                    @change="handleFileUpload($event)" />
            </div>
            <div v-if="form.file" class="mb-2 flex items-center gap-3 rounded-md border bg-slate-50 px-3 py-2 text-sm">
                <img
                    v-if="pendingMediaPreviewUrl && form.file.type.startsWith('image/')"
                    :src="pendingMediaPreviewUrl"
                    :alt="form.file.name"
                    class="h-12 w-12 shrink-0 rounded object-cover"
                />
                <video
                    v-else-if="pendingMediaPreviewUrl && form.file.type.startsWith('video/')"
                    :src="pendingMediaPreviewUrl"
                    controls
                    class="h-12 w-20 shrink-0 rounded object-cover"
                />
                <div v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded bg-white text-slate-500">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                        <path fill="currentColor" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Zm1 7V3.5L18.5 9H15ZM6 4h7v6h5v10H6V4Z" />
                    </svg>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-slate-700">{{ form.file.name }}</div>
                    <div v-if="formatFileSize(form.file.size)" class="text-xs text-slate-500">{{ formatFileSize(form.file.size) }}</div>
                    <div class="text-xs text-[#008f72]">{{ $t('Ready to send') }}</div>
                </div>
                <button type="button" class="shrink-0 text-slate-500 hover:text-red-600" @click="clearSelectedFile" :title="$t('Cancel')" :aria-label="$t('Cancel')">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                        <path fill="currentColor" d="m12 10.586l4.95-4.95l1.414 1.414l-4.95 4.95l4.95 4.95l-1.414 1.414l-4.95-4.95l-4.95 4.95l-1.414-1.414l4.95-4.95l-4.95-4.95L7.05 5.636z" />
                    </svg>
                </button>
            </div>
            <!-- Recording Interface -->
            <div v-if="isRecording || audioPreviewUrl" class="bg-slate-50 rounded-lg border px-2 mb-2">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <span class="text-red-500 mr-2">
                            <span v-if="isRecording" class="animate-pulse">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="7" fill="red" opacity="0.5" />
                                    <path fill="#fee2e2" fill-rule="evenodd"
                                        d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2S2 6.477 2 12s4.477 10 10 10m0-3a7 7 0 1 0 0-14a7 7 0 0 0 0 14"
                                        clip-rule="evenodd" />
                                </svg>
                            </span>
                            <span v-if="audioPreviewUrl" @click="togglePlayback">
                                <svg v-if="!isPlaying" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                    viewBox="0 0 24 24">
                                    <path fill="#22c55e" stroke="#22c55e" stroke-linecap="round" stroke-width="1.5"
                                        d="M3 12v6.967c0 2.31 2.534 3.769 4.597 2.648l3.203-1.742M3 8V5.033c0-2.31 2.534-3.769 4.597-2.648l12.812 6.968a2.998 2.998 0 0 1 0 5.294l-6.406 3.484" />
                                </svg>
                                <svg v-else xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                    viewBox="0 0 24 24">
                                    <path fill="none" stroke="red" stroke-linecap="round" stroke-width="1.5"
                                        d="M2 18c0 1.886 0 2.828.586 3.414S4.114 22 6 22s2.828 0 3.414-.586S10 19.886 10 18V6c0-1.886 0-2.828-.586-3.414S7.886 2 6 2s-2.828 0-3.414.586S2 4.114 2 6v8m20-8c0-1.886 0-2.828-.586-3.414S19.886 2 18 2s-2.828 0-3.414.586S14 4.114 14 6v12c0 1.886 0 2.828.586 3.414S16.114 22 18 22s2.828 0 3.414-.586S22 19.886 22 18v-8" />
                                </svg>
                            </span>
                        </span>
                        <span class="text-sm" v-if="isRecording">{{ formatTime(recordingTime) }}</span>
                        <span class="text-sm" v-else>{{ formatTime(playbackTime) }} / {{ formatTime(recordingTime)
                            }}</span>
                    </div>
                    <div class="flex gap-2">
                        <!-- Stop Recording Button -->
                        <button v-if="isRecording" @click="stopRecording" class="p-2 rounded-full hover:bg-red-100"
                            title="Stop Recording">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                                <path fill="red" stroke="red" stroke-width="1.5"
                                    d="M2 12c0-4.714 0-7.071 1.464-8.536C4.93 2 7.286 2 12 2s7.071 0 8.535 1.464C22 4.93 22 7.286 22 12s0 7.071-1.465 8.535C19.072 22 16.714 22 12 22s-7.071 0-8.536-1.465C2 19.072 2 16.714 2 12Z" />
                            </svg>
                        </button>

                        <!-- Delete Button -->
                        <button v-if="audioPreviewUrl" @click="deleteRecording"
                            class="p-2 rounded-full hover:bg-red-100 text-red-500" title="Delete Recording">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                                <path fill="none" stroke="red" stroke-linecap="round" stroke-width="1.5"
                                    d="M9.17 4a3.001 3.001 0 0 1 5.66 0m5.67 2h-17m14.874 9.4c-.177 2.654-.266 3.981-1.131 4.79s-2.195.81-4.856.81h-.774c-2.66 0-3.99 0-4.856-.81c-.865-.809-.953-2.136-1.13-4.79l-.46-6.9m13.666 0l-.2 3M9.5 11l.5 5m4.5-5l-.5 5" />
                            </svg>
                        </button>
                    </div>
                </div>
                <audio ref="audioPlayer" :src="audioPreviewUrl" @ended="isPlaying = false" class="hidden" />
            </div>
            <div v-if="typingIndicatorText" class="flex items-center gap-2 px-2 pb-2 text-xs text-slate-500">
                <span class="inline-flex h-2 w-2 rounded-full bg-primary animate-pulse"></span>
                <span>{{ typingIndicatorText }}</span>
            </div>
            <div class="flex flex-wrap gap-x-2 gap-y-2 md:gap-x-4 items-center text-gray-600">
                <div @click="viewTemplate()" class="flex gap-x-1 text-sm items-center cursor-pointer">
                    <span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 256 256">
                            <path fill="currentColor"
                                d="M216 80h-32V48a16 16 0 0 0-16-16H40a16 16 0 0 0-16 16v128a8 8 0 0 0 13 6.22L72 154v30a16 16 0 0 0 16 16h93.59L219 230.22a8 8 0 0 0 5 1.78a8 8 0 0 0 8-8V96a16 16 0 0 0-16-16M66.55 137.78L40 159.25V48h128v88H71.58a8 8 0 0 0-5.03 1.78M216 207.25l-26.55-21.47a8 8 0 0 0-5-1.78H88v-32h80a16 16 0 0 0 16-16V96h32Z">
                            </path>
                        </svg>
                    </span>
                    <button class="hidden md:inline">{{ $t('Templates') }}</button>
                </div>
                <div class="hidden md:block">|</div>
                <div @click="showRepliesModal = true" class="flex gap-x-1 text-sm items-center cursor-pointer">
                    <span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>
                    </span>
                    <button class="hidden md:inline">{{ $t('Saved Replies') }}</button>
                </div>
                <div class="hidden md:block">|</div>
                <!-- Meta Flows button — only visible when the module is enabled for this org -->
                <div v-if="metaFlowsModule" @click="showMetaFlowModal = true" class="flex gap-x-1 text-sm items-center cursor-pointer">
                    <span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2v-4M9 21H5a2 2 0 0 1-2-2v-4m0 0h18"/></svg>
                    </span>
                    <button class="hidden md:inline">{{ $t('Meta Flows') }}</button>
                </div>
                <div v-if="metaFlowsModule" class="hidden md:block">|</div>
                <div class="flex gap-x-2 md:gap-x-4 text-sm items-center">
                    <div>
                        <button type="button" class="py-1" @click="toggleEmojiPicker">
                            <span>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 16 16">
                                    <path fill="currentColor" fill-rule="evenodd"
                                        d="M4.111 2.18a7 7 0 1 1 7.778 11.64A7 7 0 0 1 4.11 2.18zm.556 10.809a6 6 0 1 0 6.666-9.978a6 6 0 0 0-6.666 9.978M6.5 7a1 1 0 1 1-2 0a1 1 0 0 1 2 0m5 0a1 1 0 1 1-2 0a1 1 0 0 1 2 0M8 11a3 3 0 0 1-2.65-1.58l-.87.48a4 4 0 0 0 7.12-.16l-.9-.43A3 3 0 0 1 8 11"
                                        clip-rule="evenodd" />
                                </svg>
                            </span>
                        </button>
                        <div class="absolute">

                            <div v-if="emojiPicker" class="absolute left-0 bottom-full" ref="emojiPickerRef">
                                <EmojiPicker :native="true" @select="addEmoji" />
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="py-1 cursor-pointer" @click="selectMediaType('document')" for="file-upload">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 56 56">
                                <path fill="currentColor"
                                    d="M44.09 28.867L26.863 46.094c-4.476 4.5-10.5 4.054-14.343.164c-3.867-3.844-4.313-9.82.187-14.32L36.191 8.453c2.696-2.695 6.657-3.07 9.235-.515c2.555 2.601 2.18 6.539-.492 9.234L21.988 40.14c-1.171 1.195-2.554.843-3.375.047c-.796-.82-1.125-2.18.047-3.376l16.031-16.007c.704-.727.75-1.758.07-2.438c-.679-.656-1.71-.61-2.413.094L16.246 34.563c-2.39 2.39-2.297 6.046-.187 8.156c2.297 2.297 5.789 2.25 8.18-.164l23.085-23.063c4.383-4.383 4.172-10.148.375-13.969c-3.75-3.726-9.586-4.007-13.992.375L10.082 29.547c-5.789 5.789-5.344 14.062-.117 19.289c5.227 5.203 13.5 5.648 19.289-.117l17.344-17.344c.703-.68.68-1.922-.024-2.555c-.68-.726-1.78-.633-2.484.047" />
                            </svg>
                        </label>
                    </div>
                    <div>
                        <label @click="selectMediaType('audio')" class="py-1 cursor-pointer" for="file-upload">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
                                <g fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path
                                        d="M9 19a3 3 0 1 1-6 0a3 3 0 0 1 6 0Zm12-2a3 3 0 1 1-6 0a3 3 0 0 1 6 0ZM9 19V8m12 9V6" />
                                    <path stroke-linecap="round"
                                        d="m15.735 3.755l-4 1.333c-1.32.44-1.98.66-2.357 1.184S9 7.492 9 8.882V12l12-4v-.45c0-2.533 0-3.8-.83-4.398c-.831-.599-2.032-.198-4.435.603Z" />
                                </g>
                            </svg>
                        </label>
                    </div>
                    <div>
                        <label @click="selectMediaType('video')" class="py-1 cursor-pointer" for="file-upload">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 32 32">
                                <path fill="currentColor"
                                    d="M6.5 5.5A4.5 4.5 0 0 0 2 10v12a4.5 4.5 0 0 0 4.5 4.5h12A4.5 4.5 0 0 0 23 22v-1.5l4.2 3.15c1.153.865 2.8.042 2.8-1.4V9.75c0-1.442-1.647-2.265-2.8-1.4L23 11.5V10a4.5 4.5 0 0 0-4.5-4.5zM23 14l5-3.75v11.5L23 18zm-2-4v12a2.5 2.5 0 0 1-2.5 2.5h-12A2.5 2.5 0 0 1 4 22V10a2.5 2.5 0 0 1 2.5-2.5h12A2.5 2.5 0 0 1 21 10" />
                            </svg>
                        </label>
                    </div>
                    <div>
                        <label class="py-1 cursor-pointer" @click="selectMediaType('image')" for="file-upload">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
                                <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                    stroke-width="1.5">
                                    <path
                                        d="M6.5 8a2 2 0 1 0 4 0a2 2 0 0 0-4 0Zm14.427 1.99c-6.61-.908-12.31 4-11.927 10.51" />
                                    <path d="M3 13.066c2.78-.385 5.275.958 6.624 3.1" />
                                    <path
                                        d="M3 12c0-4.243 0-6.364 1.318-7.682C5.636 3 7.758 3 12 3c4.243 0 6.364 0 7.682 1.318C21 5.636 21 7.758 21 12c0 4.243 0 6.364 0 7.682C18.364 21 16.242 21 12 21c-4.243 0-6.364 0-7.682-1.318C3 18.364 3 16.242 3 12Z" />
                                </g>
                            </svg>
                        </label>
                    </div>
                </div>
                <div class="hidden md:block">|</div>
                <button type="button" class="py-1 cursor-pointer" @click="startRecording"
                    v-if="!isRecording && !audioPreviewUrl">
                    <span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 1024 1024">
                            <path fill="currentColor"
                                d="M842 454c0-4.4-3.6-8-8-8h-60c-4.4 0-8 3.6-8 8c0 140.3-113.7 254-254 254S258 594.3 258 454c0-4.4-3.6-8-8-8h-60c-4.4 0-8 3.6-8 8c0 168.7 126.6 307.9 290 327.6V884H326.7c-13.7 0-24.7 14.3-24.7 32v36c0 4.4 2.8 8 6.2 8h407.6c3.4 0 6.2-3.6 6.2-8v-36c0-17.7-11-32-24.7-32H548V782.1c165.3-18 294-158 294-328.1M512 624c93.9 0 170-75.2 170-168V232c0-92.8-76.1-168-170-168s-170 75.2-170 168v224c0 92.8 76.1 168 170 168m-94-392c0-50.6 41.9-92 94-92s94 41.4 94 92v224c0 50.6-41.9 92-94 92s-94-41.4-94-92z" />
                        </svg>
                    </span>
                </button>
                <div class="hidden md:block">|</div>
                <div class="flex-shrink-0" v-if="!simpleForm">
                    <button v-if="!processingAISuggestions"
                        class="text-[12px] md:text-[14px] flex items-center gap-1 md:gap-2 hover:bg-gray-100 p-1 rounded-md"
                        type="button" @click="getSuggestion()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
                            <g fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="1.5" color="#000">
                                <path d="m7 8l2.942 1.74c1.715 1.014 2.4 1.014 4.116 0L17 8" />
                                <path
                                    d="M21.984 12.976c.021-.986.021-1.966 0-2.952c-.065-3.065-.098-4.598-1.229-5.733c-1.131-1.136-2.705-1.175-5.854-1.254a115 115 0 0 0-5.802 0c-3.149.079-4.723.118-5.854 1.254c-1.131 1.135-1.164 2.668-1.23 5.733a69 69 0 0 0 0 2.952c.066 3.065.099 4.598 1.23 5.733c1.131 1.136 2.705 1.175 5.854 1.254c1.305.033 2.601.044 3.901.033" />
                                <path
                                    d="m18.5 14l.258.697c.338.914.507 1.371.84 1.704c.334.334.791.503 1.705.841L22 17.5l-.697.258c-.914.338-1.371.507-1.704.84c-.334.334-.503.791-.841 1.705L18.5 21l-.258-.697c-.338-.914-.507-1.371-.84-1.704c-.334-.334-.503-.791-.841 1.705L15 17.5l.697-.258c.914-.338 1.371-.507 1.704-.84c.334-.334.503-.791.841-1.705z" />
                            </g>
                        </svg>
                        <span class="hidden md:inline">{{ $t('AI Assist') }}</span>
                    </button>
                    <div v-else
                        class="text-[12px] md:text-[14px] flex items-center gap-1 md:gap-2 bg-gray-100 p-1 px-2 rounded-md">
                        <span class="hidden md:inline">{{ $t('Searching for suggestions') }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
                            <circle cx="18" cy="12" r="0" fill="currentColor">
                                <animate attributeName="r" begin=".67" calcMode="spline" dur="1.5s"
                                    keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                                    repeatCount="indefinite" values="0;2;0;0" />
                            </circle>
                            <circle cx="12" cy="12" r="0" fill="currentColor">
                                <animate attributeName="r" begin=".33" calcMode="spline" dur="1.5s"
                                    keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                                    repeatCount="indefinite" values="0;2;0;0" />
                            </circle>
                            <circle cx="6" cy="12" r="0" fill="currentColor">
                                <animate attributeName="r" begin="0" calcMode="spline" dur="1.5s"
                                    keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                                    repeatCount="indefinite" values="0;2;0;0" />
                            </circle>
                        </svg>
                    </div>
                </div>
                <div class="ml-auto">
                    <button
                        class="flex items-center gap-x-1 md:gap-x-2 p-1 px-2 rounded-md border border-gray-400 text-xs md:text-sm"
                        :disabled="!hasPendingContent || processingForm">
                        <span
                            :class="!hasPendingContent || processingForm ? 'text-slate-400' : 'text-black'">Send</span>
                        <div>
                            <svg v-if="!processingForm"
                                :class="!hasPendingContent ? 'text-slate-300' : 'text-black'"
                                xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 16 16">
                                <path fill="currentColor"
                                    d="M1.724 1.053a.5.5 0 0 0-.714.545l1.403 4.85a.5.5 0 0 0 .397.354l5.69.953c.268.053.268.437 0 .49l-5.69.953a.5.5 0 0 0-.397.354l-1.403 4.85a.5.5 0 0 0 .714.545l13-6.5a.5.5 0 0 0 0-.894l-13-6.5Z" />
                            </svg>
                            <svg v-else xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
                                <circle cx="12" cy="2" r="0" fill="currentColor">
                                    <animate attributeName="r" begin="0" calcMode="spline" dur="1s"
                                        keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                                        repeatCount="indefinite" values="0;2;0;0" />
                                </circle>
                                <circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(45 12 12)">
                                    <animate attributeName="r" begin="0.125s" calcMode="spline" dur="1s"
                                        keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                                        repeatCount="indefinite" values="0;2;0;0" />
                                </circle>
                                <circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(90 12 12)">
                                    <animate attributeName="r" begin="0.25s" calcMode="spline" dur="1s"
                                        keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                                        repeatCount="indefinite" values="0;2;0;0" />
                                </circle>
                                <circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(135 12 12)">
                                    <animate attributeName="r" begin="0.375s" calcMode="spline" dur="1s"
                                        keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                                        repeatCount="indefinite" values="0;2;0;0" />
                                </circle>
                                <circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(180 12 12)">
                                    <animate attributeName="r" begin="0.5s" calcMode="spline" dur="1s"
                                        keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                                        repeatCount="indefinite" values="0;2;0;0" />
                                </circle>
                                <circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(225 12 12)">
                                    <animate attributeName="r" begin="0.625s" calcMode="spline" dur="1s"
                                        keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                                        repeatCount="indefinite" values="0;2;0;0" />
                                </circle>
                                <circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(270 12 12)">
                                    <animate attributeName="r" begin="0.75s" calcMode="spline" dur="1s"
                                        keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                                        repeatCount="indefinite" values="0;2;0;0" />
                                </circle>
                                <circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(315 12 12)">
                                    <animate attributeName="r" begin="0.875s" calcMode="spline" dur="1s"
                                        keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                                        repeatCount="indefinite" values="0;2;0;0" />
                                </circle>
                            </svg>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </form>
    <PreCreatedRepliesModal
        :show="showRepliesModal"
        :contactUuid="props.contact.uuid"
        @close="showRepliesModal = false"
        @send="sendPreCreatedReply"
    />

    <MetaFlowPickerModal
        :isOpen="showMetaFlowModal"
        :contactUuid="props.contact.uuid"
        @close="showMetaFlowModal = false"
        @sent="showMetaFlowModal = false"
    />
</template>
