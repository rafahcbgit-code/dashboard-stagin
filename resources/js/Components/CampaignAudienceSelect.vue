<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { trans } from "laravel-vue-i18n";

const props = defineProps({
  modelValue: { type: [String, Number], default: null },
  options: { type: Array, default: () => [] },
  name: { type: String, required: true },
  placeholder: { type: String, required: true },
  searchPlaceholder: { type: String, required: true },
  noResults: { type: String, required: true },
  searchError: { type: String, required: true },
  resultsHint: { type: String, required: true },
  searchingLabel: { type: String, required: true },
  searchUrl: { type: String, required: true },
  required: Boolean,
  error: String,
});

const emit = defineEmits(["update:modelValue"]);
const rootElement = ref(null);
const searchInput = ref(null);
const isOpen = ref(false);
const query = ref("");
const remoteOptions = ref([]);
const selectedRemoteOption = ref(null);
const isSearching = ref(false);
const hasSearchError = ref(false);
const activeIndex = ref(0);
const visibleOptionLimit = 50;
const listboxId = "campaign-audience-options";
let searchTimer = null;
let searchController = null;
let selectedController = null;

const allOptions = computed(() => {
  const optionsByValue = new Map();
  [...props.options, ...remoteOptions.value, ...(selectedRemoteOption.value ? [selectedRemoteOption.value] : [])]
    .forEach((option) => optionsByValue.set(String(option.value), option));
  return [...optionsByValue.values()];
});

const selectedOption = computed(() =>
  allOptions.value.find((option) => String(option.value) === String(props.modelValue)) || null,
);

const filteredOptions = computed(() => {
  const search = query.value.trim().toLocaleLowerCase();
  const matchingOptions = search.length >= 2
    ? allOptions.value.filter((option) => String(option.label).toLocaleLowerCase().includes(search))
    : [...props.options, ...(selectedRemoteOption.value ? [selectedRemoteOption.value] : [])];

  return matchingOptions.slice(0, visibleOptionLimit);
});

const fetchContactGroups = async (params, signal) => {
  const search = new URLSearchParams(params);
  const response = await fetch(`${props.searchUrl}?${search.toString()}`, {
    headers: { Accept: "application/json" },
    signal,
  });
  if (!response.ok) throw new Error("Unable to search contact groups");
  const data = await response.json();
  return (data.data || []).map((group) => ({
    value: group.uuid,
    label: group.name,
    description: trans(":count contacts in this group", { count: Number(group.contacts_count || 0).toLocaleString() }),
  }));
};

watch(query, (value) => {
  clearTimeout(searchTimer);
  searchController?.abort();
  remoteOptions.value = [];
  hasSearchError.value = false;
  activeIndex.value = 0;
  const search = value.trim();
  if (search.length < 2) {
    isSearching.value = false;
    return;
  }

  searchTimer = setTimeout(async () => {
    const controller = new AbortController();
    searchController = controller;
    isSearching.value = true;
    try {
      remoteOptions.value = await fetchContactGroups({ q: search }, controller.signal);
    } catch (error) {
      if (error.name !== "AbortError") {
        remoteOptions.value = [];
        hasSearchError.value = true;
      }
    } finally {
      if (!controller.signal.aborted) isSearching.value = false;
    }
  }, 250);
});

watch(() => props.modelValue, async (value) => {
  selectedController?.abort();
  if (!value || value === "all" || props.options.some((option) => String(option.value) === String(value))) {
    selectedRemoteOption.value = null;
    return;
  }
  if (selectedRemoteOption.value && String(selectedRemoteOption.value.value) === String(value)) return;

  const controller = new AbortController();
  selectedController = controller;
  try {
    selectedRemoteOption.value = (await fetchContactGroups({ uuid: String(value) }, controller.signal))[0] || null;
  } catch (error) {
    if (error.name !== "AbortError") selectedRemoteOption.value = null;
  }
}, { immediate: true });

const openDropdown = async () => {
  isOpen.value = true;
  activeIndex.value = Math.max(0, filteredOptions.value.findIndex((option) => String(option.value) === String(props.modelValue)));
  await nextTick();
  searchInput.value?.focus();
};

const closeDropdown = (restoreFocus = false) => {
  isOpen.value = false;
  query.value = "";
  if (restoreFocus) nextTick(() => rootElement.value?.querySelector("[role=combobox]")?.focus());
};

const selectOption = (option) => {
  if (!option) return;
  selectedRemoteOption.value = props.options.some((item) => String(item.value) === String(option.value)) ? null : option;
  emit("update:modelValue", option.value);
  closeDropdown(true);
};

const handleSearchKeydown = (event) => {
  if (event.key === "Escape") {
    event.preventDefault();
    closeDropdown(true);
    return;
  }
  if (event.key === "ArrowDown" && filteredOptions.value.length) {
    event.preventDefault();
    activeIndex.value = (activeIndex.value + 1) % filteredOptions.value.length;
  } else if (event.key === "ArrowUp" && filteredOptions.value.length) {
    event.preventDefault();
    activeIndex.value = (activeIndex.value - 1 + filteredOptions.value.length) % filteredOptions.value.length;
  } else if (event.key === "Enter" && filteredOptions.value.length) {
    event.preventDefault();
    selectOption(filteredOptions.value[activeIndex.value]);
  }
};

const closeOnOutsideClick = (event) => {
  if (isOpen.value && !rootElement.value?.contains(event.target)) closeDropdown();
};

onMounted(() => document.addEventListener("pointerdown", closeOnOutsideClick));
onBeforeUnmount(() => {
  clearTimeout(searchTimer);
  searchController?.abort();
  selectedController?.abort();
  document.removeEventListener("pointerdown", closeOnOutsideClick);
});
</script>

<template>
  <div ref="rootElement" class="relative min-w-0">
    <label for="campaign-audience-trigger" class="mb-1 block text-sm leading-6 text-gray-900">{{ name }}</label>
    <button
      id="campaign-audience-trigger"
      type="button"
      role="combobox"
      aria-haspopup="listbox"
      :aria-expanded="isOpen"
      :aria-controls="listboxId"
      :aria-required="required"
      :aria-invalid="Boolean(error)"
      class="flex min-h-10 w-full items-center justify-between gap-3 rounded-lg border bg-white px-3 py-2 text-start text-sm shadow-sm transition focus:outline-none focus:ring-2 focus:ring-primary"
      :class="error ? 'border-red-500 ring-1 ring-red-500' : 'border-slate-300 hover:border-slate-400'"
      @click="isOpen ? closeDropdown() : openDropdown()"
      @keydown.down.prevent="openDropdown"
      @keydown.enter.prevent="openDropdown"
      @keydown.space.prevent="openDropdown"
    >
      <span class="min-w-0 flex-1 truncate" :class="selectedOption ? 'text-slate-900' : 'text-slate-500'">
        {{ selectedOption?.label || placeholder }}
        <span v-if="selectedOption?.description" class="ms-1 text-xs text-slate-500">({{ selectedOption.description }})</span>
      </span>
      <svg class="h-4 w-4 shrink-0 text-slate-500 transition" :class="isOpen ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" d="M5.22 7.22a.75.75 0 0 1 1.06 0L10 10.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
      </svg>
    </button>

    <div v-if="isOpen" class="absolute inset-x-0 top-full z-40 mt-1 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
      <div class="border-b border-slate-100 p-2">
        <div class="relative">
          <svg class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.46 9.78l3.63 3.63a.75.75 0 1 0 1.06-1.06l-3.63-3.63A5.5 5.5 0 0 0 9 3.5ZM5 9a4 4 0 1 1 8 0a4 4 0 0 1-8 0Z" clip-rule="evenodd" />
          </svg>
          <input
            ref="searchInput"
            v-model="query"
            type="search"
            :placeholder="searchPlaceholder"
            autocomplete="off"
            class="block min-h-10 w-full rounded-lg border-0 bg-slate-50 py-2 pe-3 ps-9 text-start text-sm text-slate-900 ring-1 ring-inset ring-slate-200 placeholder:text-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-primary"
            :aria-label="searchPlaceholder"
            :aria-controls="listboxId"
            :aria-activedescendant="filteredOptions.length ? `campaign-audience-option-${activeIndex}` : undefined"
            @keydown="handleSearchKeydown"
          />
        </div>
      </div>

      <ul :id="listboxId" role="listbox" class="max-h-64 overflow-y-auto p-1.5">
        <li
          v-for="(option, index) in filteredOptions"
          :id="`campaign-audience-option-${index}`"
          :key="option.value"
          role="option"
          :aria-selected="String(option.value) === String(modelValue)"
          class="cursor-pointer rounded-lg px-3 py-2.5 text-start transition"
          :class="index === activeIndex ? 'bg-emerald-50 text-primary' : 'text-slate-800 hover:bg-slate-50'"
          @mouseenter="activeIndex = index"
          @mousedown.prevent
          @click="selectOption(option)"
        >
          <span class="block truncate text-sm font-medium">{{ option.label }}</span>
          <span v-if="option.description" class="mt-0.5 block text-xs text-slate-500">{{ option.description }}</span>
        </li>
        <li v-if="isSearching" class="px-3 py-3 text-start text-sm text-slate-500" role="status">{{ searchingLabel }}</li>
        <li v-else-if="hasSearchError" class="px-3 py-3 text-start text-sm text-red-700" role="alert">{{ searchError }}</li>
        <li v-else-if="filteredOptions.length === 0" class="px-3 py-3 text-start text-sm text-slate-500" role="status">{{ noResults }}</li>
        <li v-else-if="filteredOptions.length === visibleOptionLimit || (query.trim().length > 0 && remoteOptions.length >= visibleOptionLimit)" class="border-t border-slate-100 px-3 py-2 text-start text-xs text-slate-500">{{ resultsHint }}</li>
      </ul>
    </div>

    <p v-if="error" class="form-error mt-1 text-xs text-red-700" role="alert">{{ error }}</p>
  </div>
</template>
