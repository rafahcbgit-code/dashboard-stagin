<script setup>
import { ref, watch, watchEffect, computed, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import { router, useForm, usePage } from '@inertiajs/vue3'
import AlertModal from '@/Components/AlertModal.vue';
import FormSelectCombo from '@/Components/FormSelectCombo.vue';
import Modal from '@/Components/Modal.vue';
import ChatNotesModal from '@/Components/ChatComponents/ChatNotesModal.vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue3-toastify';

const { t } = useI18n();
const props = defineProps(['contact', 'displayContactInfo', 'ticketingIsEnabled', 'ticket', 'addon', 'teamRole', 'labelColors', 'currentPage']);

const accountUser = computed(() => usePage().props.auth.user);
const showAlert = ref(false);
const displayContact = ref(props.displayContactInfo);
const ticketState = ref(null);
const isNotesModalOpen = ref(false);
const isOpenLabelsModal = ref(false);
const availableLabels = ref([]);
const selectedLabels = ref([]);
const loadingLabels = ref(false);
const isSavingLabels = ref(false);
const isMarkingLatestUnread = ref(false);
const ticketUserOption = (ticket) => ({
    label: ticket?.user ? ticket.user.full_name : t('Unassigned'),
    value: ticket?.user ? ticket.user.id : 0,
});
const user = ref(ticketUserOption(props.ticket));

watchEffect(() => {
    displayContact.value = props.displayContactInfo;
    form4.bot_enable = props.contact?.bot_enable;
});

watch(
    () => props.ticket,
    (ticket) => {
        ticketState.value = ticket?.status;
        user.value = ticketUserOption(ticket);
    },
    { immediate: true, deep: true }
);

const emit = defineEmits(['toggleView', 'deleteThread', 'closeThread', 'labelsUpdated', 'noteCreated', 'messageMarkedUnread', 'search']);

const closeThread = () => {
    emit('closeThread', true);
}

const toggleView = () => {
    displayContact.value = !displayContact.value;
    emit('toggleView', displayContact.value);
}

const deleteThread = () => {
    router.visit('/chats/' + props.contact.uuid, {
        method: 'delete',
        onFinish: () => {
            showAlert.value = false
        }
    });
}

const markLatestInboundAsUnread = async () => {
    if (isMarkingLatestUnread.value || !props.contact?.uuid) {
        return;
    }

    isMarkingLatestUnread.value = true;
    closeDropdown();

    try {
        const response = await axios.post(`/chats/${props.contact.uuid}/mark-latest-unread`);
        const updatedCount = Number(response?.data?.updated_count || 0);

        emit('messageMarkedUnread', {
            contactUuid: props.contact.uuid,
            unreadCount: Number(response?.data?.unread_count || 0),
            updated: updatedCount > 0,
        });

        toast(response?.data?.message || t('Chat marked as unread'), {
            autoClose: 3000,
        });
    } catch (error) {
        toast(error?.response?.data?.message || t('Failed to mark latest message as unread'), {
            type: 'error',
            autoClose: 3000,
        });
    } finally {
        isMarkingLatestUnread.value = false;
    }
}

const form = useForm({
    'status': ticketState
});

const form3 = useForm({
    'ai_assistant': props.contact?.ai_assistance_enabled,
});

const form4 = useForm({
    'bot_enable': props.contact?.bot_enable,
});

const labelsForm = useForm({
    'labels': []
});

const changeTicketStatus = (value) => {
    router.put('/tickets/' + props.contact.uuid + '/update', {
        'status': value
    }, {})
}

const changeTicketAgent = (selectedUser = user.value) => {
    const selectedUserId = selectedUser?.value;

    console.info('[TicketAssignment] selection changed', {
        contactUuid: props.contact?.uuid,
        currentTicketId: props.ticket?.id,
        currentAssignedTo: props.ticket?.assigned_to,
        selectedUser,
        selectedUserId,
    });

    if (!selectedUserId) {
        console.warn('[TicketAssignment] assignment skipped because selected user is empty', {
            contactUuid: props.contact?.uuid,
            selectedUser,
        });
        return;
    }

    router.put('/tickets/' + props.contact.uuid + '/assign', {
        'id': selectedUserId
    }, {
        preserveScroll: true,
        onSuccess: () => {
            console.info('[TicketAssignment] assignment request completed', {
                contactUuid: props.contact?.uuid,
                selectedUserId,
            });
        },
        onError: () => {
            console.error('[TicketAssignment] assignment request failed', {
                contactUuid: props.contact?.uuid,
                selectedUserId,
                ticket: props.ticket,
            });
            user.value = ticketUserOption(props.ticket);
        },
    })
}

function loadUsers(query, setOptions) {
    fetch("/team?search=" + query, {
        headers: {
            'Accept': 'application/json'
        }
    })
        .then(response => response.json())
        .then(result => {
            setOptions(result.rows);
        })
        .catch(error => {
            console.error("Error fetching agents:", error);
        });
}

const submitForm3 = () => {
    form3.ai_assistant = !form3.ai_assistant;

    form3.post('/automation/contact/' + props.contact.uuid, {
        preserveState: true,
    });
}

const openLabelsModal = async () => {
    loadingLabels.value = true;
    isOpenLabelsModal.value = true;

    try {
        // Fetch available labels and contact's current labels
        const response = await axios.get(`/contacts/${props.contact.uuid}/labels`);
        availableLabels.value = response.data.availableLabels;
        selectedLabels.value = response.data.contactLabels.map(label => label.id);
        labelsForm.labels = [...selectedLabels.value];
    } catch (error) {
        console.error('Error loading labels:', error);
    } finally {
        loadingLabels.value = false;
    }
}

const toggleLabel = (labelId) => {
    const index = labelsForm.labels.indexOf(labelId);
    if (index > -1) {
        labelsForm.labels.splice(index, 1);
    } else {
        labelsForm.labels.push(labelId);
    }
}

const selectedLabelsList = computed(() => {
    return availableLabels.value.filter(label => labelsForm.labels.includes(label.id));
});

const unselectedLabelsList = computed(() => {
    return availableLabels.value.filter(label => !labelsForm.labels.includes(label.id));
});

const saveLabels = async () => {
    if (isSavingLabels.value) {
        return;
    }

    isSavingLabels.value = true;
    if (typeof labelsForm.clearErrors === 'function') {
        labelsForm.clearErrors();
    }

    try {
        const response = await axios.put(`/contacts/${props.contact.uuid}/labels`, {
            labels: labelsForm.labels,
        });

        const updatedLabels = Array.isArray(response?.data?.labels)
            ? response.data.labels
            : availableLabels.value.filter(label => labelsForm.labels.includes(label.id));

        emit('labelsUpdated', updatedLabels);
        isOpenLabelsModal.value = false;
    } catch (error) {
        console.error('Error saving labels:', error);
    } finally {
        isSavingLabels.value = false;
    }
}

const cleanupPageInteractivity = () => {
    if (typeof window === 'undefined') {
        return;
    }

    const cleanup = () => {
        document.querySelectorAll('[inert]').forEach((element) => {
            element.removeAttribute('inert');
        });

        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('pointer-events');
        document.documentElement.style.removeProperty('overflow');
        document.documentElement.style.removeProperty('padding-right');
        document.documentElement.style.removeProperty('pointer-events');

        if (document.activeElement instanceof HTMLElement) {
            document.activeElement.blur();
        }
    };

    cleanup();
    window.requestAnimationFrame(cleanup);
    window.setTimeout(cleanup, 50);
    window.setTimeout(cleanup, 200);
};

const openNotesModal = () => {
    cleanupPageInteractivity();
    isNotesModalOpen.value = true;
    closeDropdown();
};

const closeNotesModal = () => {
    isNotesModalOpen.value = false;
    cleanupPageInteractivity();
};

const handleNotesChanged = () => {
    cleanupPageInteractivity();
}

const handleNoteCreated = (timelineItem) => {
    emit('noteCreated', timelineItem);
    cleanupPageInteractivity();
}

const showDropdown = ref(false);

const toggleDropdown = () => {
    showDropdown.value = !showDropdown.value;
}

const closeDropdown = () => {
    showDropdown.value = false;
}

const handleClickOutside = (event) => {
    const dropdown = document.querySelector('.dropdown-container');
    if (dropdown && !dropdown.contains(event.target)) {
        closeDropdown();
    }
}

const toggleBot = () => {
    form4.bot_enable = !form4.bot_enable;

    form4.put('/contacts/' + props.contact.uuid + '/bot-toggle', {
        preserveState: true,
        onSuccess: () => {
            // Update local contact data
            props.contact.bot_enable = form4.bot_enable;
        },
        onError: (errors) => {
            // Revert on error
            form4.bot_enable = !form4.bot_enable;
            console.error('Error toggling bot:', errors);
        }
    });
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>
<template>
    <div class="min-h-[76px] bg-white border-b border-slate-100 flex items-center justify-between px-4 md:px-5 header-container">
        <div class="flex items-center gap-x-4 cursor-pointer w-3/4">
            <button type="button" @click="closeThread" class="sm:block md:hidden">
                <svg xmlns="http://www.w3.org/2000/svg" width="2em" height="2em" viewBox="0 0 24 24">
                    <path fill="currentColor"
                        d="M19 11H7.14l3.63-4.36a1 1 0 1 0-1.54-1.28l-5 6a1.19 1.19 0 0 0-.09.15c0 .05 0 .08-.07.13A1 1 0 0 0 4 12a1 1 0 0 0 .07.36c0 .05 0 .08.07.13a1.19 1.19 0 0 0 .09.15l5 6A1 1 0 0 0 10 19a1 1 0 0 0 .64-.23a1 1 0 0 0 .13-1.41L7.14 13H19a1 1 0 0 0 0-2" />
                </svg>
            </button>
            <div @click="toggleView">
                <img v-if="contact.avatar" class="rounded-2xl w-11 h-11 object-cover ring-2 ring-emerald-50" :src="contact.avatar">
                <div v-else class="rounded-full w-10 h-10 flex items-center justify-center bg-emerald-100 text-emerald-700 font-semibold">{{
                    contact.full_name.substring(0, 1) }}</div>
            </div>
            <div class="flex items-center w-full gap-x-8">
                <div>
                    <div class="flex items-center" @click="toggleView">
                        <span dir="auto" class="whitespace-nowrap font-semibold text-slate-900">{{ contact.full_name }}</span>
                    </div>
                    <div class="w-full flex items-center text-xs">
                        <span dir="ltr" class="whitespace-nowrap tabular-nums text-slate-500" @click="toggleView">{{ contact.formatted_phone_number }}</span>
                    </div>
                </div>
                <FormSelectCombo v-if="ticketingIsEnabled" v-model="user"
                    :name="''" :loadOptions="loadUsers" :class="'col-span-1 md:block hidden'"
                    :placeholder="t('Select Agent')" @update:modelValue="changeTicketAgent" />
            </div>

        </div>
        <div class="relative">
            <div class="flex items-center gap-4">
                <button v-if="!displayContact" type="button" @click="emit('search')"
                    class="rounded-full p-2 text-slate-700 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                    :aria-label="$t('Search this conversation')" :title="$t('Search this conversation')">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                </button>

                <!-- Label Color Icons -->
                <div class="flex items-center gap-1 ms-2 shrink min-w-0">
                    <svg v-for="(color, index) in props.labelColors" :key="index" xmlns="http://www.w3.org/2000/svg"
                        class="w-6 h-6 shrink" viewBox="0 0 24 24">
                        <path :fill="color" :stroke="color" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2" d="M20.59 13.41 12 22 4 14V4h10l8.59 8.59zM7 7h.01" />
                    </svg>
                </div>

                <button v-if="ticketState === 'open' && ticketingIsEnabled" @click="changeTicketStatus('closed')"
                    class="text-sm md:inline-flex hidden justify-center rounded-md border border-transparent bg-red-800 px-4 py-1 text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 whitespace-nowrap">{{
                        $t('Mark as closed') }}</button>
                <button v-if="ticketState === 'closed' && ticketingIsEnabled" @click="changeTicketStatus('open')"
                    class="text-sm md:inline-flex hidden justify-center rounded-md border border-transparent bg-primary px-4 py-1 text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 whitespace-nowrap">{{
                        $t('Mark as open') }}</button>

                <div v-if="!displayContact" class="dropdown-container relative">
                    <!-- Custom Dropdown -->
                    <div class="relative">
                        <button type="button" @click="toggleDropdown"
                            class="inline-flex w-full justify-center items-center rounded-md text-sm font-medium text-black hover:bg-opacity-30 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-opacity-75">
                            <span class="bg-slate-200 p-1 rounded-full cursor-pointer flex items-center justify-center">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                                    <path fill="none" stroke="currentColor" stroke-linecap="round"
                                        stroke-linejoin="round" stroke-width="1.5"
                                        d="M12 6.75a.75.75 0 1 1 0-1.5a.75.75 0 0 1 0 1.5Zm0 6a.75.75 0 1 1 0-1.5a.75.75 0 0 1 0 1.5Zm0 6a.75.75 0 1 1 0-1.5a.75.75 0 0 1 0 1.5Z" />
                                </svg>
                            </span>
                        </button>

                        <!-- Dropdown Menu -->
                        <div v-if="showDropdown" :class="[
                            'absolute mt-2 w-56 bg-white border border-gray-200 rounded-md shadow-lg z-[99999]',
                            $i18n.locale === 'ar' ? 'left-0 origin-top-left' : 'right-0 origin-top-right'
                        ]">
                            <div class="py-1" role="menu">
                                <button @click="openNotesModal"
                                    class="group flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900"
                                    role="menuitem">
                                    {{ $t('View notes') }}
                                </button>

                                <button @click="openLabelsModal(); closeDropdown()"
                                    class="group flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900"
                                    role="menuitem">
                                    {{ $t('Manage labels') }}
                                </button>

                                <button @click="markLatestInboundAsUnread"
                                    :disabled="isMarkingLatestUnread"
                                    class="group flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900 disabled:opacity-50 disabled:cursor-not-allowed"
                                    role="menuitem">
                                    {{ $t('Mark as unread') }}
                                </button>

                                <button v-if="ticketState === 'open' && ticketingIsEnabled"
                                    @click="changeTicketStatus('closed'); closeDropdown()"
                                    class="group flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900"
                                    role="menuitem">
                                    {{ $t('Mark as closed') }}
                                </button>

                                <button v-if="ticketState === 'closed' && ticketingIsEnabled"
                                    @click="changeTicketStatus('open'); closeDropdown()"
                                    class="group flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900"
                                    role="menuitem">
                                    {{ $t('Mark as open') }}
                                </button>

                                <button @click="toggleBot(); closeDropdown()"
                                    class="group flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900"
                                    role="menuitem">
                                    {{ form4.bot_enable ? $t('Disable Chatbot') : $t('Enable Chatbot') }}

                                </button>

                                <button v-if="teamRole !== 'agent'" @click="showAlert = true; closeDropdown()"
                                    class="group flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900"
                                    role="menuitem">
                                    {{ $t('Clear chat') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else @click="toggleView" class="bg-slate-200 p-2 rounded-lg text-sm cursor-pointer">
                    <span>{{ $t('Back') }}</span>
                </div>
            </div>
        </div>
    </div>
    <div v-if="addon == 1" class="flex flex-col gap-2 bg-white border-b border-1 py-2 px-4 sm:flex-row sm:items-center sm:justify-between md:px-4">
        <div class="text-sm py-1 px-3 rounded-md flex items-center gap-x-2 w-[fit-content]"
            :class="form3.ai_assistant ? 'bg-green-50' : 'bg-red-50'">
            <span :class="form3.ai_assistant ? 'text-green-500' : 'text-red-500'">
                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24">
                    <path fill="currentColor"
                        d="M12 22q-2.075 0-3.9-.788t-3.175-2.137T2.788 15.9T2 12t.788-3.9t2.137-3.175T8.1 2.788T12 2t3.9.788t3.175 2.137T21.213 8.1T22 12t-.788 3.9t-2.137 3.175t-3.175 2.138T12 22" />
                </svg>
            </span>
            <span>{{ $t('AI Assistant') }}</span>
        </div>
        <div class="flex items-center gap-x-2">
            <span class="text-[13px]">{{ $t('Enable/disable AI assistant') }}</span>
            <button type="button" role="switch" :aria-checked="Boolean(form3.ai_assistant)" :aria-label="$t('Enable/disable AI assistant')" :disabled="form3.processing"
                class="relative inline-flex h-6 w-12 shrink-0 items-center rounded-full p-1 transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-wait disabled:opacity-60"
                :class="form3.ai_assistant ? 'bg-primary' : 'bg-gray-300'" @click="submitForm3()">
                <span class="size-4 rounded-full bg-white shadow-md transition-transform duration-300 ease-in-out"
                    :class="form3.ai_assistant ? 'ltr:translate-x-6 rtl:-translate-x-6' : ''"></span>
            </button>
        </div>
    </div>

    <!-- Bot Status Bar -->
    <div class="flex flex-col gap-2 bg-white border-b border-1 py-2 px-4 sm:flex-row sm:items-center sm:justify-between md:px-4">
        <div class="text-sm py-1 px-3 rounded-md flex items-center gap-x-2 w-[fit-content]"
            :class="form4.bot_enable ? 'bg-green-50' : 'bg-amber-50'">
            <span :class="form4.bot_enable ? 'text-green-500' : 'text-amber-500'">
                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24">
                    <path fill="currentColor"
                        d="M12 22q-2.075 0-3.9-.788t-3.175-2.137T2.788 15.9T2 12t.788-3.9t2.137-3.175T8.1 2.788T12 2t3.9.788t3.175 2.137T21.213 8.1T22 12t-.788 3.9t-2.137 3.175t-3.175 2.138T12 22" />
                </svg>
            </span>
            <span>{{ form4.bot_enable ? $t('Bot Active') : $t('Bot Paused') }}</span>
        </div>
        <div class="flex min-w-0 items-center justify-between gap-x-3 sm:justify-start">
            <span class="text-[13px]">{{ $t('Enable/disable chatbot') }}</span>
            <button type="button" role="switch" :aria-checked="Boolean(form4.bot_enable)" :aria-label="$t('Enable/disable chatbot')" :disabled="form4.processing"
                class="relative inline-flex h-6 w-12 shrink-0 items-center rounded-full p-1 transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-wait disabled:opacity-60"
                :class="form4.bot_enable ? 'bg-primary' : 'bg-gray-300'" @click="toggleBot()">
                <span class="size-4 rounded-full bg-white shadow-md transition-transform duration-300 ease-in-out"
                    :class="form4.bot_enable ? 'ltr:translate-x-6 rtl:-translate-x-6' : ''"></span>
            </button>
        </div>
    </div>

    <ChatNotesModal
        v-if="contact && isNotesModalOpen"
        :contact="contact"
        :isOpen="true"
        @close="closeNotesModal"
        @notesChanged="handleNotesChanged"
        @noteCreated="handleNoteCreated"
    />

    <!-- Manage Labels Modal -->
    <Modal :label="$t('Manage labels')" :isOpen="isOpenLabelsModal" @close="isOpenLabelsModal = false">
        <div class="mt-5">
            <div v-if="loadingLabels" class="flex justify-center py-4">
                <svg class="animate-spin h-8 w-8 text-gray-600" xmlns="http://www.w3.org/2000/svg" fill="none"
                    viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                    </path>
                </svg>
            </div>
            <div v-else>
                <div v-if="availableLabels.length === 0" class="text-center text-gray-500 py-4">
                    {{ $t('No labels available. Create labels in Settings first.') }}
                </div>
                <div v-else class="max-h-96 overflow-y-auto">
                    <!-- Selected Labels Section -->
                    <div v-if="selectedLabelsList.length > 0" class="mb-6">
                        <h4 class="text-sm font-medium text-gray-900 mb-3 flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                class="me-2">
                                <path fill="currentColor"
                                    d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10s10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5l1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                            </svg>
                            {{ $t('Selected Labels') }} ({{ selectedLabelsList.length }})
                        </h4>
                        <div class="space-y-2">
                            <div v-for="label in selectedLabelsList" :key="`selected-${label.id}`"
                                class="flex items-center gap-3 p-2 bg-green-50 border border-green-200 rounded-md">
                                <input type="checkbox" :id="`selected-label-${label.id}`" :checked="true"
                                    @change="toggleLabel(label.id)"
                                    class="w-4 h-4 text-green-600 bg-gray-100 border-gray-300 rounded focus:ring-green-500 focus:ring-2" />
                                <label :for="`selected-label-${label.id}`"
                                    class="flex items-center gap-2 cursor-pointer flex-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                                        <path fill="none" :stroke="label.color" stroke-linecap="round"
                                            stroke-linejoin="round" stroke-width="2"
                                            d="M20.59 13.41 12 22 4 14V4h10l8.59 8.59zM7 7h.01" />
                                    </svg>
                                    <span class="font-medium">{{ label.title }}</span>
                                </label>
                                <button @click="toggleLabel(label.id)"
                                    class="text-red-500 hover:text-red-700 p-1 rounded-full hover:bg-red-100"
                                    :title="$t('Remove label')">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                                        <path fill="currentColor"
                                            d="M19 6.41L17.59 5L12 10.59L6.41 5L5 6.41L10.59 12L5 17.59L6.41 19L12 13.41L17.59 19L19 17.59L13.41 12L19 6.41z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Available Labels Section -->
                    <div v-if="unselectedLabelsList.length > 0">
                        <h4 class="text-sm font-medium text-gray-900 mb-3 flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                class="me-2">
                                <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                    stroke-width="2" d="M20.59 13.41 12 22 4 14V4h10l8.59 8.59zM7 7h.01" />
                            </svg>
                            {{ $t('Available Labels') }} ({{ unselectedLabelsList.length }})
                        </h4>
                        <div class="space-y-2">
                            <div v-for="label in unselectedLabelsList" :key="`unselected-${label.id}`"
                                class="flex items-center gap-3 p-2 bg-gray-50 border border-gray-200 rounded-md hover:bg-gray-100 transition-colors">
                                <input type="checkbox" :id="`unselected-label-${label.id}`" :checked="false"
                                    @change="toggleLabel(label.id)"
                                    class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 focus:ring-2" />
                                <label :for="`unselected-label-${label.id}`"
                                    class="flex items-center gap-2 cursor-pointer flex-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                                        <path fill="none" :stroke="label.color" stroke-linecap="round"
                                            stroke-linejoin="round" stroke-width="2"
                                            d="M20.59 13.41 12 22 4 14V4h10l8.59 8.59zM7 7h.01" />
                                    </svg>
                                    <span>{{ label.title }}</span>
                                </label>
                                <button @click="toggleLabel(label.id)"
                                    class="text-green-500 hover:text-green-700 p-1 rounded-full hover:bg-green-100"
                                    :title="$t('Add label')">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                                        <path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- No Labels Message -->
                    <div v-if="selectedLabelsList.length === 0 && unselectedLabelsList.length === 0"
                        class="text-center text-gray-500 py-8">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24"
                            class="mx-auto mb-3 text-gray-400">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2" d="M20.59 13.41 12 22 4 14V4h10l8.59 8.59zM7 7h.01" />
                        </svg>
                        <p>{{ $t('No labels found') }}</p>
                        <p class="text-xs mt-1">{{ $t('Create labels in Settings to get started') }}</p>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-gray-200 flex justify-between items-center">
                    <div class="text-sm text-gray-600">
                        {{ $t('Total selected') }}: {{ labelsForm.labels.length }}
                    </div>
                    <div class="flex gap-2">
                        <button type="button" @click="isOpenLabelsModal = false"
                            class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                            {{ $t('Cancel') }}
                        </button>
                        <button @click="saveLabels"
                            :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': isSavingLabels }]"
                            :disabled="isSavingLabels">
                            <svg v-if="isSavingLabels" xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                                viewBox="0 0 24 24">
                                <path fill="currentColor"
                                    d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z"
                                    opacity=".5" />
                                <path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z">
                                    <animateTransform attributeName="transform" dur="1s" from="0 12 12"
                                        repeatCount="indefinite" to="360 12 12" type="rotate" />
                                </path>
                            </svg>
                            <span v-else>{{ $t('Save Labels') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Modal>

    <AlertModal v-model="showAlert" :label="$t('Clear chat')"
        :description="$t('Are you sure you want to delete this thread? You can\'t undo this action')"
        @confirm="deleteThread" />
</template>
