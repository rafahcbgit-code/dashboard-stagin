<template>
    <SettingLayout :aimodule="aimodule" :fbmodule="fbmodule">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center">
                <div class="md:w-[68em] w-full">
                    <div class="bg-white border border-slate-200 rounded-xl text-sm pb-6 px-6 mb-20">
                        <div class="flex items-center justify-between pt-5 pb-4 border-b border-slate-100">
                            <div>
                                <h4 class="text-lg font-semibold text-slate-800">{{ $t('Smart Followup') }}</h4>
                                <p class="text-sm text-slate-500 mt-0.5">{{ $t('Send context-aware followups when customers pause important automations.') }}</p>
                            </div>
                            <button
                                type="button"
                                @click="openCreateModal"
                                :disabled="availableFlowsForCreate.length === 0"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-[#045a47] transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14m7-7H5"/></svg>
                                {{ $t('Create Reminder') }}
                            </button>
                        </div>

                        <div class="mt-6">
                            <div class="flex items-center gap-2 mb-2">
                                <div class="w-1 h-5 rounded-full bg-indigo-500"></div>
                                <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wide">{{ $t('Rejoin Reminder') }}</h3>
                            </div>
                            <p class="text-xs text-slate-400 mb-4">{{ $t('Create one reminder message per flow. Each flow can only have one Rejoin Reminder.') }}</p>

                            <div v-if="localReminders.length === 0" class="flex flex-col items-center justify-center rounded-lg border border-dashed border-slate-200 py-14 text-center">
                                <div class="w-11 h-11 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center mb-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                </div>
                                <p class="text-sm font-medium text-slate-600">{{ $t('No rejoin reminders yet') }}</p>
                                <p class="text-xs text-slate-400 mt-1">{{ $t('Create a reminder for a flow to bring customers back when they stop responding.') }}</p>
                            </div>

                            <div v-else class="rounded-lg border border-slate-200 overflow-hidden">
                                <div class="grid grid-cols-[1.2fr_1.8fr_0.8fr_0.6fr_0.7fr] gap-3 bg-slate-50 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    <div>{{ $t('Flow') }}</div>
                                    <div>{{ $t('Message') }}</div>
                                    <div>{{ $t('Send after') }}</div>
                                    <div>{{ $t('Status') }}</div>
                                    <div class="text-right">{{ $t('Actions') }}</div>
                                </div>

                                <div
                                    v-for="reminder in localReminders"
                                    :key="reminder.uuid"
                                    class="grid grid-cols-[1.2fr_1.8fr_0.8fr_0.6fr_0.7fr] gap-3 items-center px-4 py-3 border-t border-slate-100"
                                >
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-medium text-slate-800">{{ reminder.flow?.name || $t('Deleted flow') }}</div>
                                        <div class="text-[10px] text-slate-400 truncate">{{ reminder.flow?.uuid || '-' }}</div>
                                    </div>
                                    <div class="min-w-0 text-sm text-slate-600 truncate">{{ reminder.message }}</div>
                                    <div class="text-sm text-slate-600">{{ reminder.reminder_minutes }} {{ $t('min') }}</div>
                                    <div>
                                        <button
                                            type="button"
                                            @click="toggleReminder(reminder)"
                                            class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-1"
                                            :class="reminder.is_enabled ? 'bg-primary' : 'bg-slate-300'"
                                        >
                                            <span
                                                class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition-transform duration-200"
                                                :class="reminder.is_enabled ? 'translate-x-[18px]' : 'translate-x-[3px]'"
                                            />
                                        </button>
                                    </div>
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            @click="openEditModal(reminder)"
                                            class="p-1 rounded text-slate-400 hover:text-primary hover:bg-slate-50 transition-colors"
                                            :title="$t('Edit')"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </button>
                                        <button
                                            type="button"
                                            @click="deleteReminder(reminder)"
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
        </div>

        <Modal :isOpen="showModal" @close="closeModal" :label="editingReminder ? $t('Edit Rejoin Reminder') : $t('Create Rejoin Reminder')" :closeBtn="true">
            <form @submit.prevent="submitForm" class="space-y-5 mt-1">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">{{ $t('Flow') }}</label>
                    <select
                        v-if="!editingReminder"
                        v-model.number="form.flow_id"
                        class="w-full rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-800 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors"
                        required
                    >
                        <option :value="null" disabled>{{ $t('Select a flow') }}</option>
                        <option v-for="flow in availableFlowsForCreate" :key="flow.id" :value="flow.id">
                            {{ flow.name }}
                        </option>
                    </select>
                    <div v-else class="rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-2.5">
                        <div class="text-sm font-medium text-slate-700">{{ editingReminder.flow?.name || $t('Deleted flow') }}</div>
                        <div class="text-[10px] text-slate-400">{{ editingReminder.flow?.uuid || '-' }}</div>
                    </div>
                    <p v-if="$page.props.errors?.flow_id" class="text-xs text-red-500 mt-1">{{ $page.props.errors.flow_id }}</p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">{{ $t('Send reminder after') }}</label>
                    <div class="flex items-center gap-3">
                        <input
                            v-model.number="form.reminder_minutes"
                            type="number"
                            min="5"
                            max="1440"
                            class="w-24 rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-center text-slate-800 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors"
                            required
                        />
                        <span class="text-sm text-slate-500">{{ $t('minutes of inactivity') }}</span>
                    </div>
                    <p v-if="$page.props.errors?.reminder_minutes" class="text-xs text-red-500 mt-1">{{ $page.props.errors.reminder_minutes }}</p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">{{ $t('Reminder message') }}</label>
                    <textarea
                        v-model="form.message"
                        rows="4"
                        maxlength="500"
                        class="w-full rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-800 placeholder:text-slate-400 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors resize-none"
                        :placeholder="$t('You have an active conversation with us. Reply to continue where you left off.')"
                        required
                    />
                    <div class="flex justify-between mt-1">
                        <p v-if="$page.props.errors?.message" class="text-xs text-red-500">{{ $page.props.errors.message }}</p>
                        <p class="text-[10px] text-slate-400 ml-auto">{{ form.message.length }}/500</p>
                    </div>
                </div>

                <div class="flex items-center justify-between rounded-lg border border-slate-200 p-3">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700">{{ $t('Enabled') }}</label>
                        <p class="text-xs text-slate-400">{{ $t('Disabled reminders are saved but will not send.') }}</p>
                    </div>
                    <button
                        type="button"
                        @click="form.is_enabled = !form.is_enabled"
                        class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-1"
                        :class="form.is_enabled ? 'bg-primary' : 'bg-slate-300'"
                    >
                        <span
                            class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition-transform duration-200"
                            :class="form.is_enabled ? 'translate-x-[18px]' : 'translate-x-[3px]'"
                        />
                    </button>
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" @click="closeModal" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50 transition-colors">
                        {{ $t('Cancel') }}
                    </button>
                    <button type="submit" :disabled="saving" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-[#045a47] transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                        {{ saving ? $t('Saving...') : $t('Save') }}
                    </button>
                </div>
            </form>
        </Modal>
    </SettingLayout>
</template>

<script setup>
import SettingLayout from './../Layout.vue';
import Modal from '@/Components/Modal.vue';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps(['rejoinReminders', 'flows', 'aimodule', 'fbmodule']);

const localReminders = ref([...(props.rejoinReminders || [])]);
const showModal = ref(false);
const saving = ref(false);
const editingReminder = ref(null);

const defaultForm = () => ({
    flow_id: null,
    message: 'You have an active conversation with us. Reply to continue where you left off.',
    reminder_minutes: 15,
    is_enabled: true,
});

const form = ref(defaultForm());

watch(() => props.rejoinReminders, (value) => {
    localReminders.value = [...(value || [])];
});

const flows = computed(() => (props.flows || []).map(flow => ({
    ...flow,
    id: Number(flow.id),
})));

const usedFlowIds = computed(() => new Set(localReminders.value.map(reminder => Number(reminder.flow_id))));

const availableFlowsForCreate = computed(() => flows.value.filter(flow => !usedFlowIds.value.has(flow.id)));

function openCreateModal() {
    editingReminder.value = null;
    form.value = defaultForm();
    showModal.value = true;
}

function openEditModal(reminder) {
    editingReminder.value = reminder;
    form.value = {
        flow_id: reminder.flow_id,
        message: reminder.message || '',
        reminder_minutes: reminder.reminder_minutes || 15,
        is_enabled: reminder.is_enabled ?? true,
    };
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    saving.value = false;
    editingReminder.value = null;
    form.value = defaultForm();
}

function submitForm() {
    saving.value = true;

    const payload = {
        message: form.value.message,
        reminder_minutes: form.value.reminder_minutes,
        is_enabled: form.value.is_enabled,
    };

    if (editingReminder.value) {
        router.put(`/automation/smart-followup/rejoin-reminders/${editingReminder.value.uuid}`, payload, {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onFinish: () => saving.value = false,
        });
        return;
    }

    router.post('/automation/smart-followup/rejoin-reminders', {
        ...payload,
        flow_id: form.value.flow_id,
    }, {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onFinish: () => saving.value = false,
    });
}

function toggleReminder(reminder) {
    router.post(`/automation/smart-followup/rejoin-reminders/${reminder.uuid}/toggle`, {
        is_enabled: !reminder.is_enabled,
    }, { preserveScroll: true });
}

function deleteReminder(reminder) {
    if (!confirm('Delete this rejoin reminder?')) {
        return;
    }

    router.delete(`/automation/smart-followup/rejoin-reminders/${reminder.uuid}`, {
        preserveScroll: true,
    });
}
</script>
