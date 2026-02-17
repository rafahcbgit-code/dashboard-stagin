<template>
    <div class="inline-flex justify-center items-center">
      <div v-if="pagination.last_page > 1" class="hidden mr-2 text-sm text-gray-600 lg:block">{{ pagination.total }} items</div>
  
      <div class="flex space-x-1 items-center" v-if="pagination.last_page > 1">
        <!-- First Page Button -->
        <button
            :disabled="noPreviousPage"
            :class="{'opacity-50': noPreviousPage}"
            @click="loadPage(1)"
            class="inline-flex justify-center items-center w-11 h-11 text-gray-700 bg-white rounded border border-gray-200 shadow-sm outline-none hover:bg-gray-50 lg:h-9 lg:w-9 lg:text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
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
            class="inline-flex justify-center items-center w-11 h-11 text-gray-700 bg-white rounded border border-gray-200 shadow-sm outline-none hover:bg-gray-50 lg:h-9 lg:w-9 focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 lg:h-3 lg:w-3" fill="none" viewBox="0 0 24 24"
               stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
  
        <!-- Jump to Page Input Section -->
        <div class="flex flex-row items-center space-x-2 mx-2 px-2 border-l border-r border-gray-200">
          <div class="flex items-center space-x-1">
            <input
                type="number"
                v-model.number="goToPageInput"
                @keydown.enter="goToPage"
                :min="1"
                :max="pagination.last_page"
                placeholder="Go"
                class="w-20 px-2 py-1 text-sm text-center rounded border-2 shadow-sm outline-none hover:bg-gray-50 focus:ring-1 lg:w-16"
                style="border-color: #008000 ; color: #008000 ; font-weight: 500;"
            />
            <button
                @click="goToPage"
                :disabled="!goToPageInput"
                :class="{'opacity-50': !goToPageInput}"
                class="px-2 py-1 text-xs text-white rounded focus:ring-1 lg:text-xs"
                style="background-color: #008000;"
                @mouseover="$event.target.style.backgroundColor = '#008000'"
                @mouseout="$event.target.style.backgroundColor = '#008000'"
            >
              Go
            </button>
          </div>
          <div class="text-gray-600 text-xs lg:text-sm whitespace-nowrap">{{ page }} / {{ pagination.last_page }}</div>
        </div>
  
        <!-- Next Page Button -->
        <button
            :disabled="noNextPage"
            :class="{'opacity-50': noNextPage}"
            @click="loadPage(pagination.current_page + 1)"
            class="inline-flex justify-center items-center w-11 h-11 text-gray-700 bg-white rounded border border-gray-300 shadow-sm outline-none hover:bg-gray-50 lg:h-9 lg:w-9 focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 lg:h-3 lg:w-3" fill="none" viewBox="0 0 24 24"
               stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
          </svg>
        </button>
  
        <!-- Last Page Button -->
        <button
            :disabled="noNextPage"
            :class="{'opacity-50': noNextPage}"
            @click="loadPage(pagination.last_page)"
            class="inline-flex justify-center items-center w-11 h-11 text-gray-700 bg-white rounded border border-gray-300 shadow-sm outline-none hover:bg-gray-50 lg:h-9 lg:w-9 focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 lg:h-3 lg:w-3" fill="none" viewBox="0 0 24 24"
               stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/>
          </svg>
        </button>
      </div>
    </div>
  </template>
  
  <script>
  export default {
    name: 'Pagination',
    props: {
      pagination: Object,
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
        this.$inertia.get(this.$page.url, {page: page}, {
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