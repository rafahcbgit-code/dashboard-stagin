<script setup>
import { ref, watchEffect, computed, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import { router, useForm, usePage } from '@inertiajs/vue3'
import AlertModal from '@/Components/AlertModal.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownItemGroup from '@/Components/DropdownItemGroup.vue';
import DropdownItem from '@/Components/DropdownItem.vue';
import FormSelectCombo from '@/Components/FormSelectCombo.vue';
import FormTextArea from '@/Components/FormTextArea.vue';
import FormToggleSwitch from '@/Components/FormToggleSwitch.vue';
import Modal from '@/Components/Modal.vue';
import { trans } from 'laravel-vue-i18n';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const props = defineProps(['contact', 'displayContactInfo', 'ticketingIsEnabled', 'ticket', 'addon', 'teamRole', 'labelColors', 'currentPage']);

const accountUser = computed(() => usePage().props.auth.user);
const showAlert = ref(false);
const displayContact = ref(props.displayContactInfo);
const ticketState = ref(null);
const isOpenModal = ref(false);
const isOpenLabelsModal = ref(false);
const availableLabels = ref([]);
const selectedLabels = ref([]);
const loadingLabels = ref(false);
const user = ref({
    'label': props.ticket?.user ? props.ticket?.user?.full_name : t('Unassigned'),
    'value': props.ticket?.user ? props.ticket?.user?.id : 0,
});

watchEffect(() => {
    displayContact.value = props.displayContactInfo;
    ticketState.value = props.ticket?.status;
});

const emit = defineEmits(['toggleView', 'deleteThread', 'closeThread', 'labelsUpdated']);

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

const form = useForm({
    'status': ticketState
});

const form2 = useForm({
    'notes': null,
    'contact': null
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

const changeTicketAgent = () => {
    router.put('/tickets/' + props.contact.uuid + '/assign', {
        'id': user.value.value
    }, {})
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

const submitForm = () => {
    form2.contact = props.contact.uuid;

    form2.post('/notes', {
        preserveState: false,
        onSuccess: () => {
            form2.reset();
            isOpenModal.value = false;
        }
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

const saveLabels = () => {
    labelsForm.put(`/contacts/${props.contact.uuid}/labels`, {
        onSuccess: () => {
            isOpenLabelsModal.value = false;

            emit('labelsUpdated', props.contact);;
            // Update the local contact data with selected labels
            const updatedLabels = availableLabels.value.filter(label =>
                labelsForm.labels.includes(label.id)
            );

            // Update the contact prop (this might need to be emitted to parent)
            if (props.contact.labels) {
                props.contact.labels = updatedLabels;
            }

            if (props.labelColors) {
                props.labelColors.splice(
                    0,
                    props.labelColors.length,
                    ...updatedLabels.map(l => l.color)
                );
            }
        },
        onError: (errors) => {
            console.error('Error saving labels:', errors);
        }
    });
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
    <div class="h-20 bg-white border-b border-1 flex items-center justify-between px-4 md:px-4 header-container">
        <div class="flex items-center gap-x-4 cursor-pointer w-3/4">
            <button type="button" @click="closeThread" class="sm:block md:hidden">
                <svg xmlns="http://www.w3.org/2000/svg" width="2em" height="2em" viewBox="0 0 24 24">
                    <path fill="currentColor"
                        d="M19 11H7.14l3.63-4.36a1 1 0 1 0-1.54-1.28l-5 6a1.19 1.19 0 0 0-.09.15c0 .05 0 .08-.07.13A1 1 0 0 0 4 12a1 1 0 0 0 .07.36c0 .05 0 .08.07.13a1.19 1.19 0 0 0 .09.15l5 6A1 1 0 0 0 10 19a1 1 0 0 0 .64-.23a1 1 0 0 0 .13-1.41L7.14 13H19a1 1 0 0 0 0-2" />
                </svg>
            </button>
            <div @click="toggleView">
                <img v-if="contact.avatar" class="rounded-full w-14 h-14" :src="contact.avatar">
                <div v-else class="rounded-full w-10 h-10 flex items-center justify-center bg-slate-100">{{
                    contact.full_name.substring(0, 1) }}</div>
            </div>
            <div class="flex items-center w-full gap-x-8">
                <div>
                    <div class="flex items-center" @click="toggleView">
                        <span class="whitespace-nowrap">{{ contact.full_name }}</span>
                    </div>
                    <div class="w-full flex items-center text-xs">
                        <span @click="toggleView">{{ contact.formatted_phone_number }}</span>
                    </div>
                </div>
                <FormSelectCombo v-if="ticketingIsEnabled" v-model="user"
                    :name="''" :loadOptions="loadUsers" :class="'col-span-1 md:block hidden'"
                    :placeholder="'Select Agent'" @update:modelValue="changeTicketAgent()" />
            </div>

        </div>
        <div class="relative">
            <div class="flex items-center space-x-4">

                <!-- Label Color Icons -->
                <div class="flex items-center space-x-1 ml-2 shrink min-w-0">
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
                                <button @click="isOpenModal = true; closeDropdown()"
                                    class="group flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900"
                                    role="menuitem">
                                    {{ $t('Add notes') }}
                                </button>

                                <button @click="openLabelsModal(); closeDropdown()"
                                    class="group flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900"
                                    role="menuitem">
                                    {{ $t('Manage labels') }}
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
    <div v-if="addon == 1" class="flex justify-between items-center bg-white border-b border-1 py-2 px-4 md:px-4">
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
            <div class="w-12 h-6 flex items-center bg-gray-300 rounded-full p-1 cursor-pointer"
                :class="{ 'bg-primary': form3.ai_assistant }" @click="submitForm3()">
                <div class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out"
                    :class="{ 'translate-x-6': form3.ai_assistant }"></div>
            </div>
        </div>
    </div>

    <!-- Add Note Modal -->
    <Modal :label="'Add Note'" :isOpen="isOpenModal">
        <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
            <form @submit.prevent="submitForm()" class="">
                <FormTextArea v-model="form2.notes" :error="form2.errors.note" :name="''" :class="'col-span-2'" />
                <div class="mt-4 flex">
                    <button type="button" @click.self="isOpenModal = false"
                        class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{
                            $t('Cancel') }}</button>
                    <button
                        :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': form2.processing }]"
                        :disabled="form2.processing">
                        <svg v-if="form2.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                            viewBox="0 0 24 24">
                            <path fill="currentColor"
                                d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z"
                                opacity=".5" />
                            <path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z">
                                <animateTransform attributeName="transform" dur="1s" from="0 12 12"
                                    repeatCount="indefinite" to="360 12 12" type="rotate" />
                            </path>
                        </svg>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </Modal>

    <!-- Manage Labels Modal -->
    <Modal :label="$t('Manage labels')" :isOpen="isOpenLabelsModal">
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
                                class="mr-2">
                                <path fill="currentColor"
                                    d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10s10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5l1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
                            </svg>
                            {{ $t('Selected Labels') }} ({{ selectedLabelsList.length }})
                        </h4>
                        <div class="space-y-2">
                            <div v-for="label in selectedLabelsList" :key="`selected-${label.id}`"
                                class="flex items-center space-x-3 p-2 bg-green-50 border border-green-200 rounded-md">
                                <input type="checkbox" :id="`selected-label-${label.id}`" :checked="true"
                                    @change="toggleLabel(label.id)"
                                    class="w-4 h-4 text-green-600 bg-gray-100 border-gray-300 rounded focus:ring-green-500 focus:ring-2" />
                                <label :for="`selected-label-${label.id}`"
                                    class="flex items-center space-x-2 cursor-pointer flex-1">
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
                                class="mr-2">
                                <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                    stroke-width="2" d="M20.59 13.41 12 22 4 14V4h10l8.59 8.59zM7 7h.01" />
                            </svg>
                            {{ $t('Available Labels') }} ({{ unselectedLabelsList.length }})
                        </h4>
                        <div class="space-y-2">
                            <div v-for="label in unselectedLabelsList" :key="`unselected-${label.id}`"
                                class="flex items-center space-x-3 p-2 bg-gray-50 border border-gray-200 rounded-md hover:bg-gray-100 transition-colors">
                                <input type="checkbox" :id="`unselected-label-${label.id}`" :checked="false"
                                    @change="toggleLabel(label.id)"
                                    class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 focus:ring-2" />
                                <label :for="`unselected-label-${label.id}`"
                                    class="flex items-center space-x-2 cursor-pointer flex-1">
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
                    <div class="flex space-x-2">
                        <button type="button" @click="isOpenLabelsModal = false"
                            class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                            {{ $t('Cancel') }}
                        </button>
                        <button @click="saveLabels"
                            :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': labelsForm.processing }]"
                            :disabled="labelsForm.processing">
                            <svg v-if="labelsForm.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20"
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