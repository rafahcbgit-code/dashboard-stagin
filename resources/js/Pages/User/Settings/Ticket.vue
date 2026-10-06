<template>
    <SettingLayout :modules="props.modules">
        <div class="pb-20">
            <main class="mx-auto w-full max-w-4xl px-3 sm:px-5">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
                <header class="mb-5">
                    <h1 class="text-xl font-semibold text-slate-900">{{ $t('Ticketing settings') }}</h1>
                    <p class="mt-1 text-sm leading-6 text-slate-600">{{ $t('Ticketing settings introduction') }}</p>
                </header>

                    <!-- Enable ticketing -->
                    <section class="mb-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="ticketing-enabled-title">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 id="ticketing-enabled-title" class="font-semibold text-slate-900">{{ $t('Enable ticketing') }}</h2>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="form.active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'">{{ $t(form.active ? 'Enabled' : 'Disabled') }}</span>
                                </div>
                                <p class="mt-1 text-sm leading-6 text-slate-600">{{ $t('Ticketing workflow explanation') }}</p>
                            </div>
                            <button type="button" role="switch" :aria-checked="form.active" :aria-label="$t('Enable ticketing')" class="relative mt-1 inline-flex h-6 w-12 shrink-0 items-center rounded-full p-1 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700" :class="form.active ? 'bg-primary' : 'bg-slate-300'" @click="toggleState1">
                                <span class="sr-only">{{ $t('Enable ticketing') }}</span>
                                <span class="absolute start-1 size-4 rounded-full bg-white shadow transition-transform" :class="form.active ? 'ltr:translate-x-6 rtl:translate-x-[-24px]' : ''" aria-hidden="true"></span>
                            </button>
                        </div>
                    </section>

                    <!-- Auto assignment -->
                    <section v-if="form.active" class="mb-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="auto-assignment-title">
                        <div class="mb-4">
                            <h2 id="auto-assignment-title" class="font-semibold text-slate-900">{{ $t('Auto assignment') }}</h2>
                            <p class="mt-1 text-sm leading-6 text-slate-600">{{ $t('Use auto-assignment rules to evenly distribute chats among agent automatically.') }}</p>
                        </div>
                        <fieldset>
                            <legend class="sr-only">{{ $t('Choose assignment method') }}</legend>
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                                <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition focus-within:ring-2 focus-within:ring-primary" :class="assignmentMode === 'off' ? 'border-primary bg-emerald-50/50' : 'border-slate-200 hover:bg-slate-50'">
                                    <input v-model="assignmentMode" type="radio" name="assignment_mode" value="off" class="mt-1 size-4 shrink-0 accent-emerald-700 focus:ring-primary" />
                                    <span><span class="block font-medium text-slate-900">{{ $t('Off') }}</span><span class="mt-1 block text-sm leading-5 text-slate-600">{{ $t('Team members pick conversations manually from Unassigned folder.') }}</span></span>
                                </label>
                                <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition focus-within:ring-2 focus-within:ring-primary" :class="assignmentMode === 'auto' ? 'border-primary bg-emerald-50/50' : 'border-slate-200 hover:bg-slate-50'">
                                    <input v-model="assignmentMode" type="radio" name="assignment_mode" value="auto" class="mt-1 size-4 shrink-0 accent-emerald-700 focus:ring-primary" />
                                    <span><span class="block font-medium text-slate-900">{{ $t('Auto') }}</span><span class="mt-1 block text-sm leading-5 text-slate-600">{{ $t('Distribute conversations among all your available team members.') }}</span></span>
                                </label>
                                <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition focus-within:ring-2 focus-within:ring-primary" :class="assignmentMode === 'round_robin' ? 'border-primary bg-emerald-50/50' : 'border-slate-200 hover:bg-slate-50'">
                                    <input v-model="assignmentMode" type="radio" name="assignment_mode" value="round_robin" class="mt-1 size-4 shrink-0 accent-emerald-700 focus:ring-primary" />
                                    <span><span class="block font-medium text-slate-900">{{ $t('Round-robin assignment') }}</span><span class="mt-1 block text-sm leading-5 text-slate-600">{{ $t('Round-robin assignment distributes chats equally among available agents.') }}</span></span>
                                </label>
                            </div>
                        </fieldset>
                    </section>


                    <!-- Assigned to specific agents -->
                    <div v-if="form.active && (form.auto_assignment || form.auto_robinhood)"
                        class="mb-4 rounded-xl border border-slate-200 bg-white p-4 text-sm shadow-sm sm:p-5">
                        <div class="w-full py-2 mb-2 mt-2">
                            <div class="flex w-full mb-4">
                                <div class="text-md">
                                    <h4 class="text-[16px]">{{ $t('Assigned to specific agents') }}</h4>
                                    <div class="mb-1 text-slate-500">
                                        {{ $t('Select the specific agents who will receive auto-assigned conversations.') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mb-4">
                            <div class="rounded-lg border border-slate-200 p-4">
                                <h4 class="text-lg font-semibold mb-3">{{ $t('Select Agents') }}</h4>
                                <ul v-if="props.agents?.length" class="grid max-h-64 grid-cols-1 gap-2 overflow-y-auto sm:grid-cols-2">
                                    <li v-for="agent in props.agents" :key="agent.id"
                                        class="flex items-center gap-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <input type="checkbox" class="size-4 shrink-0 rounded accent-emerald-700 focus:ring-primary"
                                            :checked="form.selected_agents.includes(Number(agent.id))"
                                            @change="toggleAgent(agent.id)" :id="'agent_' + agent.id">
                                        <label :for="'agent_' + agent.id" class="min-w-0 cursor-pointer text-slate-800">
                                            {{ agent.first_name }} {{ agent.last_name }}
                                        </label>
                                    </li>
                                </ul>
                                <p v-else class="rounded-md bg-slate-50 p-3 text-sm leading-6 text-slate-600">{{ $t('No agents available for assignment') }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Reassign reopened chats -->
                    <section v-if="form.active" class="mb-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0"><h2 class="font-semibold text-slate-900">{{ $t('Reassign chats that have been reopened') }}</h2><p class="mt-1 text-sm leading-6 text-slate-600">{{ $t('Enable this option to reassign chats when a contact re-opens closed conversation.') }}</p></div>
                            <button type="button" role="switch" :aria-checked="form.reassign_reopened_chats" :aria-label="$t('Reassign chats that have been reopened')" class="relative mt-1 inline-flex h-6 w-12 shrink-0 items-center rounded-full p-1 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700" :class="form.reassign_reopened_chats ? 'bg-primary' : 'bg-slate-300'" @click="toggleState2"><span class="sr-only">{{ $t('Reassign chats that have been reopened') }}</span><span class="absolute start-1 size-4 rounded-full bg-white shadow transition-transform" :class="form.reassign_reopened_chats ? 'ltr:translate-x-6 rtl:translate-x-[-24px]' : ''" aria-hidden="true"></span></button>
                        </div>
                    </section>


                    <!-- Allow agents to view all chats -->
                    <section v-if="form.active" class="mb-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0"><h2 class="font-semibold text-slate-900">{{ $t('Grant agents access to view all chats not assigned to them') }}</h2><p class="mt-1 text-sm leading-6 text-slate-600">{{ $t('Disable this option, if you want live chat agents to have access only to new conversations.') }}</p></div>
                            <button type="button" role="switch" :aria-checked="form.allow_agents_to_view_all_chats" :aria-label="$t('Grant agents access to view all chats not assigned to them')" class="relative mt-1 inline-flex h-6 w-12 shrink-0 items-center rounded-full p-1 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700" :class="form.allow_agents_to_view_all_chats ? 'bg-primary' : 'bg-slate-300'" @click="toggleState3"><span class="sr-only">{{ $t('Grant agents access to view all chats not assigned to them') }}</span><span class="absolute start-1 size-4 rounded-full bg-white shadow transition-transform" :class="form.allow_agents_to_view_all_chats ? 'ltr:translate-x-6 rtl:translate-x-[-24px]' : ''" aria-hidden="true"></span></button>
                        </div>
                    </section>

                    <!-- Mask phone numbers for agents -->
                    <section v-if="form.active" class="mb-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0"><h2 class="font-semibold text-slate-900">{{ $t('Mask phone numbers for agents') }}</h2><p class="mt-1 text-sm leading-6 text-slate-600">{{ $t('When enabled, agents can only see the first 3 and last 2 digits of phone numbers.') }}</p></div>
                            <button type="button" role="switch" :aria-checked="form.mask_agent_phone_numbers" :aria-label="$t('Mask phone numbers for agents')" class="relative mt-1 inline-flex h-6 w-12 shrink-0 items-center rounded-full p-1 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700" :class="form.mask_agent_phone_numbers ? 'bg-primary' : 'bg-slate-300'" @click="toggleState4"><span class="sr-only">{{ $t('Mask phone numbers for agents') }}</span><span class="absolute start-1 size-4 rounded-full bg-white shadow transition-transform" :class="form.mask_agent_phone_numbers ? 'ltr:translate-x-6 rtl:translate-x-[-24px]' : ''" aria-hidden="true"></span></button>
                        </div>
                    </section>

                    <!-- Note email notifications -->
                    <section v-if="form.active" class="mb-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="email-note-notification-title">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0"><h2 id="email-note-notification-title" class="font-semibold text-slate-900">{{ $t('Email notification when a note is added') }}</h2><p class="mt-1 text-sm leading-6 text-slate-600">{{ $t('Send an email after a team member adds a note to a conversation.') }}</p></div>
                            <button type="button" role="switch" :aria-checked="form.note_email_notifications_enabled" :aria-label="$t('Email notification when a note is added')" class="relative mt-1 inline-flex h-6 w-12 shrink-0 items-center rounded-full p-1 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700" :class="form.note_email_notifications_enabled ? 'bg-primary' : 'bg-slate-300'" @click="toggleState5"><span class="sr-only">{{ $t('Email notification when a note is added') }}</span><span class="absolute start-1 size-4 rounded-full bg-white shadow transition-transform" :class="form.note_email_notifications_enabled ? 'ltr:translate-x-6 rtl:translate-x-[-24px]' : ''" aria-hidden="true"></span></button>
                        </div>
                        <fieldset v-if="form.note_email_notifications_enabled" class="mt-4">
                            <legend class="mb-2 text-sm font-medium text-slate-700">{{ $t('Who should receive the email?') }}</legend>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 focus-within:ring-2 focus-within:ring-primary" :class="form.note_email_notifications_recipient_scope === 'assigned_agent' ? 'border-primary bg-emerald-50/50' : 'border-slate-200'">
                                    <input v-model="form.note_email_notifications_recipient_scope" type="radio" name="note_email_recipient_scope" value="assigned_agent" class="mt-1 size-4 shrink-0 accent-emerald-700 focus:ring-primary" />
                                    <span><span class="block font-medium text-slate-900">{{ $t('Assigned agent only') }}</span><span class="mt-1 block text-sm leading-5 text-slate-600">{{ $t('Notify only the team member assigned to the conversation.') }}</span></span>
                                </label>
                                <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 focus-within:ring-2 focus-within:ring-primary" :class="form.note_email_notifications_recipient_scope === 'all_team' ? 'border-primary bg-emerald-50/50' : 'border-slate-200'">
                                    <input v-model="form.note_email_notifications_recipient_scope" type="radio" name="note_email_recipient_scope" value="all_team" class="mt-1 size-4 shrink-0 accent-emerald-700 focus:ring-primary" />
                                    <span><span class="block font-medium text-slate-900">{{ $t('All team members') }}</span><span class="mt-1 block text-sm leading-5 text-slate-600">{{ $t('Notify every active team member in this organization.') }}</span></span>
                                </label>
                            </div>
                        </fieldset>
                    </section>

                    <!-- Note platform notifications -->
                    <section v-if="form.active" class="mb-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="platform-note-notification-title">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0"><h2 id="platform-note-notification-title" class="font-semibold text-slate-900">{{ $t('Platform notification when a note is added') }}</h2><p class="mt-1 text-sm leading-6 text-slate-600">{{ $t('Show an in-app notification with sound after a team member adds a note.') }}</p></div>
                            <button type="button" role="switch" :aria-checked="form.note_platform_notifications_enabled" :aria-label="$t('Platform notification when a note is added')" class="relative mt-1 inline-flex h-6 w-12 shrink-0 items-center rounded-full p-1 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700" :class="form.note_platform_notifications_enabled ? 'bg-primary' : 'bg-slate-300'" @click="toggleState6"><span class="sr-only">{{ $t('Platform notification when a note is added') }}</span><span class="absolute start-1 size-4 rounded-full bg-white shadow transition-transform" :class="form.note_platform_notifications_enabled ? 'ltr:translate-x-6 rtl:translate-x-[-24px]' : ''" aria-hidden="true"></span></button>
                        </div>
                        <fieldset v-if="form.note_platform_notifications_enabled" class="mt-4">
                            <legend class="mb-2 text-sm font-medium text-slate-700">{{ $t('Who should receive the notification?') }}</legend>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 focus-within:ring-2 focus-within:ring-primary" :class="form.note_platform_notifications_recipient_scope === 'assigned_agent' ? 'border-primary bg-emerald-50/50' : 'border-slate-200'">
                                    <input v-model="form.note_platform_notifications_recipient_scope" type="radio" name="note_platform_recipient_scope" value="assigned_agent" class="mt-1 size-4 shrink-0 accent-emerald-700 focus:ring-primary" />
                                    <span><span class="block font-medium text-slate-900">{{ $t('Assigned agent only') }}</span><span class="mt-1 block text-sm leading-5 text-slate-600">{{ $t('Notify only the team member assigned to the conversation.') }}</span></span>
                                </label>
                                <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 focus-within:ring-2 focus-within:ring-primary" :class="form.note_platform_notifications_recipient_scope === 'all_team' ? 'border-primary bg-emerald-50/50' : 'border-slate-200'">
                                    <input v-model="form.note_platform_notifications_recipient_scope" type="radio" name="note_platform_recipient_scope" value="all_team" class="mt-1 size-4 shrink-0 accent-emerald-700 focus:ring-primary" />
                                    <span><span class="block font-medium text-slate-900">{{ $t('All team members') }}</span><span class="mt-1 block text-sm leading-5 text-slate-600">{{ $t('Notify every active team member in this organization.') }}</span></span>
                                </label>
                            </div>
                        </fieldset>
                    </section>

                    <footer class="sticky bottom-0 z-10 mt-5 flex flex-col-reverse gap-3 border-t border-slate-200 bg-white/95 py-4 backdrop-blur sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-slate-600" aria-live="polite">{{ form.isDirty ? $t('Unsaved ticketing changes') : (form.wasSuccessful ? $t('Ticketing changes saved') : $t('No unsaved ticketing changes')) }}</p>
                        <button type="button" :disabled="form.processing || !form.isDirty" @click="submitForm" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white shadow-sm hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50">
                            <svg v-if="form.processing" class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            {{ form.processing ? $t('Saving...') : $t('Save ticketing settings') }}
                        </button>
                    </footer>
                </div>
            </main>
        </div>
    </SettingLayout>
</template>

<script setup>
import SettingLayout from "./Layout.vue";
import { computed, ref } from 'vue';
import { useForm } from "@inertiajs/vue3";

const props = defineProps(['rows', 'filters', 'settings', 'modules', 'agents', 'oldSelectedAgents']);
const config = ref(props.settings.metadata);
const settings = ref(config.value ? JSON.parse(config.value) : null);

const toNumberArray = (arr) => {
    if (!arr) return [];
    const base = Array.isArray(arr) ? arr : Object.values(arr);
    return Array.from(new Set(base.map(v => Number(v)).filter(v => !isNaN(v) && v > 0)));
};

const form = useForm({
    active: settings.value?.tickets?.active ?? false,
    auto_assignment: settings.value?.tickets?.auto_assignment ?? false,
    auto_robinhood: settings.value?.tickets?.assigned_to_specific_agents ?? false,
    reassign_reopened_chats: settings.value?.tickets?.reassign_reopened_chats ?? false,
    allow_agents_to_view_all_chats: settings.value?.tickets?.allow_agents_to_view_all_chats ?? false,
    mask_agent_phone_numbers: settings.value?.tickets?.mask_agent_phone_numbers ?? false,
    note_email_notifications_enabled: settings.value?.tickets?.note_email_notifications?.enabled ?? false,
    note_email_notifications_recipient_scope: settings.value?.tickets?.note_email_notifications?.recipient_scope ?? 'assigned_agent',
    note_platform_notifications_enabled: settings.value?.tickets?.note_platform_notifications?.enabled ?? false,
    note_platform_notifications_recipient_scope: settings.value?.tickets?.note_platform_notifications?.recipient_scope ?? 'assigned_agent',
    selected_agents: (() => {
        const agentsArray = Array.isArray(props.agents) ? props.agents : Object.values(props.agents);
        const validAgentIds = agentsArray.map(a => Number(a.id));
        const initial = toNumberArray(settings.value?.tickets?.selected_agents ?? (props.oldSelectedAgents ?? []));
        return initial.filter(id => validAgentIds.includes(id));
    })()
});

const assignmentMode = computed({
    get: () => form.auto_robinhood ? 'round_robin' : (form.auto_assignment ? 'auto' : 'off'),
    set: (mode) => {
        form.auto_assignment = mode === 'auto';
        form.auto_robinhood = mode === 'round_robin';

        if (mode === 'off') {
            form.selected_agents = [];
        }
    },
});

const toggleAgent = (agentId) => {
    const id = Number(agentId);
    const index = form.selected_agents.indexOf(id);

    if (index > -1) {
        form.selected_agents.splice(index, 1);
    } else {
        form.selected_agents.push(id);
    }
};

const toggleState1 = () => { form.active = !form.active; };
const toggleState2 = () => { form.reassign_reopened_chats = !form.reassign_reopened_chats; };
const toggleState3 = () => { form.allow_agents_to_view_all_chats = !form.allow_agents_to_view_all_chats; };
const toggleState4 = () => { form.mask_agent_phone_numbers = !form.mask_agent_phone_numbers; };
const toggleState5 = () => { form.note_email_notifications_enabled = !form.note_email_notifications_enabled; };
const toggleState6 = () => { form.note_platform_notifications_enabled = !form.note_platform_notifications_enabled; };

const submitForm = () => form.post('/settings/tickets', {
    preserveScroll: true,
    onSuccess: () => form.defaults(),
});
</script>
