<template>
    <div :aria-busy="isNavigating" class="flex w-full min-w-0 items-center justify-center">
      <div class="flex w-full min-w-0 flex-col items-center justify-center gap-1">
      <div v-if="pagination.last_page > 1" class="shrink-0 whitespace-nowrap text-[11px] font-medium text-slate-600 sm:text-xs">{{ pagination.total }} {{ $t('Items') }}</div>

      <div class="flex max-w-full min-w-0 flex-none items-center justify-center gap-1 rounded-xl border border-slate-100 bg-slate-50/80 p-0.5" v-if="pagination.last_page > 1">
        <!-- First Page Button -->
        <button v-if="!compact"
            :disabled="noPreviousPage || isNavigating"
            :class="{'opacity-50': noPreviousPage || isNavigating}"
            @click="loadPage(1)"
            :aria-label="$t('First page')"
            class="hidden h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-700 shadow-sm outline-none hover:bg-emerald-50 hover:text-emerald-700 sm:inline-flex focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 lg:h-3 lg:w-3" :class="{ 'rotate-180': isRtl }" fill="none" viewBox="0 0 24 24"
               stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
          </svg>
        </button>

        <!-- Previous Page Button -->
        <button
            :disabled="noPreviousPage || isNavigating"
            :class="{'opacity-50': noPreviousPage || isNavigating}"
            @click="loadPage(pagination.current_page - 1)"
            :aria-label="$t('Previous page')"
            class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-700 shadow-sm outline-none hover:bg-emerald-50 hover:text-emerald-700 focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 lg:h-3 lg:w-3" :class="{ 'rotate-180': isRtl }" fill="none" viewBox="0 0 24 24"
               stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
  
        <!-- Jump to Page Input Section -->
        <div class="relative flex shrink-0 items-center gap-1 px-1">
          <div class="flex items-center gap-1">
            <input
                type="number"
                v-model.number="goToPageInput"
                @input="validationError = null"
                :disabled="isNavigating"
                :aria-invalid="Boolean(validationError)"
                @keydown.enter="goToPage"
                :min="1"
                :max="pagination.last_page"
                :placeholder="$t('Go')"
                :aria-label="$t('Page number')"
                :class="validationError ? 'bg-red-50' : 'bg-white'"
                class="h-7 w-12 rounded border-2 px-1 py-0.5 text-center text-[11px] shadow-sm outline-none hover:bg-gray-50 focus:ring-1 sm:w-14 sm:px-2 sm:text-xs"
                :style="{ borderColor: validationError ? '#dc2626' : '#10b981', color: validationError ? '#dc2626' : '#047857', fontWeight: 500 }"
                :title="validationError || $t('Enter page number')"
            />
            <button
                @click="goToPage"
                :disabled="!goToPageInput || isNavigating"
                :class="{'opacity-50': !goToPageInput || isNavigating}"
                :aria-label="$t('Go to page')"
                class="cb-pagination-go cb-primary-button w-12 shrink-0 rounded-lg px-0 text-[11px] sm:w-14"
            >
              <span v-if="isNavigating" class="inline-block h-3 w-3 animate-spin rounded-full border-2 border-white border-t-transparent" aria-hidden="true"></span>
              <span v-else>{{ $t('Go') }}</span>
            </button>
          </div>
          <div class="pagination-page-indicator shrink-0 whitespace-nowrap text-xs text-slate-600">{{ page }} / {{ pagination.last_page }}</div>
          <span v-if="validationError" role="alert" class="absolute top-full z-10 mt-1 whitespace-nowrap rounded bg-red-50 px-2 py-1 text-[10px] text-red-600 shadow-sm ltr:left-0 rtl:right-0">{{ validationError }}</span>
        </div>
  
        <!-- Next Page Button -->
        <button
            :disabled="noNextPage || isNavigating"
            :class="{'opacity-50': noNextPage || isNavigating}"
            @click="loadPage(pagination.current_page + 1)"
            :aria-label="$t('Next page')"
            class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-700 shadow-sm outline-none hover:bg-emerald-50 hover:text-emerald-700 focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 lg:h-3 lg:w-3" :class="{ 'rotate-180': isRtl }" fill="none" viewBox="0 0 24 24"
               stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
          </svg>
        </button>
  
        <!-- Last Page Button -->
        <button v-if="!compact"
            :disabled="noNextPage || isNavigating"
            :class="{'opacity-50': noNextPage || isNavigating}"
            @click="loadPage(pagination.last_page)"
            :aria-label="$t('Last page')"
            class="hidden h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-700 shadow-sm outline-none hover:bg-emerald-50 hover:text-emerald-700 sm:inline-flex focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 lg:h-3 lg:w-3" :class="{ 'rotate-180': isRtl }" fill="none" viewBox="0 0 24 24"
               stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/>
          </svg>
        </button>
      </div>
      </div>
    </div>
  </template>
  
  <script>
  export default {
    name: 'Pagination',
    props: {
      pagination: Object,
      compact: {
        type: Boolean,
        default: false,
      },
    },
    data() {
      return {
        page: this.pagination.current_page,
        isRtl: typeof document !== 'undefined' && document.documentElement.dir === 'rtl',
        goToPageInput: null,
        isNavigating: false,
        validationError: null,
      }
    },
    watch: {
      'pagination.current_page': function(page) {
        this.page = page;
        this.goToPageInput = null; // Clear input after navigation
      }
    },
    methods: {
      loadPage(page) {
        const targetPage = Number(page);

        if (!Number.isInteger(targetPage)
          || targetPage < 1
          || targetPage > this.pagination.last_page
          || targetPage === this.pagination.current_page
          || this.isNavigating) {
          return;
        }

        this.validationError = null;
        this.isNavigating = true;
        const url = new URL(window.location.href);
        url.searchParams.set('page', targetPage);
        this.$inertia.get(url.pathname, Object.fromEntries(url.searchParams), {
          preserveState: true,
          preserveScroll: true,
          replace: true,
          onFinish: () => {
            this.isNavigating = false;
          },
        });
      },
      goToPage() {
        const targetPage = Number(this.goToPageInput);

        if (!Number.isInteger(targetPage) || targetPage < 1) {
          this.validationError = this.$t('Enter a valid page number');
          return;
        }

        if (targetPage > this.pagination.last_page) {
          this.validationError = this.$t('Maximum page is {page}', { page: this.pagination.last_page });
          return;
        }

        this.validationError = null;
        this.loadPage(targetPage);
      }
    },
    computed: {
      noPreviousPage() {
        return this.pagination.current_page - 1 <= 0;
      },
      noNextPage() {
        return this.pagination.current_page + 1 > this.pagination.last_page;
      }
    }
  };
  </script>
