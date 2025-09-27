<template>
    <SettingLayout :modules="props.modules">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center">
                <div class="md:w-[60em]">

                    <!-- Enable ticketing -->
                    <div class="bg-white border border-slate-200 rounded-lg pt-2 text-sm mb-4 px-4 mb-20">
                        <div class="w-full py-2 mb-4 mt-2">
                            <div class="flex w-full">
                                <div class="text-md">
                                    <h4 class="text-[16px]">{{ $t('Enable ticketing') }}</h4>
                                    <div class="mb-1 text-slate-500">
                                        {{ $t('Activate ticketing workflow in your conversations') }}
                                    </div>
                                </div>
                                <div class="ml-auto">
                                    <div class="w-12 h-6 flex items-center bg-gray-300 rounded-full p-1" 
                                         :class="{ 'bg-primary': form.active}" 
                                         @click="toggleState1">
                                        <div class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out" 
                                             :class="{ 'translate-x-6': form.active}"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Auto assignment -->
                    <div v-if="form.active" class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4 pb-4 px-4 mb-20">
                        <div class="w-full py-2 mb-2 mt-2">
                            <div class="flex w-full mb-4">
                                <div class="text-md">
                                    <h4 class="text-[16px]">{{ $t('Auto assignment') }}</h4>
                                    <span class="flex items-center mt-1 text-slate-500">
                                        {{ $t('Use auto-assignment rules to evenly distribute chats among agents automatically.') }}
                                    </span> 
                                </div>
                            </div>
                            <div class="w-5/5">
                                <div class="grid grid-cols-2 gap-x-4">
                                    <div class="border rounded-xl p-4">
                                        <div class="flex space-s-2">
                                            <div>
                                                <div class="flex mt-[1px]">
                                                    <label @click="toggleAutoAssignment(false)" class="cursor-pointer">
                                                        <div class="w-4 h-4 border border-gray-400 rounded-md flex items-center justify-center" 
                                                             :class="form.auto_assignment === false ? 'bg-[#000]' : ''">
                                                            <svg v-if="form.auto_assignment === false" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                                            </svg>
                                                        </div>
                                                    </label>
                                                </div>
                                            </div>
                                            <div @click="toggleAutoAssignment(false)" class="cursor-pointer">
                                                <div>{{ $t('Off') }}</div>
                                                <div>{{ $t('Team members pick conversations manually from Unassigned folder.') }}</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="border rounded-xl p-4">
                                        <div class="flex space-s-2">
                                            <div>
                                                <div class="flex mt-[1px]">
                                                    <label @click="toggleAutoAssignment(true)" class="cursor-pointer">
                                                        <div class="w-4 h-4 border border-gray-400 rounded-md flex items-center justify-center" 
                                                             :class="form.auto_assignment === true ? 'bg-[#000]' : ''">
                                                            <svg v-if="form.auto_assignment === true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                                            </svg>
                                                        </div>
                                                    </label>
                                                </div>
                                            </div>
                                            <div @click="toggleAutoAssignment(true)" class="cursor-pointer">
                                                <div>{{ $t('Auto') }}</div>
                                                <div>{{ $t('Distribute conversations among all your available team members.') }}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Reassign reopened chats -->
                    <div v-if="form.active" class="bg-white border border-slate-200 rounded-lg pt-2 text-sm mb-4 px-4 mb-20">
                        <div class="w-full py-2 mb-4 mt-2">
                            <div class="flex w-full">
                                <div class="w-3/4 text-md">
                                    <h4 class="text-[16px]">{{ $t('Reassign chats that have been reopened') }}</h4>
                                    <div class="mb-1 text-slate-500">
                                        {{ $t('Enable this option to reassign chats when a contact re-opens a closed conversation.') }}
                                    </div>
                                </div>
                                <div class="w-1/4">
                                    <div class="ml-auto w-12 h-6 flex items-center bg-gray-300 rounded-full p-1"
                                         :class="{ 'bg-primary': form.reassign_reopened_chats}" 
                                         @click="toggleState2">
                                        <div class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out"
                                             :class="{ 'translate-x-6': form.reassign_reopened_chats}"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>


                     <!-- Assigned to specific agents -->
<div v-if="form.active" class="bg-white border border-slate-200 rounded-lg pt-2 text-sm mb-4 px-4">
    <div class="w-full py-2 mb-4 mt-2">
        <div class="flex w-full">
            <div class="w-3/4 text-md">
                <h4 class="text-[16px]">{{ $t('Assigned to specific agents') }}</h4>
                <div class="mb-1 text-slate-500">
                    {{ $t('Enable this option to allow assigning conversations only to specific agents.') }}
                </div>
            </div>
            <div class="w-1/4">
                <div class="ml-auto w-12 h-6 flex items-center bg-gray-300 rounded-full p-1"
                     :class="{ 'bg-primary': form.assigned_to_specific_agents}" 
                     @click="toggleState4">
                    <div class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out"
                         :class="{ 'translate-x-6': form.assigned_to_specific_agents}"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- القائمة تظهر عند التفعيل -->
    <div v-if="form.assigned_to_specific_agents" class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">
            {{ $t('Select agents') }}
        </label>
        <select v-model="form.selected_agents" multiple
            class="w-full border rounded-md px-3 py-2 text-sm focus:ring focus:ring-primary focus:outline-none">
            <option v-for="agent in props.agents" :key="agent.id" :value="agent.id">
                {{ agent.first_name }}
            </option>
        </select>
         <!-- زر الحفظ -->
    <button @click="submitAgents"
            class="mt-3 bg-primary text-white px-4 py-2 rounded hover:bg-primary-dark">
        {{ $t('Save Selected Agents') }}
    </button>
    </div>
</div>



                    <!-- Allow agents to view all chats -->
                    <div v-if="form.active" class="bg-white border border-slate-200 rounded-lg pt-2 text-sm mb-4 px-4 mb-20">
                        <div class="w-full py-2 mb-4 mt-2">
                            <div class="flex w-full">
                                <div class="w-3/4 text-md">
                                    <h4 class="text-[16px]">{{ $t('Grant agents access to view all chats not assigned to them') }}</h4>
                                    <div class="mb-1 text-slate-500">
                                        {{ $t('Disable this option, if you want live chat agents to have access only to new conversations.') }}
                                    </div>
                                </div>
                                <div class="w-1/4">
                                    <div class="ml-auto w-12 h-6 flex items-center bg-gray-300 rounded-full p-1"
                                         :class="{ 'bg-primary': form.allow_agents_to_view_all_chats}" 
                                         @click="toggleState3">
                                        <div class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out"
                                             :class="{ 'translate-x-6': form.allow_agents_to_view_all_chats}"></div>
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
import { ref } from 'vue';
import { useForm } from "@inertiajs/vue3";

const props = defineProps(['rows', 'filters', 'settings', 'modules', 'agents']);
const config = ref(props.settings.metadata);
const settings = ref(config.value ? JSON.parse(config.value) : null);

const form = useForm({
    active: settings.value?.tickets?.active ?? false,
    auto_assignment: settings.value?.tickets?.auto_assignment ?? false,
    reassign_reopened_chats: settings.value?.tickets?.reassign_reopened_chats ?? false,
    allow_agents_to_view_all_chats: settings.value?.tickets?.allow_agents_to_view_all_chats ?? false,
    assigned_to_specific_agents: settings.value?.tickets?.assigned_to_specific_agents ?? false,
    selected_agents: settings.value?.tickets?.selected_agents ?? []
});

const toggleState1 = () => { form.active = !form.active; submitForm(); }
const toggleState2 = () => { form.reassign_reopened_chats = !form.reassign_reopened_chats; submitForm(); }
const toggleState3 = () => { form.allow_agents_to_view_all_chats = !form.allow_agents_to_view_all_chats; submitForm(); }
const toggleState4 = () => 
{ 
    form.assigned_to_specific_agents = !form.assigned_to_specific_agents; 
     }
const toggleAutoAssignment = (el) => { form.auto_assignment = el; submitForm(); }

const submitAgents = () => {
    form.post('/settings/tickets', {
        preserveScroll: true,
        only: ['assigned_to_specific_agents', 'selected_agents'] // فقط البيانات المطلوبة
    });
}

const submitForm = async () => {
    form.post('/settings/tickets', { preserveScroll: true })
}
</script>
