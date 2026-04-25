<template>
    <SettingLayout :modules="props.modules">
        <div class="md:h-[90vh] overflow-y-auto pb-20">
            <div class="flex justify-center items-center">
                <div class="md:w-[60em]">

                    <!-- Enable ticketing -->
                    <div class="bg-white border border-slate-200 rounded-lg pt-2 text-sm mb-4 px-4 mb-3">
                        <div class="w-full py-2 mb-4 mt-2">
                            <div class="flex w-full">
                                <div class="text-md">
                                    <h4 class="text-[16px]">{{ $t('Enable ticketing') }}</h4>
                                    <div class="mb-1 text-slate-500">
                                        {{ $t('Activate ticketing workflow in your conversations') }}
                                    </div>
                                </div>
                                <div class="ml-auto">
                                    <div class="w-12 h-6 flex items-center bg-gray-300 rounded-full p-1 cursor-pointer transition-colors duration-300"
                                        :class="{ 'bg-primary': form.active }" @click="toggleState1">
                                        <div class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out"
                                            :class="{ 'ltr:translate-x-6 rtl:translate-x-[-24px]': form.active }"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Auto assignment -->
                    <div v-if="form.active"
                        class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4 pb-4 px-4 mb-3">


                        <div class="w-full py-2 mb-2 mt-2">
                            <div class="flex w-full mb-4">
                                <div class="text-md">
                                    <h4 class="text-[16px]">{{ $t('Auto assignment') }}</h4>
                                    <span class="flex items-center mt-1 text-slate-500">
                                        {{ $t('Use auto-assignment rules to evenly distribute chats among agent automatically.') }}
                                    </span>
                                </div>
                            </div>
                            <div class="w-5/5">
                                <div class="grid grid-cols-3 gap-x-4">
                                    <div class="border rounded-xl p-4">
                                        <div class="flex space-s-2">
                                            <div>
                                                <div class="flex mt-[1px]">
                                                    <label @click="toggleAutoAssignment('off')" class="cursor-pointer">
                                                        <div class="w-4 h-4 border border-gray-400 rounded-md flex items-center justify-center"
                                                            :class="!form.auto_assignment && !form.auto_robinhood ? 'bg-[#000]' : ''">
                                                            <svg v-if="!form.auto_assignment && !form.auto_robinhood"
                                                                class="w-4 h-4" fill="none" stroke="currentColor"
                                                                viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                    stroke-width="2" d="M5 13l4 4L19 7" />
                                                            </svg>
                                                        </div>
                                                    </label>
                                                </div>
                                            </div>
                                            <div @click="toggleAutoAssignment('off')" class="cursor-pointer">
                                                <div>{{ $t('Off') }}</div>
                                                <div>{{ $t('Team members pick conversations manually from Unassigned folder.') }}</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="border rounded-xl p-4">
                                        <div class="flex space-s-2">
                                            <div>
                                                <div class="flex mt-[1px]">
                                                    <label @click="toggleAutoAssignment('auto')" class="cursor-pointer">
                                                        <div class="w-4 h-4 border border-gray-400 rounded-md flex items-center justify-center"
                                                            :class="form.auto_assignment ? 'bg-[#000]' : ''">
                                                            <svg v-if="form.auto_assignment" class="w-4 h-4" fill="none"
                                                                stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                    stroke-width="2" d="M5 13l4 4L19 7" />
                                                            </svg>
                                                        </div>
                                                    </label>
                                                </div>
                                            </div>
                                            <div @click="toggleAutoAssignment('auto')" class="cursor-pointer">
                                                <div>{{ $t('Auto') }}</div>
                                                <div>{{ $t('Distribute conversations among all your available team members.') }}</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="border rounded-xl p-4">
                                        <div class="flex space-s-2">
                                            <div>
                                                <div class="flex mt-[1px]">
                                                    <label @click="toggleAutoAssignment('auto_robinhood')"
                                                        class="cursor-pointer">
                                                        <div class="w-4 h-4 border border-gray-400 rounded-md flex items-center justify-center"
                                                            :class="form.auto_robinhood ? 'bg-[#000]' : ''">
                                                            <svg v-if="form.auto_robinhood" class="w-4 h-4" fill="none"
                                                                stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                    stroke-width="2" d="M5 13l4 4L19 7" />
                                                            </svg>
                                                        </div>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="cursor-pointer">
                                                <div>{{ $t('Auto Robinhood') }}</div>
                                                <div>{{ $t('Round-robin assignment distributes chats equally among available agents.') }}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>


                    <!-- Assigned to specific agents -->
                    <div v-if="form.active && (form.auto_assignment || form.auto_robinhood)"
                        class="bg-white border border-slate-200 rounded-lg pt-2 text-sm mb-4 px-4">
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
                            <div :dir="$page.props.locale === 'ar' ? 'rtl' : 'ltr'"
                                class="bg-white border border-slate-200 rounded-lg p-4">
                                <h4 class="text-lg font-semibold mb-3">{{ $t('Select Agents') }}</h4>
                                <ul class="space-y-2 max-h-64 overflow-y-auto">
                                    <li v-for="agent in props.agents" :key="agent.id"
                                        class="flex items-center p-2 border rounded-md bg-gray-50">
                                        <input type="checkbox" class="mx-2"
                                            :checked="form.selected_agents.includes(Number(agent.id))"
                                            @change="toggleAgent(agent.id)" :id="'agent_' + agent.id">
                                        <label :for="'agent_' + agent.id" class="cursor-pointer">
                                            {{ agent.first_name }} {{ agent.last_name }}
                                        </label>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="mb-4 flex justify-end">
                            <button @click="submitForm" :disabled="form.selected_agents.length === 0"
                                :class="form.selected_agents.length === 0 ? 'bg-gray-300 cursor-not-allowed' : 'bg-primary hover:bg-primary-dark'"
                                class="px-6 py-2 text-white rounded-lg transition-colors duration-200">
                                {{ $t('Save') }}
                            </button>
                        </div>
                    </div>

                    <!-- Reassign reopened chats -->
                    <div v-if="form.active"
                        class="bg-white border border-slate-200 rounded-lg pt-2 text-sm mb-4 px-4 mb-3">
                        <div class="w-full py-2 mb-4 mt-2">
                            <div class="flex w-full">
                                <div class="w-3/4 text-md">
                                    <h4 class="text-[16px]">{{ $t('Reassign chats that have been reopened') }}</h4>
                                    <div class="mb-1 text-slate-500">
                                        {{ $t('Enable this option to reassign chats when a contact re-opens closed conversation.') }}
                                    </div>
                                </div>
                                <div class="w-1/4">
                                    <div class="ml-auto w-12 h-6 flex items-center bg-gray-300 rounded-full p-1 cursor-pointer transition-colors duration-300"
                                        :class="{ 'bg-primary': form.reassign_reopened_chats }" @click="toggleState2">
                                        <div class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out"
                                            :class="{ 'ltr:translate-x-6 rtl:translate-x-[-24px]': form.reassign_reopened_chats }">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>


                    <!-- Allow agents to view all chats -->
                    <div v-if="form.active" class="bg-white border border-slate-200 rounded-lg pt-2 text-sm mb-4 px-4">
                        <div class="w-full py-2 mb-4 mt-2">
                            <div class="flex w-full items-start gap-4">
                                <div class="flex-1 text-md">
                                    <h4 class="text-[16px] leading-relaxed">
                                        {{ $t('Grant agents access to view all chats not assigned to them') }}
                                    </h4>
                                    <div class="mb-1 text-slate-500 mt-1">
                                        {{ $t('Disable this option, if you want live chat agents to have access only to new conversations.') }}
                                    </div>
                                </div>
                                <div class="flex-shrink-0">
                                    <div class="w-12 h-6 flex items-center bg-gray-300 rounded-full p-1 cursor-pointer transition-colors duration-300"
                                        :class="{ 'bg-primary': form.allow_agents_to_view_all_chats }"
                                        @click="toggleState3">
                                        <div class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out"
                                            :class="{ 'ltr:translate-x-6 rtl:translate-x-[-24px]': form.allow_agents_to_view_all_chats }">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Mask phone numbers for agents -->
                    <div v-if="form.active" class="bg-white border border-slate-200 rounded-lg pt-2 text-sm mb-4 px-4">
                        <div class="w-full py-2 mb-4 mt-2">
                            <div class="flex w-full items-start gap-4">
                                <div class="flex-1 text-md">
                                    <h4 class="text-[16px] leading-relaxed">
                                        {{ $t('Mask phone numbers for agents') }}
                                    </h4>
                                    <div class="mb-1 text-slate-500 mt-1">
                                        {{ $t('When enabled, agents can only see the first 3 and last 2 digits of phone numbers.') }}
                                    </div>
                                </div>
                                <div class="flex-shrink-0">
                                    <div class="w-12 h-6 flex items-center bg-gray-300 rounded-full p-1 cursor-pointer transition-colors duration-300"
                                        :class="{ 'bg-primary': form.mask_agent_phone_numbers }"
                                        @click="toggleState4">
                                        <div class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out"
                                            :class="{ 'ltr:translate-x-6 rtl:translate-x-[-24px]': form.mask_agent_phone_numbers }">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </SettingLayout>
</template>

<script setup>
import SettingLayout from "./Layout.vue";
import { ref, watch } from 'vue';
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
    selected_agents: (() => {
        const agentsArray = Array.isArray(props.agents) ? props.agents : Object.values(props.agents);
        const validAgentIds = agentsArray.map(a => Number(a.id));
        const initial = toNumberArray(settings.value?.tickets?.selected_agents ?? (props.oldSelectedAgents ?? []));
        return initial.filter(id => validAgentIds.includes(id));
    })()
});

watch(() => form.selected_agents, (curr) => {
    console.log('Ticket.vue - form.selected_agents changed to:', curr);
}, { deep: true });

const toggleAgent = (agentId) => {
    const id = Number(agentId);
    const index = form.selected_agents.indexOf(id);

    if (index > -1) {
        form.selected_agents.splice(index, 1);
    } else {
        form.selected_agents.push(id);
    }
    console.log('Agent toggled. ID:', id, 'New selection:', form.selected_agents);
};

const toggleState1 = () => { form.active = !form.active; submitForm(); }
const toggleAutoAssignment = (el) => {
    // Reset both options first
    form.auto_assignment = false;
    form.auto_robinhood = false;

    if (el === 'auto') {
        form.auto_assignment = true;
    } else if (el === 'auto_robinhood') {
        form.auto_robinhood = true;
    } else if (el === 'off') {
        form.selected_agents = [];
        submitForm();
    }

}
const toggleState2 = () => {
    form.reassign_reopened_chats = !form.reassign_reopened_chats;
    submitForm();
}
const toggleState3 = () => { form.allow_agents_to_view_all_chats = !form.allow_agents_to_view_all_chats; submitForm(); }
const toggleState4 = () => { form.mask_agent_phone_numbers = !form.mask_agent_phone_numbers; submitForm(); }

const submitForm = async () => {
    console.log('SUBMITTING POST TO SERVER:', form.data());
    form.post('/settings/tickets', { preserveScroll: true });
}
</script>
