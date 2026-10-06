<template>
    <SettingLayout :modules="props.modules">
        <div class="flex items-start justify-center px-2 pb-8">
            <section class="w-full max-w-5xl overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <header class="flex flex-col gap-4 border-b border-slate-100 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
                    <div class="text-start">
                        <h1 class="text-xl font-semibold text-slate-900">{{ $t('Labels') }}</h1>
                        <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600">{{ $t('Labels page introduction') }}</p>
                    </div>
                    <button type="button" @click="openModal()"
                        class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-white transition hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        {{ $t('Add Label') }}
                    </button>
                </header>

                <div class="space-y-5 p-4 sm:p-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="relative w-full sm:max-w-sm">
                            <label class="sr-only" for="labels-search">{{ $t('Search labels') }}</label>
                            <svg aria-hidden="true" class="pointer-events-none absolute start-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <circle cx="11" cy="11" r="7" stroke-width="2" />
                                <path stroke-linecap="round" stroke-width="2" d="m16 16 4 4" />
                            </svg>
                            <input id="labels-search" v-model="search" type="search" :placeholder="$t('Search labels')"
                                class="w-full rounded-lg border border-slate-300 py-2.5 pe-10 ps-10 text-sm text-slate-900 placeholder:text-slate-400 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" />
                            <button v-if="search" type="button" @click="search = ''" :aria-label="$t('Clear search')"
                                class="absolute end-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-width="2" d="m6 6 12 12M18 6 6 18" /></svg>
                            </button>
                        </div>
                        <p class="text-sm text-slate-500" aria-live="polite">
                            {{ $t('Labels count', { count: props.labels.length }) }}
                        </p>
                    </div>

                    <div v-if="filteredLabels.length" class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <article v-for="label in filteredLabels" :key="label.id"
                            class="flex min-w-0 items-center justify-between gap-3 rounded-xl border border-slate-200 p-4 transition hover:border-slate-300 hover:shadow-sm">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg" :style="colorBadgeStyle(label.color)" aria-hidden="true">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.59 13.41 12 22 4 14V4h10l8.59 8.59zM7 7h.01" />
                                    </svg>
                                </span>
                                <div class="min-w-0 text-start">
                                    <h2 class="truncate font-medium text-slate-900">{{ label.title }}</h2>
                                    <p class="mt-1 text-sm text-slate-500">{{ $t('Contacts count', { count: label.contact_count || 0 }) }}</p>
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-1">
                                <button type="button" @click="openCreateGroupModal(label)" :aria-label="`${$t('Create Group from Label')}: ${label.title}`" :title="$t('Create Group from Label')"
                                    class="rounded-lg p-2 text-emerald-700 hover:bg-emerald-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                                </button>
                                <button type="button" @click="editLabel(label)" :aria-label="`${$t('Edit Label')}: ${label.title}`" :title="$t('Edit Label')"
                                    class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m16.862 4.487 2.651 2.651M5 19l4.5-1 10.013-10.013a1.875 1.875 0 0 0-2.652-2.652L6.848 15.348 5 19Z" /></svg>
                                </button>
                                <button type="button" @click="confirmDelete(label)" :aria-label="`${$t('Delete Label')}: ${label.title}`" :title="$t('Delete')"
                                    class="rounded-lg p-2 text-slate-500 hover:bg-red-50 hover:text-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500">
                                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7 18 19a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 7m4 0V4h6v3m-11 0h16" /></svg>
                                </button>
                            </div>
                        </article>
                    </div>

                    <div v-else class="rounded-xl border border-dashed border-slate-300 px-5 py-12 text-center">
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-500">
                            <svg aria-hidden="true" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20.59 13.41 12 22 4 14V4h10l8.59 8.59zM7 7h.01" /></svg>
                        </div>
                        <h2 class="mt-3 font-medium text-slate-900">{{ search ? $t('No matching labels') : $t('No labels yet') }}</h2>
                        <p class="mx-auto mt-1 max-w-md text-sm leading-6 text-slate-500">{{ search ? $t('Try a different search term.') : $t('Create labels to organize contacts and build audiences from them.') }}</p>
                        <button v-if="!search" type="button" @click="openModal()" class="mt-4 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">{{ $t('Add Label') }}</button>
                    </div>
                </div>
            </section>
        </div>

        <Modal :label="$t(modalTitle)" :isOpen="isOpen" @close="isOpen = false">
            <form @submit.prevent="submit" class="space-y-5 pt-4">
                <FormInput v-model="form.title" :name="$t('Title')" :error="form.errors.title" />
                <div class="flex flex-wrap items-center justify-between gap-4 rounded-lg bg-slate-50 p-4">
                    <div class="text-start">
                        <label for="label-color" class="block text-sm font-medium text-slate-800">{{ $t('Color') }}</label>
                        <p class="mt-1 text-xs text-slate-500">{{ $t('Choose a color to recognize this label.') }}</p>
                    </div>
                    <input id="label-color" type="color" v-model="form.color" class="h-10 w-14 cursor-pointer rounded border border-slate-300 bg-white p-1" />
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" @click="isOpen = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">{{ $t('Cancel') }}</button>
                    <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2" :disabled="form.processing">{{ $t('Save') }}</button>
                </div>
            </form>
        </Modal>

        <Modal :label="$t('Create Group from Label')" :isOpen="isCreateGroupOpen" @close="isCreateGroupOpen = false">
            <form @submit.prevent="submitCreateGroup" class="space-y-5 pt-4">
                <div class="flex items-center gap-3 rounded-lg bg-slate-50 p-3 text-start">
                    <span class="flex h-10 w-10 items-center justify-center rounded-lg" :style="colorBadgeStyle(selectedLabel?.color)" aria-hidden="true">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.59 13.41 12 22 4 14V4h10l8.59 8.59zM7 7h.01" /></svg>
                    </span>
                    <div class="min-w-0"><p class="truncate font-medium text-slate-900">{{ selectedLabel?.title }}</p><p class="text-sm text-slate-500">{{ $t('Contacts count', { count: selectedLabel?.contact_count || 0 }) }}</p></div>
                </div>
                <FormInput v-model="createGroupForm.name" :name="$t('Group Name')" :error="createGroupForm.errors.name" />
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" @click="isCreateGroupOpen = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">{{ $t('Cancel') }}</button>
                    <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2" :disabled="createGroupForm.processing">{{ $t('Create Group') }}</button>
                </div>
            </form>
        </Modal>

        <Modal :label="$t('Delete Label')" :isOpen="isDeleteOpen" @close="isDeleteOpen = false">
            <div class="space-y-5 pt-4 text-start">
                <p class="text-sm leading-6 text-slate-600">{{ $t('Confirm delete label', { name: deletingLabel?.title || '' }) }}</p>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" @click="isDeleteOpen = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">{{ $t('Cancel') }}</button>
                    <button type="button" @click="destroy" class="rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2">{{ $t('Delete') }}</button>
                </div>
            </div>
        </Modal>
    </SettingLayout>
</template>

<script setup>
import SettingLayout from './Layout.vue';
import Modal from '@/Components/Modal.vue';
import FormInput from '@/Components/FormInput.vue';
import { useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    labels: { type: Array, default: () => [] },
    settings: Object,
    modules: Array,
});

const isOpen = ref(false);
const isCreateGroupOpen = ref(false);
const isDeleteOpen = ref(false);
const modalTitle = ref('Add Label');
const selectedLabel = ref(null);
const deletingLabel = ref(null);
const search = ref('');
const filteredLabels = computed(() => {
    const term = search.value.trim().toLocaleLowerCase();
    if (!term) return props.labels;
    return props.labels.filter((label) => String(label.title || '').toLocaleLowerCase().includes(term));
});

const form = useForm({ id: null, title: '', color: '#000000' });
const createGroupForm = useForm({ name: '', label_id: '' });

const colorBadgeStyle = (color) => ({
    color: color || '#475569',
    backgroundColor: `${color || '#94a3b8'}18`,
});

const openModal = () => {
    form.reset();
    form.clearErrors();
    form.id = null;
    modalTitle.value = 'Add Label';
    isOpen.value = true;
};

const editLabel = (label) => {
    form.clearErrors();
    form.id = label.id;
    form.title = label.title;
    form.color = label.color || '#000000';
    modalTitle.value = 'Edit Label';
    isOpen.value = true;
};

const openCreateGroupModal = (label) => {
    createGroupForm.reset();
    createGroupForm.clearErrors();
    createGroupForm.label_id = label.id;
    selectedLabel.value = label;
    isCreateGroupOpen.value = true;
};

const submit = () => {
    const options = { onSuccess: () => { isOpen.value = false; } };
    if (form.id) form.put(`/settings/labels/${form.id}`, options);
    else form.post('/settings/labels', options);
};

const submitCreateGroup = () => {
    createGroupForm.post('/settings/labels/create-group', {
        onSuccess: () => {
            isCreateGroupOpen.value = false;
            createGroupForm.reset();
        },
    });
};

const confirmDelete = (label) => {
    deletingLabel.value = label;
    isDeleteOpen.value = true;
};

const destroy = () => {
    if (!deletingLabel.value) return;
    router.delete(`/settings/labels/${deletingLabel.value.id}`, {
        onSuccess: () => { isDeleteOpen.value = false; deletingLabel.value = null; },
    });
};
</script>
