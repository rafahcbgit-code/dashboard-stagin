<template>
    <div class="flex w-full min-w-0 justify-center">
      <div class="flex max-w-full min-w-0 items-center gap-2">
      <div v-if="pagination.last_page > 1" class="shrink-0 whitespace-nowrap text-xs text-gray-600 sm:text-sm">{{ pagination.total }} items</div>

      <div class="flex min-w-0 shrink flex-nowrap items-center gap-1" v-if="pagination.last_page > 1">
        <!-- First Page Button -->
        <button v-if="!compact"
            :disabled="noPreviousPage"
            :class="{'opacity-50': noPreviousPage}"
            @click="loadPage(1)"
            aria-label="First page"
            class="hidden shrink-0 justify-center items-center h-9 w-9 text-gray-700 bg-white rounded border border-gray-200 shadow-sm outline-none hover:bg-gray-50 sm:inline-flex focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 lg:h-3 lg:w-3" fill="none" viewBox="0 0 24 24"
               stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
          </svg>
        </button>

        <!-- Previous Page Button -->
        <button
            :disabled="noPreviousPage"
            :class="{'opacity-50': noPreviousPage}"
            @click="loadPage(pagination.current_page - 1)"
            aria-label="Previous page"
            class="inline-flex shrink-0 justify-center items-center h-9 w-9 text-gray-700 bg-white rounded border border-gray-200 shadow-sm outline-none hover:bg-gray-50 focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 lg:h-3 lg:w-3" fill="none" viewBox="0 0 24 24"
               stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
  
        <!-- Jump to Page Input Section -->
        <div :class="compact ? 'shrink-0' : 'shrink'" class="flex min-w-0 items-center gap-1 border-l border-r border-gray-200 px-1 sm:mx-1 sm:px-2">
          <div class="flex items-center space-x-1">
            <input
                type="number"
                v-model.number="goToPageInput"
                @keydown.enter="goToPage"
                :min="1"
                :max="pagination.last_page"
                placeholder="Go"
                aria-label="Page number"
                class="w-14 px-1 py-1 text-xs text-center rounded border-2 shadow-sm outline-none hover:bg-gray-50 focus:ring-1 sm:w-16 sm:px-2 sm:text-sm"
                style="border-color: #008000 ; color: #008000 ; font-weight: 500;"
            />
            <button
                @click="goToPage"
                :disabled="!goToPageInput"
                :class="{'opacity-50': !goToPageInput}"
                aria-label="Go to page"
                class="shrink-0 px-2 py-1 text-xs text-white rounded focus:ring-1"
                style="background-color: #008000;"
                @mouseover="$event.target.style.backgroundColor = '#008000'"
                @mouseout="$event.target.style.backgroundColor = '#008000'"
            >
              Go
            </button>
          </div>
          <div class="shrink-0 text-gray-600 text-xs whitespace-nowrap">{{ page }} / {{ pagination.last_page }}</div>
        </div>
  
        <!-- Next Page Button -->
        <button
            :disabled="noNextPage"
            :class="{'opacity-50': noNextPage}"
            @click="loadPage(pagination.current_page + 1)"
            aria-label="Next page"
            class="inline-flex shrink-0 justify-center items-center h-9 w-9 text-gray-700 bg-white rounded border border-gray-300 shadow-sm outline-none hover:bg-gray-50 focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 lg:h-3 lg:w-3" fill="none" viewBox="0 0 24 24"
               stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
          </svg>
        </button>
  
        <!-- Last Page Button -->
        <button v-if="!compact"
            :disabled="noNextPage"
            :class="{'opacity-50': noNextPage}"
            @click="loadPage(pagination.last_page)"
            aria-label="Last page"
            class="hidden shrink-0 justify-center items-center h-9 w-9 text-gray-700 bg-white rounded border border-gray-300 shadow-sm outline-none hover:bg-gray-50 sm:inline-flex focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 lg:h-3 lg:w-3" fill="none" viewBox="0 0 24 24"
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
        goToPageInput: null
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
        const url = new URL(window.location.href);
        url.searchParams.set('page', page);
        this.$inertia.get(url.pathname, Object.fromEntries(url.searchParams), {
          preserveState: true
        });
      },
      goToPage() {
        // Validate input
        if (!this.goToPageInput || this.goToPageInput < 1) {
          alert('Please enter a valid page number');
          return;
        }

        if (this.goToPageInput > this.pagination.last_page) {
          alert(`Page number cannot exceed ${this.pagination.last_page}`);
          return;
        }

        // Load the requested page
        this.loadPage(this.goToPageInput);
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
