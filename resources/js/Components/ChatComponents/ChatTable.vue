<script setup>
import axios from 'axios';
import { ref, watch, onMounted } from 'vue';
import debounce from 'lodash/debounce';
import { Link, router } from "@inertiajs/vue3";
import Pagination from '@/Components/Pagination.vue';
import SortDirectionToggle from '@/Components/SortDirectionToggle.vue';
import SendTemplateToPhone from '@/Components/ChatComponents/SendTemplateToPhone.vue';
import { parseChatMetadata as parseMetadata, documentExtension } from '@/Components/ChatComponents/chatPreview.js';
import { useChatFilterStore } from '@/stores/chatFilterStore.js';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

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
    },
    selectedContactUuid: {
        type: String,
        default: null
    },
    templates: {
        type: Array,
        default: () => []
    },
    contactFields: {
        type: [Array, Object],
        default: () => []
    }
});

const isSearching = ref(false);
const selectedContact = ref(null);
const availableLabels = ref([]);
const selectedLabels = ref([]);
const showLabelFilter = ref(false);
const loadingLabels = ref(false);
const availableTeamMembers = ref([]);
const selectedTeamMember = ref(null);
const showTeamMemberFilter = ref(false);
const loadingTeamMembers = ref(false);
const showStatusFilter = ref(false);

// Temporary filter states (before applying)
const tempSelectedLabels = ref([]);
const tempSelectedTeamMember = ref(null);
const tempSelectedStatus = ref(props.status || 'all');
const tempAwaitingReply = ref(
    props.filters.awaiting_reply === true
    || props.filters.awaiting_reply === 1
    || props.filters.awaiting_reply === '1'
);
const hasUnappliedFilters = ref(false);

const emit = defineEmits(['view']);

function viewChat(contact) {
    selectedContact.value = contact;
    emit('view', contact);
}

const handleQuickSendSent = () => {
    // Optional: refresh or notify parent if needed.
};

const contentType = (metadata) => {
    return parseMetadata(metadata).type;
}

const content = (metadata) => {
    return parseMetadata(metadata);
}

const isDeletedMessagePreview = (metadata) => {
    const chatData = parseMetadata(metadata);
    return chatData.type === 'revoke' || Boolean(chatData.revoked?.is_revoked);
}

const getContactDisplayName = (metadata) => {
    const contacts = parseMetadata(metadata).contacts;
    if (!Array.isArray(contacts) || contacts.length === 0) {
        return 'No contacts available';
    }
    if (contacts.length === 1) {
        const contact = contacts[0];
        return contact?.name?.formatted_name || `${contact?.name?.first_name || ''} ${contact?.name?.last_name || ''}`.trim();
    } else if (contacts.length > 1) {
        const firstName = contacts[0]?.name?.first_name || contacts[0]?.name?.formatted_name || '';
        const otherContactsCount = contacts.length - 1;
        return `${firstName} and ${otherContactsCount} other contacts`;
    } else {
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

const params = ref({
    search: props.filters.search,
    labels: props.filters.labels,
    assigned_to: props.filters.assigned_to,
    status: props.status,
    awaiting_reply: props.filters.awaiting_reply,
});

const search = debounce(() => {
    isSearching.value = true;
    runSearch();
}, 1000);

const runSearch = () => {
    const url = window.location.pathname;

    // Update the filter store before navigating
    const filterStore = useChatFilterStore();
    filterStore.search = params.value.search;
    filterStore.labels = params.value.labels;
    filterStore.assigned_to = params.value.assigned_to;
    filterStore.status = params.value.status;
    filterStore.awaiting_reply = params.value.awaiting_reply;

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
    const index = tempSelectedLabels.value.indexOf(labelId);
    if (index > -1) {
        tempSelectedLabels.value.splice(index, 1);
    } else {
        tempSelectedLabels.value.push(labelId);
    }
    hasUnappliedFilters.value = true;
};

const clearLabelFilters = () => {
    tempSelectedLabels.value = [];
    hasUnappliedFilters.value = true;
};

// Team member filtering functions
const loadTeamMembers = async () => {
    if (availableTeamMembers.value.length > 0) {
        showTeamMemberFilter.value = !showTeamMemberFilter.value;
        return;
    }

    loadingTeamMembers.value = true;
    try {
        const response = await axios.get('/chats/team-members/filter-data');
        availableTeamMembers.value = response.data;
        showTeamMemberFilter.value = true;
    } catch (error) {
        console.error('Error loading team members:', error);
    } finally {
        loadingTeamMembers.value = false;
    }
};

const selectTeamMember = (userId) => {
    tempSelectedTeamMember.value = userId;
    showTeamMemberFilter.value = false;
    hasUnappliedFilters.value = true;
};

const clearTeamMemberFilter = () => {
    tempSelectedTeamMember.value = null;
    hasUnappliedFilters.value = true;
};

const selectStatus = (status) => {
    tempSelectedStatus.value = status;
    showStatusFilter.value = false;
    hasUnappliedFilters.value = true;
};

const toggleAwaitingReply = () => {
    tempAwaitingReply.value = !tempAwaitingReply.value;
    hasUnappliedFilters.value = true;
};

const getStatusLabel = (status) => {
    const statusLabels = {
        'all': 'All',
        'open': 'Open',
        'closed': 'Closed',
        'unassigned': 'Unassigned',
        'assigned_to_me': 'Assigned to Me',
        'unread': 'Unread Only'
    };
    return statusLabels[status] || status;
};

const applyFilters = () => {
    // Apply temporary filters to actual filters
    selectedLabels.value = [...tempSelectedLabels.value];
    selectedTeamMember.value = tempSelectedTeamMember.value;

    // Update params
    params.value.labels = selectedLabels.value.length > 0 ? selectedLabels.value.join(',') : null;
    params.value.assigned_to = tempSelectedTeamMember.value;
    params.value.status = tempSelectedStatus.value;
    params.value.awaiting_reply = tempAwaitingReply.value ? '1' : null;

    hasUnappliedFilters.value = false;
    runSearch();
};

const resetFilters = () => {
    tempSelectedLabels.value = [];
    tempSelectedTeamMember.value = null;
    tempSelectedStatus.value = 'all';
    tempAwaitingReply.value = false;

    selectedLabels.value = [];
    selectedTeamMember.value = null;

    params.value.labels = null;
    params.value.assigned_to = null;
    params.value.status = 'all';
    params.value.awaiting_reply = null;

    hasUnappliedFilters.value = false;
    runSearch();
};

// Mark all as read function
const isMarkingAllRead = ref(false);

const markAllAsRead = async () => {
    if (isMarkingAllRead.value) return;

    if (!confirm(t('Are you sure you want to mark all messages as read?'))) {
        return;
    }

    isMarkingAllRead.value = true;
    try {
        const response = await axios.post('/chats/mark-all-read');
        if (response.data.success) {
            // Refresh the page to show updated counts
            runSearch();
        }
    } catch (error) {
        console.error('Error marking all as read:', error);
        alert('Failed to mark all messages as read');
    } finally {
        isMarkingAllRead.value = false;
    }
};

// Initialize selected labels from URL params
onMounted(() => {
    if (props.filters.labels) {
        selectedLabels.value = props.filters.labels.split(',').map(id => parseInt(id));
        tempSelectedLabels.value = [...selectedLabels.value];
    }

    if (props.filters.assigned_to) {
        selectedTeamMember.value = parseInt(props.filters.assigned_to);
        tempSelectedTeamMember.value = selectedTeamMember.value;
    }

    tempSelectedStatus.value = props.status || 'all';

    // Initialize the filter store with current values
    const filterStore = useChatFilterStore();
    filterStore.search = params.value.search;
    filterStore.labels = params.value.labels;
    filterStore.assigned_to = params.value.assigned_to;
    filterStore.status = params.value.status;
    filterStore.awaiting_reply = params.value.awaiting_reply;

    // Add click outside listener
    document.addEventListener('click', handleClickOutside);
});

// Handle click outside to close dropdown
const handleClickOutside = (event) => {
    const labelFilterElement = event.target.closest('.label-filter-container');
    if (!labelFilterElement && showLabelFilter.value) {
        showLabelFilter.value = false;
    }

    const teamMemberFilterElement = event.target.closest('.team-member-filter-container');
    if (!teamMemberFilterElement && showTeamMemberFilter.value) {
        showTeamMemberFilter.value = false;
    }

    const statusFilterElement = event.target.closest('.status-filter-container');
    if (!statusFilterElement && showStatusFilter.value) {
        showStatusFilter.value = false;
    }
};

// Build chat URL with current filters
const buildChatUrl = (contactUuid) => {
    const queryParams = new URLSearchParams();
    queryParams.append('page', props.rows.meta.current_page);

    if (params.value.labels) {
        queryParams.append('labels', params.value.labels);
    }

    if (params.value.search) {
        queryParams.append('search', params.value.search);
    }

    if (params.value.assigned_to) {
        queryParams.append('assigned_to', params.value.assigned_to);
    }

    if (props.status) {
        queryParams.append('status', props.status);
    }

    if (params.value.awaiting_reply) {
        queryParams.append('awaiting_reply', '1');
    }

    return `/chats/${contactUuid}?${queryParams.toString()}`;
};

const cleanupNavigationBlockers = () => {
    if (typeof window === 'undefined') {
        return;
    }

    document.querySelectorAll('[inert]').forEach((element) => {
        element.removeAttribute('inert');
    });

    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
    document.body.style.removeProperty('pointer-events');
    document.documentElement.style.removeProperty('overflow');
    document.documentElement.style.removeProperty('padding-right');
    document.documentElement.style.removeProperty('pointer-events');
};

</script>
<template>
    <div class="flex h-full min-h-0 flex-col">
        <div class="shrink-0 px-4 py-4 border-b">
        <div class="flex items-center justify-between space-x-1 text-xl">
            <div class="flex items-center space-x-3">
                <h2>{{ $t('Chats') }}</h2>
                <!-- <span class="text-slate-500">{{ rowCount }}</span> -->
            </div>
            <SendTemplateToPhone :templates="props.templates" :contactFields="props.contactFields" @sent="handleQuickSendSent" />
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
        <div class="mt-3 space-y-3">
            <!-- Filter Buttons Row -->
            <div class="flex items-start space-x-2 flex-wrap gap-2 relative">
                <div class="flex flex-col gap-2">
                    <!-- Team Member Filter Button -->
                    <div class="relative team-member-filter-container">
                        <button @click="loadTeamMembers" :disabled="loadingTeamMembers"
                            :class="tempSelectedTeamMember ? 'border border-blue-500 text-blue-600 hover:bg-blue-50' : 'bg-gray-100 hover:bg-gray-200'"
                            class="flex items-center space-x-1 px-3 py-1 rounded-md text-sm transition-colors">
                            <svg v-if="loadingTeamMembers" class="animate-spin w-4 h-4"
                                xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4">
                                </circle>
                                <path class="opacity-75" fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                </path>
                            </svg>
                            <svg v-else xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                fill="white" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round">
                                <path fill="white" stroke="currentColor"
                                    d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4s-4 1.79-4 4s1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                            </svg>
                            <span>{{tempSelectedTeamMember ? availableTeamMembers.find(m => m.user_id ===
                                tempSelectedTeamMember)?.first_name || $t('Team Member') : $t('Team Member')}}</span>
                        </button>

                        <!-- Team Member Filter Dropdown -->
                        <div v-if="showTeamMemberFilter && availableTeamMembers.length > 0"
                            class="absolute top-full ltr:left-0 rtl:right-0 rtl:left-auto mt-1 w-64 bg-white border border-gray-200 rounded-md shadow-lg z-20 max-h-60 overflow-y-auto">
                            <div class="p-2">
                                <div class="text-sm font-medium text-gray-700 mb-2">{{ $t('Select Team Member') }}</div>
                                <div class="space-y-1">
                                    <div v-for="member in availableTeamMembers" :key="member.user_id"
                                        @click="selectTeamMember(member.user_id)"
                                        :class="tempSelectedTeamMember === member.user_id ? 'bg-blue-50 border-blue-300' : 'hover:bg-gray-50'"
                                        class="flex items-center space-x-2 p-2 rounded cursor-pointer border border-transparent">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                            viewBox="0 0 24 24">
                                            <path fill="currentColor"
                                                d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4s-4 1.79-4 4s1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                                        </svg>
                                        <div class="flex-1">
                                            <div class="text-sm font-medium">{{ member.first_name }}</div>
                                            <div class="text-xs text-gray-500 capitalize">{{ member.role }}</div>
                                        </div>
                                        <svg v-if="tempSelectedTeamMember === member.user_id"
                                            xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                            viewBox="0 0 24 24">
                                            <path fill="currentColor"
                                                d="M9 16.17L4.83 12l-1.42 1.41L9 19L21 7l-1.41-1.41L9 16.17z" />
                                        </svg>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Status Dropdown -->
                    <div class="relative status-filter-container">
                        <button @click="showStatusFilter = !showStatusFilter"
                            :class="tempSelectedStatus !== 'all' ? 'border border-purple-500 text-purple-600 hover:bg-purple-50' : 'bg-gray-100 hover:bg-gray-200'"
                            class="flex items-center space-x-1 px-3 py-1 rounded-md text-sm transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                                <path fill="currentColor"
                                    d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10s10-4.5 10-10S17.5 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8s8 3.59 8 8s-3.59 8-8 8z" />
                            </svg>
                            <span>{{ $t(getStatusLabel(tempSelectedStatus)) }}</span>
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                                <path fill="currentColor"
                                    d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6l-6-6l1.41-1.41z" />
                            </svg>
                        </button>

                        <!-- Status Filter Dropdown -->
                        <div v-if="showStatusFilter"
                            class="absolute top-full ltr:left-0 rtl:right-0 rtl:left-auto mt-1 w-48 bg-white border border-gray-200 rounded-md shadow-lg z-20">
                            <div class="p-1">
                                <div v-if="teamRole === 'agent'" @click="selectStatus('assigned_to_me')"
                                    :class="tempSelectedStatus === 'assigned_to_me' ? 'bg-purple-50 text-purple-700' : 'hover:bg-gray-50'"
                                    class="px-3 py-2 text-sm cursor-pointer rounded">
                                    {{ $t('Assigned to Me') }}
                                </div>
                                <div @click="selectStatus('unassigned')"
                                    :class="tempSelectedStatus === 'unassigned' ? 'bg-purple-50 text-purple-700' : 'hover:bg-gray-50'"
                                    class="px-3 py-2 text-sm cursor-pointer rounded">
                                    {{ $t('Unassigned') }}
                                </div>
                                <div @click="selectStatus('open')"
                                    :class="tempSelectedStatus === 'open' ? 'bg-purple-50 text-purple-700' : 'hover:bg-gray-50'"
                                    class="px-3 py-2 text-sm cursor-pointer rounded">
                                    {{ $t('Open') }}
                                </div>
                                <div @click="selectStatus('closed')"
                                    :class="tempSelectedStatus === 'closed' ? 'bg-purple-50 text-purple-700' : 'hover:bg-gray-50'"
                                    class="px-3 py-2 text-sm cursor-pointer rounded">
                                    {{ $t('Closed') }}
                                </div>
                                <div @click="selectStatus('all')"
                                    :class="tempSelectedStatus === 'all' ? 'bg-purple-50 text-purple-700' : 'hover:bg-gray-50'"
                                    class="px-3 py-2 text-sm cursor-pointer rounded">
                                    {{ $t('All') }}
                                </div>
                                <div @click="selectStatus('unread')"
                                    :class="tempSelectedStatus === 'unread' ? 'bg-purple-50 text-purple-700' : 'hover:bg-gray-50'"
                                    class="px-3 py-2 text-sm cursor-pointer rounded">
                                    {{ $t('Unread Only') }}
                                </div>
                                <div @click="toggleAwaitingReply"
                                    :class="tempAwaitingReply ? 'bg-purple-50 text-purple-700' : 'hover:bg-gray-50'"
                                    class="px-3 py-2 text-sm cursor-pointer rounded flex items-center justify-between">
                                    <span>{{ $t('Awaiting Reply') }}</span>
                                    <svg v-if="tempAwaitingReply" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                                        <path fill="currentColor" d="M9 16.17L4.83 12l-1.42 1.41L9 19L21 7l-1.41-1.41L9 16.17z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Labels Filter Button -->
                <div class="relative label-filter-container">
                    <button @click="loadLabels" :disabled="loadingLabels"
                        :class="tempSelectedLabels.length > 0 ? 'border border-green-500 text-green-600 hover:bg-green-50' : 'bg-gray-100 hover:bg-gray-200'"
                        class="flex items-center space-x-1 px-3 py-1 rounded-md text-sm transition-colors">
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
                        <span>{{ $t('Labels') }}</span>
                        <span v-if="tempSelectedLabels.length > 0"
                            class="bg-white text-green-600 text-xs rounded-full w-5 h-5 flex items-center justify-center font-bold">
                            {{ tempSelectedLabels.length }}
                        </span>
                    </button>

                    <!-- Label Filter Dropdown -->
                    <div v-if="showLabelFilter && availableLabels.length > 0"
                        class="absolute top-full ltr:left-0 rtl:right-0 rtl:left-auto mt-1 w-64 bg-white border border-gray-200 rounded-md shadow-lg z-20 max-h-60 overflow-y-auto">
                        <div class="p-2">
                            <div class="text-sm font-medium text-gray-700 mb-2">{{ $t('Select Labels') }}</div>
                            <div class="space-y-2">
                                <div v-for="label in availableLabels" :key="label.id"
                                    class="flex items-center space-x-2 hover:bg-gray-50 p-1 rounded">
                                    <input type="checkbox" :id="`filter-label-${label.id}`"
                                        :checked="tempSelectedLabels.includes(label.id)" @change="toggleLabel(label.id)"
                                        class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 focus:ring-2">
                                    <label :for="`filter-label-${label.id}`"
                                        class="flex items-center space-x-2 cursor-pointer flex-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                            viewBox="0 0 24 24">
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

            </div>

            <!-- Action Buttons -->
            <div v-if="hasUnappliedFilters || tempSelectedLabels.length > 0 || tempSelectedTeamMember || tempSelectedStatus !== 'all' || tempAwaitingReply"
                class="flex items-center gap-2">
                <button @click="applyFilters" :disabled="!hasUnappliedFilters"
                    :class="hasUnappliedFilters ? 'border border-blue-500 text-blue-600 hover:bg-blue-50' : 'border border-gray-300 text-gray-400 cursor-not-allowed'"
                    class="flex items-center gap-2 px-4 py-2 rounded-md text-sm font-medium transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                        <path fill="currentColor" d="M9 16.17L4.83 12l-1.42 1.41L9 19L21 7l-1.41-1.41L9 16.17z" />
                    </svg>
                    <span>{{ $t('Apply Filters') }}</span>
                </button>

                <button @click="resetFilters"
                    v-if="tempSelectedLabels.length > 0 || tempSelectedTeamMember || tempSelectedStatus !== 'all' || tempAwaitingReply"
                    class="flex items-center gap-2 px-4 py-2 border border-red-500 text-red-600 hover:bg-red-50 rounded-md text-sm font-medium transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                        <path fill="currentColor"
                            d="M19 6.41L17.59 5L12 10.59L6.41 5L5 6.41L10.59 12L5 17.59L6.41 19L12 13.41L17.59 19L19 17.59L13.41 12L19 6.41z" />
                    </svg>
                    <span>{{ $t('Reset') }}</span>
                </button>
            </div>
        </div>

        <div class="grid grid-cols-2 mt-4 items-center w-full">
            <div class="flex items-center flex-nowrap gap-2">
                <span class="text-sm text-gray-600 whitespace-nowrap">{{ $t('Total Chats') }}: <span
                        class="font-bold text-slate-900">{{ rowCount }}</span></span>
                <button @click="markAllAsRead" :disabled="isMarkingAllRead"
                    class="flex items-center text-sm text-slate-600 hover:text-slate-900 disabled:opacity-50 disabled:cursor-not-allowed"
                    :title="$t('Mark all as read')" aria-label="Mark all as read">
                    <svg v-if="!isMarkingAllRead" xmlns="http://www.w3.org/2000/svg" width="32" height="32"
                        viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="9" fill="none" stroke="#ffffff" stroke-width="2" />
                        <path fill="none" stroke="#16a34a" stroke-width="2.2" stroke-linecap="round"
                            stroke-linejoin="round" d="M7 12.5l2.2 2.2l4.8-4.8" />
                        <path fill="none" stroke="#16a34a" stroke-width="2.2" stroke-linecap="round"
                            stroke-linejoin="round" d="M11.5 12.5l2.2 2.2l3.3-3.3" />
                    </svg>
                    <svg v-else class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="32" height="32"
                        viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none"
                            opacity="0.25" />
                        <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" fill="none" />
                    </svg>
                    <!-- <span>{{ $t('Mark all read') }}</span> -->
                </button>
            </div>
            <div class="flex ltr:ml-auto rtl:mr-auto gap-1">
                <SortDirectionToggle :direction="props.chatSortDirection" :url="'/chats/update-sort-direction'" />
            </div>
        </div>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto" ref="scrollContainer">
        <Link :href="buildChatUrl(contact.uuid)" preserve-scroll class="block border-b group-hover:pr-0" :class="{
            'bg-green-50': contact.unread_messages > 0 && selectedContactUuid !== contact.uuid,
            'bg-green-100': selectedContactUuid === contact.uuid
        }" v-for="(contact, index) in rows.data" :key="index" @click="cleanupNavigationBlockers">
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
                        <div v-if="isDeletedMessagePreview(contact?.last_chat?.metadata)"
                            class="text-slate-500 text-xs truncate self-end italic">
                            {{ $t('Deleted message') }}
                        </div>
                        <template v-else>
                        <div v-if="contentType(contact?.last_chat?.metadata) === 'text'"
                            class="text-slate-500 text-xs truncate self-end"> {{
                                content(contact?.last_chat?.metadata).text?.body }}</div>
                        <div v-if="contentType(contact?.last_chat?.metadata) === 'button'"
                            class="text-slate-500 text-xs truncate self-end"> {{
                                content(contact?.last_chat?.metadata).button?.text }}</div>
                        <div v-if="contentType(contact?.last_chat?.metadata) === 'interactive'"
                            class="text-slate-500 text-xs truncate self-end"> {{
                                content(contact?.last_chat?.metadata).interactive?.button_reply?.title ||
                                content(contact?.last_chat?.metadata).interactive?.list_reply?.title ||
                                content(contact?.last_chat?.metadata).interactive?.body?.text ||
                                content(contact?.last_chat?.metadata).interactive?.action?.button }}</div>
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
                                <span class="ml-2">{{ documentExtension(contact?.last_chat) }} {{ $t('File')
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
                        <div v-if="contentType(contact?.last_chat?.metadata) === 'reaction'"
                            class="text-slate-500 text-xs truncate self-end">
                            <div class="flex items-center">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                                    <path fill="currentColor"
                                        d="M12 2C6.47 2 2 6.47 2 12s4.47 10 10 10s10-4.47 10-10S17.53 2 12 2Zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8s8 3.59 8 8s-3.59 8-8 8Zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8S14 8.67 14 9.5s.67 1.5 1.5 1.5Zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8S7 8.67 7 9.5s.67 1.5 1.5 1.5Zm3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5Z" />
                                </svg>
                                <span class="ml-2">{{ content(contact?.last_chat?.metadata).reaction?.emoji }}</span>
                            </div>
                        </div>
                        <div v-if="contentType(contact?.last_chat?.metadata) === 'carousel'"
                            class="text-slate-500 text-xs truncate self-end">
                            Carousel message
                        </div>
                        </template>

                        <span v-if="contact.unread_messages > 0"
                            class="bg-green-600 text-white rounded-md py-[1px] px-[8px] min-w-10 text-[10px] flex items-center justify-center">{{
                                contact.unread_messages }}</span>
                    </div>
                </div>
            </div>
        </Link>
        </div>
        <div class="shrink-0 px-4 pb-4">
            <Pagination class="mt-3" :pagination="rows.meta" :compact="true" />
        </div>
    </div>
</template>
