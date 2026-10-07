<script setup>
import { nextTick, onUnmounted, ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
  contactUuid: { type: String, required: true },
  refreshToken: { type: Number, default: 0 },
});
const emit = defineEmits(['close', 'select', 'reset-selection']);
const input = ref(null);
const query = ref('');
const results = ref([]);
const cursor = ref(null);
const selected = ref(-1);
const resultsExpanded = ref(true);
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
  resultsExpanded.value = true;
  emit('reset-selection');
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
  resultsExpanded.value = true;
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
    resultsExpanded.value = true;
    emit('reset-selection');
    fetchResults();
  }
});

const choose = (index) => {
  selected.value = index;
  resultsExpanded.value = false;
  emit('select', results.value[index]);
};

const clearSelection = () => {
  selected.value = -1;
  resultsExpanded.value = true;
  emit('reset-selection');
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
  <section class="min-w-0 border-b border-slate-200 bg-white p-2.5 text-start sm:p-3" :aria-label="$t('Search this conversation')">
    <div class="flex min-w-0 items-center gap-2">
      <div class="flex min-w-0 flex-1 items-center gap-2 rounded-xl border border-slate-300 bg-white px-3 shadow-sm focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/20">
        <svg aria-hidden="true" class="h-4 w-4 shrink-0 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
        <input ref="input" v-model="query" type="search" :placeholder="$t('Search messages')"
          :aria-label="$t('Search messages')" class="min-w-0 flex-1 border-0 bg-transparent py-2.5 text-start text-sm text-slate-900 outline-none placeholder:text-slate-500"
          @keydown="onSearchKeydown" />
      </div>
      <button type="button" class="shrink-0 rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm font-medium text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="emit('close')">{{ $t('Close search') }}</button>
    </div>
    <p v-if="query.trim().length > 0 && query.trim().length < 3" class="mt-2 text-xs text-slate-600">{{ $t('Enter at least three characters') }}</p>
    <p v-else-if="loading || pending" class="mt-2 text-sm text-slate-600" role="status">{{ $t('Searching messages...') }}</p>
    <p v-else-if="error" class="mt-2 text-sm text-red-700" role="alert">{{ $t('Search failed. Please try again.') }} <button type="button" class="underline" @click="fetchResults()">{{ $t('Retry') }}</button></p>
    <p v-else-if="preparing" class="mt-2 text-sm text-slate-600" role="status">{{ $t('Conversation search is preparing') }} <button type="button" class="underline" @click="fetchResults()">{{ $t('Retry') }}</button></p>
    <p v-else-if="query.trim().length >= 3 && !results.length" class="mt-2 text-sm text-slate-600" role="status">{{ $t('No matching messages') }}</p>
    <template v-if="results.length">
      <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-700" aria-live="polite">
        <span class="font-semibold"><template v-if="selected >= 0">{{ $t('Result position among loaded', { current: selected + 1, total: results.length }) }}</template><template v-else>{{ $t('Search results') }}: {{ results.length }}</template><span v-if="cursor" class="font-normal"> · {{ $t('More results available') }}</span></span>
        <button v-if="selected >= 0" type="button" class="min-h-8 rounded-lg px-2 font-semibold text-primary underline-offset-2 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" :aria-expanded="resultsExpanded" @click="resultsExpanded = !resultsExpanded">{{ resultsExpanded ? $t('Hide search results') : $t('Show search results') }}</button>
      </div>
      <div v-if="selected >= 0" class="mt-1.5 flex flex-wrap items-center gap-1.5">
        <button type="button" :disabled="selected <= 0" class="min-h-8 rounded-lg border border-slate-300 px-2.5 text-xs font-medium text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-40" @click="move(-1)">{{ $t('Previous result') }}</button>
        <button type="button" :disabled="selected >= results.length - 1 && !cursor" class="min-h-8 rounded-lg border border-slate-300 px-2.5 text-xs font-medium text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-40" @click="move(1)">{{ $t('Next result') }}</button>
        <button type="button" class="min-h-8 rounded-lg bg-primary px-2.5 text-xs font-semibold text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="clearSelection">{{ $t('Back to latest messages') }}</button>
      </div>
      <template v-if="resultsExpanded">
        <ul class="mt-2 max-h-40 space-y-1 overflow-y-auto pe-1" :aria-label="$t('Search results')">
          <li v-for="(result, index) in results" :key="result.log_id">
            <button type="button" @click="choose(index)" :aria-current="selected === index ? 'true' : undefined"
              class="block w-full min-w-0 rounded-lg border px-3 py-1.5 text-start transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
              :class="selected === index ? 'border-primary bg-emerald-50' : 'border-slate-200 bg-slate-50 hover:border-slate-300 hover:bg-white'">
              <span class="flex items-center justify-between gap-3">
                <span class="text-xs font-semibold text-slate-700">{{ result.type === 'inbound' ? $t('From contact') : $t('From you') }}</span>
                <time class="shrink-0 text-xs text-slate-500" dir="ltr">{{ result.created_at }}</time>
              </span>
              <span class="mt-0.5 block truncate text-sm text-slate-900" dir="auto"><template v-for="(part, partIndex) in highlightParts(result.text)" :key="partIndex"><mark v-if="part.match" class="rounded bg-amber-200 text-inherit">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></span>
            </button>
          </li>
        </ul>
        <button v-if="cursor" type="button" :disabled="loadingMore" class="mt-2 min-h-8 rounded-lg border border-slate-300 px-3 text-xs font-semibold text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-50" @click="fetchResults(true)">{{ loadingMore ? $t('Loading...') : $t('More results') }}</button>
      </template>
    </template>
  </section>
</template>
