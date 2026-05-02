<template>
    <SettingLayout :aimodule="aimodule" :fbmodule="fbmodule">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center">
                <div class="md:w-[68em] w-full">
                    <div class="bg-white border border-slate-200 rounded-xl text-sm pb-6 px-6 mb-20">
                        <!-- Header -->
                        <div class="flex items-center justify-between pt-5 pb-4 border-b border-slate-100">
                            <div>
                                <h4 class="text-lg font-semibold text-slate-800">{{ $t('Knowledge Base') }}</h4>
                                <p class="text-sm text-slate-500 mt-0.5">{{ $t('Add Q&A pairs so AI can answer from your knowledge during fallback.') }}</p>
                            </div>
                            <button
                                @click="openCreateModal"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-[#045a47] transition-colors"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14m7-7H5"/></svg>
                                {{ $t('Add Entry') }}
                            </button>
                        </div>

                        <!-- Info Banner -->
                        <div class="mt-4 flex items-start gap-2 rounded-lg bg-violet-50 border border-violet-100 px-3.5 py-2.5 text-xs text-violet-800">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 mt-0.5"><path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446A9 9 0 1 1 12 3z"/><path d="M17 4a2 2 0 0 0 2 2a2 2 0 0 0-2 2a2 2 0 0 0-2-2a2 2 0 0 0 2-2"/><path d="M18.5 12a1.5 1.5 0 0 0 1.5 1.5a1.5 1.5 0 0 0-1.5 1.5a1.5 1.5 0 0 0-1.5-1.5a1.5 1.5 0 0 0 1.5-1.5"/></svg>
                            <span>{{ $t('When no trigger matches, AI searches your knowledge base to find an answer. Disabled entries are skipped.') }}</span>
                        </div>

                        <!-- Empty State -->
                        <div v-if="entries.length === 0" class="flex flex-col items-center justify-center py-16 text-slate-400">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mb-3 opacity-40"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                            <p class="text-sm font-medium text-slate-500">{{ $t('No knowledge entries yet') }}</p>
                            <p class="text-xs text-slate-400 mt-1">{{ $t('Add Q&A pairs so AI can answer questions from your knowledge.') }}</p>
                        </div>

                        <!-- Entries List -->
                        <div v-else class="mt-4 space-y-3">
                            <div
                                v-for="entry in entries"
                                :key="entry.uuid"
                                class="group relative rounded-lg border px-4 py-3.5 transition-all duration-150"
                                :class="entry.is_enabled
                                    ? 'bg-white border-slate-200 hover:border-slate-300 hover:shadow-sm'
                                    : 'bg-slate-50/60 border-slate-100 opacity-70'"
                            >
                                <!-- Q&A Content -->
                                <div class="flex items-start gap-3">
                                    <!-- Q/A Badge -->
                                    <div class="flex-shrink-0 mt-0.5 flex flex-col gap-1">
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-md text-[10px] font-bold bg-violet-100 text-violet-700">Q</span>
                                    </div>

                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-slate-800 leading-snug">{{ entry.question }}</p>
                                        <div class="flex items-start gap-3 mt-2">
                                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-md text-[10px] font-bold bg-emerald-100 text-emerald-700 shrink-0">A</span>
                                            <p class="text-sm text-slate-600 leading-relaxed">{{ entry.answer }}</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div class="absolute top-3 right-3 flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity" :class="entry.is_enabled ? '' : 'opacity-100'">
                                    <button
                                        @click="toggleEntry(entry)"
                                        class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-1"
                                        :class="entry.is_enabled ? 'bg-primary' : 'bg-slate-300'"
                                    >
                                        <span
                                            class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition-transform duration-200"
                                            :class="entry.is_enabled ? 'translate-x-[18px]' : 'translate-x-[3px]'"
                                        />
                                    </button>
                                    <button
                                        @click="openEditModal(entry)"
                                        class="p-1 rounded text-slate-400 hover:text-primary hover:bg-slate-50 transition-colors"
                                        :title="$t('Edit')"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </button>
                                    <button
                                        @click="deleteEntry(entry)"
                                        class="p-1 rounded text-slate-400 hover:text-red-500 hover:bg-red-50 transition-colors"
                                        :title="$t('Delete')"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <Modal :isOpen="showModal" @close="closeModal" :label="editingEntry ? $t('Edit Entry') : $t('Add Knowledge Entry')" :closeBtn="true">
            <form @submit.prevent="submitForm" class="space-y-5 mt-1">
                <!-- Question -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">{{ $t('Question') }}</label>
                    <textarea
                        v-model="form.question"
                        rows="2"
                        class="w-full rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-800 placeholder:text-slate-400 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors resize-none"
                        :placeholder="$t('e.g., What is your return policy?')"
                        required
                    />
                </div>

                <!-- Answer -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">{{ $t('Answer') }}</label>
                    <textarea
                        v-model="form.answer"
                        rows="4"
                        class="w-full rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-800 placeholder:text-slate-400 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors resize-y"
                        :placeholder="$t('e.g., You can return any item within 30 days of purchase...')"
                        required
                    />
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-2.5 pt-3 border-t border-slate-100">
                    <button type="button" @click="closeModal" class="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50 transition-colors">
                        {{ $t('Cancel') }}
                    </button>
                    <button type="submit" class="rounded-lg bg-primary px-5 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-[#045a47] transition-colors">
                        {{ editingEntry ? $t('Save Changes') : $t('Add Entry') }}
                    </button>
                </div>
            </form>
        </Modal>
    </SettingLayout>
</template>

<script setup>
import SettingLayout from './../Layout.vue';
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { trans } from 'laravel-vue-i18n';

const props = defineProps(['entries', 'aimodule', 'fbmodule']);

const showModal = ref(false);
const editingEntry = ref(null);

const defaultForm = () => ({
    question: '',
    answer: '',
    is_enabled: true,
});

const form = ref(defaultForm());

function openCreateModal() {
    editingEntry.value = null;
    form.value = defaultForm();
    showModal.value = true;
}

function openEditModal(entry) {
    editingEntry.value = entry;
    form.value = {
        question: entry.question,
        answer: entry.answer,
        is_enabled: entry.is_enabled,
    };
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    editingEntry.value = null;
    form.value = defaultForm();
}

function submitForm() {
    if (editingEntry.value) {
        router.put(`/automation/knowledge-base/${editingEntry.value.uuid}`, form.value, {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    } else {
        router.post('/automation/knowledge-base', form.value, {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    }
}

function toggleEntry(entry) {
    router.post(`/automation/knowledge-base/${entry.uuid}/toggle`, {
        is_enabled: !entry.is_enabled,
    }, { preserveScroll: true });
}

function deleteEntry(entry) {
    if (!confirm(trans('Are you sure you want to delete this entry?'))) return;
    router.delete(`/automation/knowledge-base/${entry.uuid}`, { preserveScroll: true });
}
</script>