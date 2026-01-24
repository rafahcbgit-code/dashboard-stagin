<script setup>
import axios from 'axios';
import { ref, watch, onMounted } from 'vue';
import debounce from 'lodash/debounce';
import { useChatFilterStore, setChatFilters } from '@/stores/chatFilterStore.js';
import { Link, router } from "@inertiajs/vue3";
import Pagination from '@/Components/Pagination.vue';
import TicketStatusToggle from '@/Components/TicketStatusToggle.vue';
import SortDirectionToggle from '@/Components/SortDirectionToggle.vue';

const props = defineProps({
    rows: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object
    },
    rowCount: {
        type: Number,
        required: true,
    },
    ticketingIsEnabled: {
        type: Boolean
    },
    status: {
        type: String,
    },
    chatSortDirection: {
        type: String
    },
    teamRole: {
        type: String,
        default: 'owner'
    }
    ,
    selectedContactUuid: {
        type: [String, Number],
        default: null,
    }
});

const isSearching = ref(false);
const selectedContact = ref(props.selectedContactUuid || null);
const availableLabels = ref([]);
const selectedLabels = ref([]);
const showLabelFilter = ref(false);
const loadingLabels = ref(false);
const availableTeamMembers = ref([]);
const selectedTeamMember = ref(null);
const showTeamFilter = ref(false);
const loadingTeamMembers = ref(false);
const emit = defineEmits(['view']);

function viewChat(contact) {
    selectedContact.value = contact.uuid || contact;
    emit('view', contact);
}

// Keep local selection in sync with parent prop
watch(() => props.selectedContactUuid, (val) => {
    selectedContact.value = val;
});

const selectContact = (uuid) => {
    selectedContact.value = uuid;
}

const contentType = (metadata) => {
    try {
        if (!metadata) return null;
        const chatData = JSON.parse(metadata);
        return chatData?.type ?? null;
    } catch (e) {
        return null;
    }
}

const content = (metadata) => {
    try {
        if (!metadata) return {};
        return JSON.parse(metadata) || {};
    } catch (e) {
        return {};
    }
}

const getExtension = (fileFormat) => {
    const formatMap = {
        'text/plain': 'TXT',
        'application/pdf': 'PDF',
        'application/vnd.ms-powerpoint': 'PPT',
        'application/msword': 'DOC',
        'application/vnd.ms-excel': 'XLS',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'DOCX',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'PPTX',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'XLSX',
    };

    return formatMap[fileFormat] || 'Unknown';
};

const getContactDisplayName = (metadata) => {
    try {
        if (!metadata) return 'No contacts available';
        const parsed = JSON.parse(metadata) || {};
        const contacts = parsed.contacts || [];
        if (contacts.length === 1) {
            const contact = contacts[0];
            return contact?.name?.formatted_name || `${contact?.name?.first_name || ''} ${contact?.name?.last_name || ''}`.trim();
        } else if (contacts.length > 1) {
            const firstName = contacts[0]?.name?.first_name || '';
            const otherContactsCount = contacts.length - 1;
            return `${firstName} and ${otherContactsCount} other contacts`;
        } else {
            return 'No contacts available';
        }
    } catch (e) {
        return 'No contacts available';
    }
}

const formatTime = (time) => {
    if (!time) {
        return "Invalid time";
    } else {
        const currentTime = new Date();
        const targetTime = new Date(time);
        const timeDiff = currentTime - targetTime;

        // Check if the time is today
        if (
            targetTime.getDate() === currentTime.getDate() &&
            targetTime.getMonth() === currentTime.getMonth() &&
            targetTime.getFullYear() === currentTime.getFullYear()
        ) {
            return formatDate(targetTime, 'h:mm a');
        }

        // Check if the time is yesterday
        const yesterday = new Date();
        yesterday.setDate(currentTime.getDate() - 1);
        if (
            targetTime.getDate() === yesterday.getDate() &&
            targetTime.getMonth() === yesterday.getMonth() &&
            targetTime.getFullYear() === yesterday.getFullYear()
        ) {
            return 'Yesterday';
        }

        // If beyond yesterday, return the time in the format d/m/y
        return formatDate(targetTime, 'd/m/y');
    }
};

const formatDate = (date, format) => {
    let options = null;
    if (format === 'h:mm a') {
        options = { hour12: true, hour: 'numeric', minute: 'numeric' };
    } else {
        options = { day: 'numeric', month: 'numeric', year: 'numeric' };
    }

    return new Intl.DateTimeFormat('en-US', options).format(date);
};

const queryParamsString = window.location.search;

// Watch for changes in the query parameters
watch(() => queryParamsString,
    (newQuery, oldQuery) => {
        console.log('Query parameters changed:', newQuery);
        // Perform actions based on query parameter changes
    }
);

const filterStore = useChatFilterStore();

const params = ref({
    search: props.filters?.search ?? null,
    labels: props.filters?.labels ?? null,
    assigned_to: props.filters?.assigned_to ?? null,
    status: props.filters?.status ?? null,
});

const search = debounce(() => {
    isSearching.value = true;
    runSearch();
}, 1000);

const runSearch = () => {
    const url = window.location.pathname;

    router.visit(url, {
        method: 'get',
        data: params.value,
    })
}

const clearSearch = () => {
    params.value.search = null;
    runSearch();
}

// Label filtering functions
const loadLabels = async () => {
    if (availableLabels.value.length > 0) {
        showLabelFilter.value = !showLabelFilter.value;
        return;
    }

    loadingLabels.value = true;
    try {
        const response = await axios.get('/chats/labels/filter-data');
        availableLabels.value = response.data;
        showLabelFilter.value = true;
    } catch (error) {
        console.error('Error loading labels:', error);
    } finally {
        loadingLabels.value = false;
    }
};

const toggleLabel = (labelId) => {
    const index = selectedLabels.value.indexOf(labelId);
    if (index > -1) {
        selectedLabels.value.splice(index, 1);
    } else {
        selectedLabels.value.push(labelId);
    }

    // Update params and trigger search
    params.value.labels = selectedLabels.value.length > 0 ? selectedLabels.value.join(',') : null;
    // persist to shared store
    setChatFilters({ search: params.value.search, labels: params.value.labels, assigned_to: params.value.assigned_to });
    runSearch();
};

const clearLabelFilters = () => {
    selectedLabels.value = [];
    params.value.labels = null;
    setChatFilters({ search: params.value.search, labels: null, assigned_to: params.value.assigned_to, status: params.value.status });
    runSearch();
};

// Team member filtering functions
const loadTeamMembers = async () => {
    if (availableTeamMembers.value.length > 0) {
        showTeamFilter.value = !showTeamFilter.value;
        return;
    }

    loadingTeamMembers.value = true;
    try {
        const response = await axios.get('/chats/team-members/filter-data');
        console.log('Team members response:', response.data);
        availableTeamMembers.value = response.data;
        showTeamFilter.value = true;
    } catch (error) {
        console.error('Error loading team members:', error);
        alert('Error loading team members: ' + error.message);
    } finally {
        loadingTeamMembers.value = false;
    }
};

const selectTeamMember = (userId) => {
    selectedTeamMember.value = userId;
    params.value.assigned_to = userId;
    setChatFilters({ search: params.value.search, labels: params.value.labels, assigned_to: params.value.assigned_to, status: params.value.status });
    runSearch();
    showTeamFilter.value = false;
};

const clearTeamFilter = () => {
    selectedTeamMember.value = null;
    params.value.assigned_to = null;
    setChatFilters({ search: params.value.search, labels: params.value.labels, assigned_to: null, status: params.value.status });
    runSearch();
};

// Initialize selected labels and team member from URL params
onMounted(() => {
    // Initialize from shared store if present, otherwise use props
    if (filterStore.labels) {
        params.value.labels = filterStore.labels;
        selectedLabels.value = filterStore.labels.split(',').map(id => parseInt(id));
    } else if (props.filters?.labels) {
        params.value.labels = props.filters.labels;
        selectedLabels.value = props.filters.labels.split(',').map(id => parseInt(id));
        setChatFilters({ search: params.value.search, labels: params.value.labels, assigned_to: params.value.assigned_to });
    }

    if (filterStore.assigned_to) {
        params.value.assigned_to = filterStore.assigned_to;
        selectedTeamMember.value = parseInt(filterStore.assigned_to);
    } else if (props.filters?.assigned_to) {
        params.value.assigned_to = props.filters.assigned_to;
        selectedTeamMember.value = parseInt(props.filters.assigned_to);
        setChatFilters({ search: params.value.search, labels: params.value.labels, assigned_to: params.value.assigned_to });
    }

    // Add click outside listener
    document.addEventListener('click', handleClickOutside);
});

// Handle click outside to close dropdown
const handleClickOutside = (event) => {
    const labelFilterElement = event.target.closest('.label-filter-container');
    const teamFilterElement = event.target.closest('.team-filter-container');
    if (!labelFilterElement && showLabelFilter.value) {
        showLabelFilter.value = false;
    }
    if (!teamFilterElement && showTeamFilter.value) {
        showTeamFilter.value = false;
    }
};

const buildChatHref = (contact) => {
    const page = props.rows.meta?.current_page || 1;
    const qs = new URLSearchParams();
    qs.set('page', page);
    if (params.value.labels) qs.set('labels', params.value.labels);
    if (params.value.assigned_to) qs.set('assigned_to', params.value.assigned_to);
    if (params.value.status) qs.set('status', params.value.status);
    if (params.value.search) qs.set('search', params.value.search);
    return '/chats/' + contact.uuid + '?' + qs.toString();
};

</script>
<template>
    <div class="px-4 py-4 border-b">
        <div class="flex items-center justify-between space-x-1 text-xl">
            <div class="flex space-x-1">
                <h2>{{ $t('Chats') }}</h2>
                <span class="text-slate-500">{{ rowCount }}</span>
            </div>
        </div>
        <div class="bg-slate-50 rounded-md mt-3 flex items-center py-[2px]">
            <div class="pl-3 py-2">
                <svg class="text-slate-600" xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                    viewBox="0 0 24 24">
                    <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                        stroke-width="2" d="M3 10a7 7 0 1 0 14 0a7 7 0 1 0-14 0m18 11l-6-6" />
                </svg>
            </div>
            <input @input="search" v-model="params.search"
                class="w-full bg-slate-50 outline-none rounded-xl py-2 pl-2 mr-2 text-sm" type="text"
                :placeholder="$t('Search name or number...')">
            <button v-if="isSearching === false && params.search" @click="clearSearch" type="button" class="pr-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                    <path fill="currentColor"
                        d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10s10-4.5 10-10S17.5 2 12 2zm3.7 12.3c.4.4.4 1 0 1.4c-.4.4-1 .4-1.4 0L12 13.4l-2.3 2.3c-.4.4-1 .4-1.4 0c-.4-.4-.4-1 0-1.4l2.3-2.3l-2.3-2.3c-.4-.4-.4-1 0-1.4c.4-.4 1-.4 1.4 0l2.3 2.3l2.3-2.3c.4-.4 1-.4 1.4 0c.4.4.4 1 0 1.4L13.4 12l2.3 2.3z" />
                </svg>
            </button>
            <span v-if="isSearching" class="pr-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                    <circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0">
                        <animateTransform attributeName="transform" calcMode="discrete" dur="2.4s"
                            repeatCount="indefinite" type="rotate" values="0 12 12;90 12 12;180 12 12;270 12 12" />
                        <animate attributeName="opacity" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite"
                            values="1;1;0" />
                    </circle>
                    <circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0">
                        <animateTransform attributeName="transform" begin="0.2s" calcMode="discrete" dur="2.4s"
                            repeatCount="indefinite" type="rotate" values="30 12 12;120 12 12;210 12 12;300 12 12" />
                        <animate attributeName="opacity" begin="0.2s" dur="0.6s" keyTimes="0;0.5;1"
                            repeatCount="indefinite" values="1;1;0" />
                    </circle>
                    <circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0">
                        <animateTransform attributeName="transform" begin="0.4s" calcMode="discrete" dur="2.4s"
                            repeatCount="indefinite" type="rotate" values="60 12 12;150 12 12;240 12 12;330 12 12" />
                        <animate attributeName="opacity" begin="0.4s" dur="0.6s" keyTimes="0;0.5;1"
                            repeatCount="indefinite" values="1;1;0" />
                    </circle>
                </svg>
            </span>
        </div>

        <!-- Filter Section -->
        <div class="mt-3 flex items-center gap-2">
            <!-- Label Filter -->
            <div class="relative label-filter-container">
                <button @click="loadLabels" :disabled="loadingLabels"
                    class="flex items-center space-x-1 px-3 py-1 bg-gray-100 hover:bg-gray-200 rounded-md text-sm transition-colors">
                    <svg v-if="loadingLabels" class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg"
                        fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                        </circle>
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                        </path>
                    </svg>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2" d="M20.59 13.41 12 22 4 14V4h10l8.59 8.59zM7 7h.01" />
                    </svg>
                    <span>{{ $t('Filter by Labels') }}</span>
                    <span v-if="selectedLabels.length > 0"
                        class="bg-green-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">
                        {{ selectedLabels.length }}
                    </span>
                </button>

                <!-- Label Filter Dropdown -->
                <div v-if="showLabelFilter && availableLabels.length > 0"
                    class="absolute top-full left-0 mt-1 w-64 bg-white border border-gray-200 rounded-md shadow-lg z-50 max-h-60 overflow-y-auto">
                    <div class="p-2">
                        <div class="text-sm font-medium text-gray-700 mb-2">{{ $t('Select Labels') }}</div>
                        <div class="space-y-2">
                            <div v-for="label in availableLabels" :key="label.id"
                                class="flex items-center space-x-2 hover:bg-gray-50 p-1 rounded">
                                <input type="checkbox" :id="`filter-label-${label.id}`"
                                    :checked="selectedLabels.includes(label.id)" @change="toggleLabel(label.id)"
                                    class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 focus:ring-2">
                                <label :for="`filter-label-${label.id}`"
                                    class="flex items-center space-x-2 cursor-pointer flex-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
                                        <path fill="none" :stroke="label.color" stroke-linecap="round"
                                            stroke-linejoin="round" stroke-width="2"
                                            d="M20.59 13.41 12 22 4 14V4h10l8.59 8.59zM7 7h.01" />
                                    </svg>
                                    <span class="text-sm">{{ label.title }}</span>
                                    <span class="text-xs text-gray-500 ml-auto">({{ label.contacts_count }})</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Team Member Filter -->
            <div v-if="props.teamRole !== 'agent'" class="relative team-filter-container">
                <button @click="loadTeamMembers" :disabled="loadingTeamMembers"
                    class="flex items-center space-x-1 px-3 py-1 bg-gray-100 hover:bg-gray-200 rounded-md text-sm transition-colors">
                    <svg v-if="loadingTeamMembers" class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg"
                        fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                        </circle>
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                        </path>
                    </svg>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                    <span>{{ selectedTeamMember ? 'Team: 1' : 'Team' }}</span>
                    <svg class="w-3 h-3" :class="{ 'rotate-180': showTeamFilter }" xmlns="http://www.w3.org/2000/svg"
                        fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <!-- Team Member dropdown -->
                <div v-if="showTeamFilter"
                    class="absolute top-full left-0 mt-1 w-64 bg-white rounded-lg shadow-lg border border-gray-200 z-50 max-h-96 overflow-y-auto">
                    <div class="p-3 border-b border-gray-200">
                        <div class="flex items-center justify-between">
                            <h3 class="font-semibold text-sm">{{ $t('Filter by Team Member') }}</h3>
                            <button v-if="selectedTeamMember" @click="clearTeamFilter"
                                class="text-xs text-blue-600 hover:text-blue-800">
                                {{ $t('Clear') }}
                            </button>
                        </div>
                    </div>
                    <div v-if="availableTeamMembers.length === 0" class="p-4 text-center text-gray-500 text-sm">
                        {{ $t('No team members found') }}
                    </div>
                    <div v-else class="p-2">
                        <button v-for="member in availableTeamMembers" :key="member.user_id"
                            @click="selectTeamMember(member.user_id)"
                            class="w-full text-left px-3 py-2 hover:bg-gray-50 rounded flex items-center justify-between group transition-colors">
                            <div class="flex items-center space-x-2">
                                <div
                                    class="w-8 h-8 rounded-full bg-secondary/10 text-secondary flex items-center justify-center text-sm font-semibold">
                                    {{ (member.first_name || 'U').substring(0, 1).toUpperCase() }}
                                </div>
                                <div>
                                    <div class="text-sm font-medium">{{ member.first_name || 'Unknown' }}</div>
                                    <div class="text-xs text-gray-500">{{ member.role }}</div>
                                </div>
                            </div>
                            <svg v-if="selectedTeamMember === member.user_id" class="w-4 h-4 text-secondary"
                                fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                    clip-rule="evenodd" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Clear filters button -->
            <button v-if="selectedLabels.length > 0 || selectedTeamMember"
                @click="clearLabelFilters(); clearTeamFilter()" class="text-red-500 hover:text-red-700 text-sm">
                {{ $t('Clear all filters') }}
            </button>
        </div>


        <div v-if="ticketingIsEnabled" class="grid grid-cols-2 mt-4 items-center w-full">
            <TicketStatusToggle :status="status" :rowCount="rowCount" :teamRole="props.teamRole" />
            <div class="flex ml-auto gap-x-1">
                <!--<span class="cursor-pointer hover:bg-slate-50 p-1 rounded-full">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 5.6c0-.56 0-.84-.11-1.054a.998.998 0 0 0-.436-.437C19.24 4 18.96 4 18.4 4H5.6c-.56 0-.84 0-1.054.109a1 1 0 0 0-.437.437C4 4.76 4 5.04 4 5.6v.737c0 .245 0 .367.028.482a1 1 0 0 0 .12.29c.061.1.148.187.32.36l5.063 5.062c.173.173.26.26.321.36c.055.09.096.188.12.29c.028.114.028.235.028.474v4.756c0 .857 0 1.286.18 1.544a1 1 0 0 0 .674.416c.311.046.695-.145 1.461-.529l.8-.4c.322-.16.482-.24.599-.36a1 1 0 0 0 .231-.374c.055-.158.055-.338.055-.697v-4.348c0-.245 0-.367.028-.482a.998.998 0 0 1 .12-.29c.06-.1.147-.186.317-.356l.004-.004l5.063-5.062c.172-.173.258-.26.32-.36a.994.994 0 0 0 .12-.29C20 6.706 20 6.584 20 6.345z"/></svg>
                </span>-->
                <SortDirectionToggle :direction="props.chatSortDirection" :url="'/chats/update-sort-direction'" />
            </div>
        </div>
    </div>
    <div class="flex-grow overflow-y-auto h-[65vh]" ref="scrollContainer">

        <Link @click="selectContact(contact.uuid)"
            :href="'/chats/' + contact.uuid + '?page=' + props.rows.meta.current_page"
            :class="['block border-b group-hover:pr-0', contact.unread_messages > 0 ? 'bg-green-50' : '', selectedContact === contact.uuid ? 'bg-green-100' : '']"
            v-for="(contact, index) in rows.data" :key="index">

            <!-- <Link :href="buildChatHref(contact)" class="block border-b group-hover:pr-0" :class="contact.unread_messages > 0 ? 'bg-green-50' : ''" v-for="(contact, index) in rows.data" :key="index"> -->

            <div class="flex space-x-2 hover:bg-gray-50 cursor-pointer py-3 px-4">
                <div class="w-[15%]">
                    <img v-if="contact.avatar" class="rounded-full w-10 h-10" :src="contact.avatar">
                    <div v-else class="rounded-full w-10 h-10 flex items-center justify-center bg-slate-200 capitalize">
                        {{ contact.full_name.substring(0, 1) }}</div>
                </div>
                <div class="w-[85%]">

                    <div class="flex items-center gap-1 w-full">
                        <div class="flex flex-col flex-1 min-w-0">

                            <div class="flex items-center gap-2">
                                <h3 class="font-small text-gray-900 whitespace-nowrap shrink-0">
                                    {{ contact.full_name }}
                                </h3>

                                <div class="flex space-x-1 min-w-0 overflow-hidden">
                                    <svg v-for="(label, index) in contact.labels" :key="index"
                                        xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink" viewBox="0 0 24 24">
                                        <path fill="none" :stroke="label.color" stroke-linecap="round"
                                            stroke-linejoin="round" stroke-width="4" d="M5 12h14" />
                                    </svg>
                                </div>
                            </div>

                            <span v-if="contact?.latest_assigned_user?.full_name"
                                class="inline-flex w-fit text-sm truncate bg-gray-200 text-gray-800 px-2 py-0.5 rounded-full">
                                {{ contact.latest_assigned_user.full_name }}
                            </span>
                        </div>

                        <span class="text-gray-400 text-xs whitespace-nowrap ml-auto">
                            {{ formatTime(contact?.last_chat?.created_at) }}
                        </span>
                    </div>


                    <div v-if="contact?.last_chat?.deleted_at === null" class="flex justify-between">
                        <div v-if="contentType(contact?.last_chat?.metadata) === 'text'"
                            class="text-slate-500 text-xs truncate self-end"> {{
                                content(contact?.last_chat?.metadata)?.text?.body || contact?.last_chat?.body || $t('No
                            message') }}</div>
                        <div v-if="contentType(contact?.last_chat?.metadata) === 'button'"
                            class="text-slate-500 text-xs truncate self-end"> {{
                                content(contact?.last_chat?.metadata)?.button?.text || contact?.last_chat?.body || $t('No
                            message') }}</div>
                        <div v-if="contentType(contact?.last_chat?.metadata) === 'interactive'"
                            class="text-slate-500 text-xs truncate self-end"> {{
                                content(contact?.last_chat?.metadata)?.interactive?.button_reply?.title ||
                                content(contact?.last_chat?.metadata)?.interactive?.list_reply?.title ||
                            contact?.last_chat?.body || $t('No message') }}</div>

                        <div v-if="contentType(contact?.last_chat?.metadata) === 'reaction'"
                            class="text-slate-500 text-xs truncate self-end">
                            <div class="flex items-center gap-2">
                                <span class="text-lg">{{ content(contact?.last_chat?.metadata)?.reaction?.emoji || '👍'
                                    }}</span>
                                <span class="text-xs text-slate-500">{{ $t('Reacted') }}</span>
                            </div>
                        </div>

                        <div v-if="contentType(contact?.last_chat?.metadata) === 'image'"
                            class="text-slate-500 text-xs truncate self-end">
                            <div class="flex items-center">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                                    <path fill="currentColor"
                                        d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.9 13.98l2.1 2.53l3.1-3.99c.2-.26.6-.26.8.01l3.51 4.68a.5.5 0 0 1-.4.8H6.02c-.42 0-.65-.48-.39-.81L8.12 14c.19-.26.57-.27.78-.02z" />
                                </svg>
                                <span class="ml-2">{{ $t('Photo') }}</span>
                            </div>
                        </div>
                        <div v-if="contentType(contact?.last_chat?.metadata) === 'document'"
                            class="text-slate-500 text-xs truncate self-end">
                            <div class="flex items-center">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                                    <path fill="currentColor" fill-rule="evenodd"
                                        d="M14.25 2.5a.25.25 0 0 0-.25-.25H7A2.75 2.75 0 0 0 4.25 5v14A2.75 2.75 0 0 0 7 21.75h10A2.75 2.75 0 0 0 19.75 19V9.147a.25.25 0 0 0-.25-.25H15a.75.75 0 0 1-.75-.75V2.5Zm.75 9.75a.75.75 0 0 1 0 1.5H9a.75.75 0 0 1 0-1.5h6Zm0 4a.75.75 0 0 1 0 1.5H9a.75.75 0 0 1 0-1.5h6Z"
                                        clip-rule="evenodd" />
                                    <path fill="currentColor"
                                        d="M15.75 2.824c0-.184.193-.301.336-.186c.121.098.23.212.323.342l3.013 4.197c.068.096-.006.22-.124.22H16a.25.25 0 0 1-.25-.25V2.824Z" />
                                </svg>
                                <span class="ml-2">{{ getExtension(contact?.last_chat?.media.type) }} {{ $t('File')
                                    }}</span>
                            </div>
                        </div>
                        <div v-if="contentType(contact?.last_chat?.metadata) === 'video'"
                            class="text-slate-500 text-xs truncate self-end">
                            <div class="flex items-center">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16">
                                    <path fill="currentColor"
                                        d="M3.5 2.5A2.5 2.5 0 0 0 1 5v6a2.5 2.5 0 0 0 2.5 2.5h5A2.5 2.5 0 0 0 11 11V5a2.5 2.5 0 0 0-2.5-2.5h-5Zm10.684 1.61L12 5.893v4.215l2.184 1.78a.5.5 0 0 0 .816-.389v-7a.5.5 0 0 0-.816-.387Z" />
                                </svg>
                                <span class="ml-2">{{ $t('Video') }}</span>
                            </div>
                        </div>
                        <div v-if="contentType(contact?.last_chat?.metadata) === 'audio'"
                            class="text-slate-500 text-xs truncate self-end">
                            <div class="flex items-center">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 512 512">
                                    <path fill="currentColor"
                                        d="M256 80C149.9 80 62.4 159.4 49.6 262c9.4-3.8 19.6-6 30.4-6c26.5 0 48 21.5 48 48v128c0 26.5-21.5 48-48 48c-44.2 0-80-35.8-80-80V288C0 146.6 114.6 32 256 32s256 114.6 256 256v112c0 44.2-35.8 80-80 80c-26.5 0-48-21.5-48-48V304c0-26.5 21.5-48 48-48c10.8 0 21 2.1 30.4 6C449.6 159.4 362.1 80 256 80z" />
                                </svg>
                                <span class="ml-2">{{ $t('Audio') }}</span>
                            </div>
                        </div>
                        <div v-if="contentType(contact?.last_chat?.metadata) === 'sticker'"
                            class="text-slate-500 text-xs truncate self-end">
                            <div class="flex items-center">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 256 256">
                                    <path fill="currentColor"
                                        d="M168 32H88a56.06 56.06 0 0 0-56 56v80a56.06 56.06 0 0 0 56 56h48a8.07 8.07 0 0 0 2.53-.41c26.23-8.75 76.31-58.83 85.06-85.06A8.07 8.07 0 0 0 224 136V88a56.06 56.06 0 0 0-56-56Zm-32 175.42V176a40 40 0 0 1 40-40h31.42c-9.26 21.55-49.87 62.16-71.42 71.42Z" />
                                </svg>
                                <span class="ml-2">{{ $t('Sticker') }}</span>
                            </div>
                        </div>
                        <div v-if="contentType(contact?.last_chat?.metadata) === 'contacts'"
                            class="text-slate-500 text-xs truncate self-end">
                            <div class="flex items-center">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16">
                                    <path fill="currentColor"
                                        d="M3 14s-1 0-1-1s1-4 6-4s6 3 6 4s-1 1-1 1H3Zm5-6a3 3 0 1 0 0-6a3 3 0 0 0 0 6Z" />
                                </svg>
                                <span class="ml-2">
                                    {{ getContactDisplayName(contact?.last_chat?.metadata) }}
                                </span>
                            </div>
                        </div>
                        <div v-if="contentType(contact?.last_chat?.metadata) === 'location'"
                            class="text-slate-500 text-xs truncate self-end">
                            <div class="flex items-center">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16">
                                    <path fill="currentColor"
                                        d="M9.156 14.544C10.899 13.01 14 9.876 14 7A6 6 0 0 0 2 7c0 2.876 3.1 6.01 4.844 7.544a1.736 1.736 0 0 0 2.312 0ZM6 7a2 2 0 1 1 4 0a2 2 0 0 1-4 0Z" />
                                </svg>
                                <span class="ml-2">{{ $t('Location') }}</span>
                            </div>
                        </div>
                        <span v-if="contact.unread_messages > 0"
                            class="bg-green-600 text-white rounded-md py-[1px] px-[8px] min-w-10 text-[10px] flex items-center justify-center">{{
                            contact.unread_messages }}</span>
                    </div>
                </div>
            </div>
        </Link>
    </div>
    <div class="px-4 pb-4">
        <Pagination class="mt-3" :pagination="rows.meta" />
    </div>
</template>