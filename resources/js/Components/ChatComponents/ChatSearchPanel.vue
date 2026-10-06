<script setup>
import { nextTick, onUnmounted, ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
  contactUuid: { type: String, required: true },
  refreshToken: { type: Number, default: 0 },
});
const emit = defineEmits(['close', 'select']);
const input = ref(null);
const query = ref('');
const results = ref([]);
const cursor = ref(null);
const selected = ref(-1);
const loading = ref(false);
const loadingMore = ref(false);
const pending = ref(false);
const error = ref(false);
const preparing = ref(false);
let timer = null;
let controller = null;
let requestId = 0;

nextTick(() => input.value?.focus());

const fetchResults = async (more = false) => {
  const text = query.value.trim();
  if (text.length < 3 || (more && !cursor.value)) return;
  controller?.abort();
  controller = new AbortController();
  const currentRequest = ++requestId;
  if (more) loadingMore.value = true;
  else {
    loading.value = true;
    pending.value = false;
  }
  error.value = false;
  preparing.value = false;

  try {
    const response = await axios.get(`/chats/${encodeURIComponent(props.contactUuid)}/search`, {
      params: { q: text, ...(more ? { before: cursor.value } : {}) },
      signal: controller.signal,
    });
    if (currentRequest !== requestId) return;
    results.value = more ? [...results.value, ...response.data.results] : response.data.results;
    cursor.value = response.data.next_cursor;
  } catch (exception) {
    if (currentRequest === requestId && exception.code !== 'ERR_CANCELED') {
      preparing.value = exception.response?.data?.code === 'index_not_ready';
      error.value = !preparing.value;
    }
  } finally {
    if (currentRequest === requestId) {
      loading.value = false;
      loadingMore.value = false;
      pending.value = false;
    }
  }
};

watch(query, () => {
  clearTimeout(timer);
  controller?.abort();
  requestId++;
  results.value = [];
  selected.value = -1;
  cursor.value = null;
  loading.value = false;
  loadingMore.value = false;
  pending.value = query.value.trim().length >= 3;
  error.value = false;
  preparing.value = false;
  if (query.value.trim().length >= 3) timer = setTimeout(() => fetchResults(), 250);
});

watch(() => props.contactUuid, () => {
  clearTimeout(timer);
  controller?.abort();
  requestId++;
  query.value = '';
  results.value = [];
  cursor.value = null;
  selected.value = -1;
  loading.value = false;
  loadingMore.value = false;
  pending.value = false;
  error.value = false;
  preparing.value = false;
  nextTick(() => input.value?.focus());
});

watch(() => props.refreshToken, () => {
  if (query.value.trim().length >= 3) {
    cursor.value = null;
    selected.value = -1;
    fetchResults();
  }
});

const choose = (index) => {
  selected.value = index;
  emit('select', results.value[index]);
};

const move = async (direction) => {
  if (!results.value.length) return;
  let next = selected.value + direction;
  if (selected.value === -1) next = 0;
  if (next >= results.value.length && cursor.value) {
    await fetchResults(true);
  }
  if (next >= 0 && next < results.value.length) choose(next);
};

const onSearchKeydown = (event) => {
  if (event.key === 'Escape') {
    emit('close');
  } else if (event.key === 'Enter') {
    event.preventDefault();
    move(event.shiftKey ? -1 : 1);
  }
};

const highlightParts = (text) => {
  const words = [...new Set(query.value.trim().split(/\s+/u).filter(Boolean))];
  if (!words.length) return [{ text, match: false }];
  const escaped = words.map((word) => word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
  const parts = String(text).split(new RegExp(`(${escaped.join('|')})`, 'giu'));
  return parts.filter(Boolean).map((part, index) => ({
    text: part,
    match: words.some((word) => part.toLocaleLowerCase() === word.toLocaleLowerCase()),
  }));
};

onUnmounted(() => {
  clearTimeout(timer);
  controller?.abort();
});
</script>

<template>
  <section class="border-b border-slate-200 bg-white p-3 text-start" :aria-label="$t('Search this conversation')">
    <div class="flex items-center gap-2">
      <input ref="input" v-model="query" type="search" :placeholder="$t('Search messages')"
        :aria-label="$t('Search messages')" class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
        @keydown="onSearchKeydown" />
      <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" @click="emit('close')">{{ $t('Close search') }}</button>
    </div>
    <p v-if="query.trim().length > 0 && query.trim().length < 3" class="mt-2 text-xs text-slate-600">{{ $t('Enter at least three characters') }}</p>
    <p v-else-if="loading || pending" class="mt-2 text-sm text-slate-600" role="status">{{ $t('Searching messages...') }}</p>
    <p v-else-if="error" class="mt-2 text-sm text-red-700" role="alert">{{ $t('Search failed. Please try again.') }} <button type="button" class="underline" @click="fetchResults()">{{ $t('Retry') }}</button></p>
    <p v-else-if="preparing" class="mt-2 text-sm text-slate-600" role="status">{{ $t('Conversation search is preparing') }} <button type="button" class="underline" @click="fetchResults()">{{ $t('Retry') }}</button></p>
    <p v-else-if="query.trim().length >= 3 && !results.length" class="mt-2 text-sm text-slate-600" role="status">{{ $t('No matching messages') }}</p>
    <template v-if="results.length">
      <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-600">
        <span>{{ $t('Loaded search results') }}: {{ results.length }}<span v-if="cursor"> · {{ $t('More results available') }}</span></span>
        <div class="flex gap-1">
          <button type="button" :disabled="selected <= 0" class="rounded border px-2 py-1 disabled:opacity-40" @click="move(-1)">{{ $t('Previous result') }}</button>
          <button type="button" :disabled="selected >= results.length - 1 && !cursor" class="rounded border px-2 py-1 disabled:opacity-40" @click="move(1)">{{ $t('Next result') }}</button>
        </div>
      </div>
      <ul class="mt-2 max-h-44 space-y-1 overflow-y-auto">
        <li v-for="(result, index) in results" :key="result.log_id">
        <button type="button" @click="choose(index)"
          class="block w-full rounded-lg border px-3 py-2 text-start text-sm hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
          :class="selected === index ? 'border-primary bg-emerald-50' : 'border-slate-200'">
          <span class="block text-xs text-slate-500">{{ result.type === 'inbound' ? $t('Incoming message') : $t('Outgoing message') }} · {{ result.created_at }}</span>
          <span class="mt-1 block line-clamp-2 break-words" dir="auto"><template v-for="(part, partIndex) in highlightParts(result.text)" :key="partIndex"><mark v-if="part.match" class="rounded bg-amber-200 text-inherit">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></span>
        </button>
        </li>
      </ul>
      <button v-if="cursor" type="button" :disabled="loadingMore" class="mt-2 text-sm text-primary underline disabled:opacity-50" @click="fetchResults(true)">{{ loadingMore ? $t('Loading...') : $t('More results') }}</button>
    </template>
  </section>
</template>
