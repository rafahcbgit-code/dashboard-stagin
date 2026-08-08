<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import FormTextArea from '@/Components/FormTextArea.vue';

const props = defineProps({
    contact: {
        type: Object,
        required: true,
    },
    isOpen: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close', 'notesChanged', 'noteCreated']);

const notes = ref([]);
const content = ref('');
const editingNote = ref(null);
const confirmingDeleteUuid = ref(null);
const isLoading = ref(false);
const isSaving = ref(false);
const deletingUuid = ref(null);
const errorMessage = ref('');

const hasContent = computed(() => content.value.trim().length > 0);
const formTitle = computed(() => editingNote.value ? 'Edit note' : 'Add note');
const saveLabel = computed(() => editingNote.value ? 'Update note' : 'Add note');

const normalizeNotes = (items) => Array.isArray(items) ? items : [];

const resetForm = () => {
    content.value = '';
    editingNote.value = null;
};

const cleanupModalSideEffects = () => {
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
    };

    cleanup();
    window.requestAnimationFrame(cleanup);
    window.setTimeout(cleanup, 50);
    window.setTimeout(cleanup, 200);
};

const closeModal = () => {
    resetForm();
    confirmingDeleteUuid.value = null;
    errorMessage.value = '';
    emit('close');
    cleanupModalSideEffects();
};

const loadNotes = async () => {
    if (!props.contact?.uuid) {
        notes.value = [];
        return;
    }

    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.get('/notes', {
            params: {
                contact: props.contact.uuid,
            },
            headers: {
                Accept: 'application/json',
            },
        });

        notes.value = normalizeNotes(response.data?.notes);
        emit('notesChanged', notes.value);
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'Failed to load notes.';
    } finally {
        isLoading.value = false;
    }
};

const submitNote = async () => {
    if (!hasContent.value || isSaving.value) {
        return;
    }

    const isCreating = !editingNote.value;
    isSaving.value = true;
    errorMessage.value = '';

    try {
        if (editingNote.value) {
            const response = await axios.put(`/notes/${editingNote.value.uuid}`, {
                content: content.value.trim(),
            }, {
                headers: {
                    Accept: 'application/json',
                },
            });

            const updatedNote = response.data?.note;

            if (updatedNote) {
                notes.value = notes.value.map((note) => note.uuid === updatedNote.uuid ? updatedNote : note);
                emit('notesChanged', notes.value);
            }
        } else {
            const response = await axios.post('/notes', {
                contact: props.contact.uuid,
                notes: content.value.trim(),
            }, {
                headers: {
                    Accept: 'application/json',
                },
            });

            const createdNote = response.data?.note;

            if (createdNote) {
                notes.value = [createdNote, ...notes.value];
                emit('notesChanged', notes.value);
                emit('noteCreated', response.data?.timeline_item || [{
                    type: 'notes',
                    value: createdNote,
                }]);
            }
        }

        resetForm();

        if (isCreating) {
            closeModal();
            cleanupModalSideEffects();
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'Failed to save note.';
    } finally {
        isSaving.value = false;
    }
};

const editNote = (note) => {
    editingNote.value = note;
    content.value = note.content || '';
    confirmingDeleteUuid.value = null;
};

const askDelete = (note) => {
    confirmingDeleteUuid.value = note.uuid;
};

const deleteNote = async (note) => {
    if (deletingUuid.value) {
        return;
    }

    deletingUuid.value = note.uuid;
    errorMessage.value = '';

    try {
        await axios.delete(`/notes/${note.uuid}`, {
            headers: {
                Accept: 'application/json',
            },
        });

        notes.value = notes.value.filter((item) => item.uuid !== note.uuid);
        emit('notesChanged', notes.value);

        if (editingNote.value?.uuid === note.uuid) {
            resetForm();
        }

        confirmingDeleteUuid.value = null;
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'Failed to delete note.';
    } finally {
        deletingUuid.value = null;
    }
};

watch(
    () => props.isOpen,
    (isOpen) => {
        if (isOpen) {
            resetForm();
            confirmingDeleteUuid.value = null;
            loadNotes();
        }
    }
);

watch(
    () => props.contact?.uuid,
    () => {
        if (props.isOpen) {
            loadNotes();
        }
    }
);

onMounted(() => {
    if (props.isOpen) {
        loadNotes();
    }
});
</script>

<template>
    <div
        v-if="isOpen"
        class="fixed inset-0 z-[999999] overflow-y-auto"
        @click.self="closeModal"
    >
        <div class="fixed inset-0 bg-black bg-opacity-25" @click="closeModal"></div>

        <div class="relative flex min-h-full items-center justify-center p-4 text-center">
            <div class="relative w-full max-w-md transform overflow-hidden rounded-2xl bg-white text-left align-middle shadow-xl">
                <div class="flex items-center justify-between bg-gray-50 px-4 py-3">
                    <h3 class="text-lg font-medium leading-6 text-gray-900">
                        {{ $t('Conversation notes') }}
                    </h3>
                    <button
                        type="button"
                        class="rounded-full bg-slate-100 p-1 hover:shadow"
                        @click="closeModal"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                            <path fill="none" stroke="black" stroke-linecap="round" stroke-linejoin="round" d="M17 7L7 17M7 7l10 10"/>
                        </svg>
                    </button>
                </div>

                <div class="space-y-4 px-4 pb-4 pt-5 text-left">
            <div v-if="errorMessage" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ $t(errorMessage) }}
            </div>

            <form class="space-y-3 rounded-md border border-gray-100 bg-gray-50 p-3" @submit.prevent="submitNote">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm font-medium text-gray-900">{{ $t(formTitle) }}</h4>
                    <button
                        v-if="editingNote"
                        type="button"
                        class="text-xs text-gray-500 hover:text-gray-700"
                        @click="resetForm"
                    >
                        {{ $t('Cancel edit') }}
                    </button>
                </div>
                <FormTextArea v-model="content" :name="''" :class="'col-span-2'" />
                <div class="flex justify-end">
                    <button
                        type="submit"
                        :disabled="!hasContent || isSaving"
                        :class="[
                            'inline-flex items-center justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white',
                            { 'opacity-50': !hasContent || isSaving }
                        ]"
                    >
                        <svg v-if="isSaving" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
                            <path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5" />
                            <path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z">
                                <animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate" />
                            </path>
                        </svg>
                        <span v-else>{{ $t(saveLabel) }}</span>
                    </button>
                </div>
            </form>

            <div class="space-y-2">
                <div v-if="isLoading" class="py-6 text-center text-sm text-gray-500">
                    {{ $t('Loading notes...') }}
                </div>

                <div v-else-if="notes.length === 0" class="rounded-md border border-dashed border-gray-200 py-6 text-center text-sm text-gray-500">
                    {{ $t('No notes for this conversation yet.') }}
                </div>

                <div v-else class="max-h-80 space-y-2 overflow-y-auto pr-1">
                    <div v-for="note in notes" :key="note.uuid" class="rounded-md border border-gray-100 bg-white p-3 text-sm shadow-sm">
                        <div class="whitespace-pre-wrap text-gray-900">{{ note.content }}</div>
                        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
                            <span>
                                {{ note.created_by?.full_name || $t('Team member') }}
                                <span v-if="note.updated_at && note.updated_at !== note.created_at"> - {{ $t('Edited') }}</span>
                                - {{ note.created_at }}
                            </span>
                            <div class="flex items-center gap-3">
                                <button type="button" class="text-primary hover:underline" @click="editNote(note)">
                                    {{ $t('Edit') }}
                                </button>
                                <button type="button" class="text-red-600 hover:underline" @click="askDelete(note)">
                                    {{ $t('Delete') }}
                                </button>
                            </div>
                        </div>

                        <div v-if="confirmingDeleteUuid === note.uuid" class="mt-3 rounded-md bg-red-50 p-3 text-xs text-red-700">
                            <div>{{ $t('Are you sure you want to delete this note?') }}</div>
                            <div class="mt-2 flex gap-2">
                                <button
                                    type="button"
                                    class="rounded-md bg-red-600 px-3 py-1.5 text-white"
                                    :disabled="deletingUuid === note.uuid"
                                    @click="deleteNote(note)"
                                >
                                    {{ deletingUuid === note.uuid ? $t('Deleting...') : $t('Delete') }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md bg-white px-3 py-1.5 text-gray-700 ring-1 ring-gray-200"
                                    @click="confirmingDeleteUuid = null"
                                >
                                    {{ $t('Cancel') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
            </div>
        </div>
    </div>
</template>
