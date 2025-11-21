<script setup>
import { ref, onMounted, watch } from 'vue';
import axios from 'axios';
import { trans } from 'laravel-vue-i18n';

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

const fetchReplies = async (pageNumber = 1) => {
    loading.value = true;
    try {
        const response = await axios.get('/pre-created-replies', {
            params: {
                page: pageNumber,
                search: search.value
            },
            headers: {
                'Accept': 'application/json'
            }
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

const selectReply = (reply) => {
    let payload = {
        uuid: props.contactUuid,
        message: null,
        type: 'text',
        header: null,
        footer: null,
        buttons: [],
        button_label: null
    };

    const content = reply.content; // Already parsed JSON/Array due to model casting

    if (reply.type === 'text') {
        payload.type = 'text';
        payload.message = content.body;
    } else if (reply.type === 'quick_reply') {
        payload.type = 'interactive buttons';
        payload.message = content.body;
        payload.buttons = content.buttons.map((btn, index) => ({
            id: `btn_${Date.now()}_${index}`,
            title: btn.text
        }));
    } else if (reply.type === 'header_footer') {
        payload.type = 'interactive buttons';
        payload.message = content.body;
        if (content.header) {
            payload.header = {
                type: 'text',
                text: content.header
            };
        }
        if (content.footer) {
            payload.footer = content.footer;
        }
        payload.buttons = content.buttons.map((btn, index) => ({
            id: `btn_${Date.now()}_${index}`,
            title: btn.text
        }));
    } else if (reply.type === 'link') {
        payload.type = 'interactive call to action url';
        payload.message = content.body;
        payload.buttons = {
            display_text: content.link_text,
            url: content.link_url
        };
    }

    emit('send', payload);
    emit('close');
};

watch(() => props.show, (newVal) => {
    if (newVal) {
        fetchReplies();
    }
});

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
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" @click="$emit('close')"></div>

            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                            <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">
                                {{ $t('Select a Reply') }}
                            </h3>
                            <div class="mt-2">
                                <input 
                                    type="text" 
                                    v-model="search" 
                                    @input="fetchReplies(1)" 
                                    :placeholder="$t('Search replies...')" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm mb-4"
                                >

                                <div v-if="loading" class="text-center py-4">
                                    <svg class="animate-spin h-5 w-5 text-gray-500 mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                </div>

                                <div v-else-if="replies.length === 0" class="text-center py-4 text-gray-500">
                                    {{ $t('No replies found.') }}
                                </div>

                                <ul v-else class="divide-y divide-gray-200 max-h-60 overflow-y-auto">
                                    <li v-for="reply in replies" :key="reply.uuid" @click="selectReply(reply)" class="py-3 cursor-pointer hover:bg-gray-50 px-2 rounded">
                                        <div class="flex justify-between">
                                            <span class="font-medium text-sm text-gray-900">{{ reply.title }}</span>
                                            <span class="text-xs text-gray-500 capitalize">{{ reply.type.replace('_', ' ') }}</span>
                                        </div>
                                        <p class="text-xs text-gray-500 truncate mt-1">{{ reply.content.body }}</p>
                                    </li>
                                </ul>

                                <!-- Pagination -->
                                <div v-if="lastPage > 1" class="flex justify-between mt-4">
                                    <button @click="prevPage" :disabled="page === 1" class="text-sm text-indigo-600 disabled:text-gray-400">{{ $t('Previous') }}</button>
                                    <span class="text-xs text-gray-500">{{ page }} / {{ lastPage }}</span>
                                    <button @click="nextPage" :disabled="page === lastPage" class="text-sm text-indigo-600 disabled:text-gray-400">{{ $t('Next') }}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm" @click="$emit('close')">
                        {{ $t('Cancel') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
