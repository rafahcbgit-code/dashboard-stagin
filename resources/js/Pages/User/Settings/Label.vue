<template>
    <SettingLayout :modules="props.modules">
        <div class="md:h-[90vh] flex justify-center items-start">
            <div class="md:w-[60em]">
                <div class="bg-white border border-slate-200 rounded-lg pt-4 px-6 pb-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-lg">{{ $t('Labels') }}</h2>
                        <button @click="openModal()"
                            class="bg-primary text-white px-4 py-2 rounded-md hover:bg-indigo-600">
                            {{ $t('Add Label') }}
                        </button>
                    </div>

                    <div class="grid md:grid-cols-2 gap-3">
                        <div v-for="label in props.labels" :key="label.id"
                            class="flex justify-between items-center border p-3 rounded-md hover:bg-slate-50">
                            <!-- Label Tag with Icon -->
                            <div class="flex items-center space-x-2">
                                <!-- Tag Icon -->
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                                <path fill="none" :stroke="label.color" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M20.59 13.41 12 22 4 14V4h10l8.59 8.59zM7 7h.01" />
                                </svg>
                                <div>
                                    <span>{{ label.title }}</span>
                                    <div class="text-xs text-gray-500">
                                        {{ label.contact_count || 0 }} {{ $t('contacts') }}
                                    </div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center space-x-3">
                                <!-- Create Group Icon -->
                                <button @click="openCreateGroupModal(label)" 
                                    class="text-green-500 hover:text-green-700"
                                    :title="$t('Create Group from Label')">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none"
                                        viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 4v16m8-8H4" />
                                    </svg>
                                </button>
                                <!-- Edit Icon (Pencil) -->
                                <button @click="editLabel(label)" class="text-black-500 hover:text-blue-700">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none"
                                        viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                    </svg>
                                </button>
                                <!-- Delete Icon (Trash) -->
                                <button @click="destroy(label.id)" class="text-black-500 hover:text-red-700">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none"
                                        viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5-4h4m-4 0h4m-9 4h12" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add/Edit Label Modal -->
        <Modal :label="modalTitle" :isOpen="isOpen">
            <form @submit.prevent="submit" class="space-y-4">
                <FormInput v-model="form.title" :name="$t('Title')" :error="form.errors.title" />
                <div>
                    <label class="block text-sm mb-1">{{ $t('Color') }}</label>
                    <input type="color" v-model="form.color" class="w-16 h-8 border rounded" />
                </div>
                <div class="flex justify-end space-x-2 mt-4">
                    <button type="button" @click="isOpen = false" class="px-4 py-2 border rounded">
                        {{ $t('Cancel') }}
                    </button>
                    <button type="submit" class="bg-primary text-white px-4 py-2 rounded hover:bg-indigo-600"
                        :disabled="form.processing">
                        {{ $t('Save') }}
                    </button>
                </div>
            </form>
        </Modal>

        <!-- Create Group Modal -->
        <Modal :label="`${$t('Create Group from Label')}: ${selectedLabel?.title}`" :isOpen="isCreateGroupOpen">
            <form @submit.prevent="submitCreateGroup" class="space-y-4">
                <div class="mb-4 p-3 bg-gray-50 rounded-md">
                    <div class="flex items-center space-x-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
                            <path fill="none" :stroke="selectedLabel?.color" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M20.59 13.41 12 22 4 14V4h10l8.59 8.59zM7 7h.01" />
                        </svg>
                        <span class="font-medium">{{ selectedLabel?.title }}</span>
                        <span class="text-sm text-gray-500">({{ selectedLabel?.contact_count || 0 }} {{ $t('contacts') }})</span>
                    </div>
                </div>
                <FormInput v-model="createGroupForm.name" :name="$t('Group Name')" :error="createGroupForm.errors.name" />
                <div class="flex justify-end space-x-2 mt-4">
                    <button type="button" @click="isCreateGroupOpen = false" class="px-4 py-2 border rounded">
                        {{ $t('Cancel') }}
                    </button>
                    <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700"
                        :disabled="createGroupForm.processing">
                        {{ $t('Create Group') }}
                    </button>
                </div>
            </form>
        </Modal>
    </SettingLayout>
</template>

<script setup>
import SettingLayout from "./Layout.vue";
import Modal from '@/Components/Modal.vue';
import FormInput from '@/Components/FormInput.vue';
import { useForm, router } from "@inertiajs/vue3";
import { ref } from "vue";
import { useI18n } from 'vue-i18n';

const props = defineProps(['labels', 'settings', 'modules']);

const isOpen = ref(false);
const isCreateGroupOpen = ref(false);
const modalTitle = ref("Add Label");
const selectedLabel = ref(null);

const form = useForm({
    id: null,
    title: "",
    color: "#000000"
});

const createGroupForm = useForm({
    name: "",
    label_id: ""
});

const openModal = () => {
    form.reset();
    form.id = null;
    modalTitle.value = "Add Label";
    isOpen.value = true;
};
const { t } = useI18n() 
const editLabel = (label) => {
    form.id = label.id;
    form.title = label.title;
    form.color = label.color;
    modalTitle.value = t("Edit Label");
    isOpen.value = true;
};

const openCreateGroupModal = (label) => {
    createGroupForm.reset();
    createGroupForm.label_id = label.id;
    selectedLabel.value = label;
    isCreateGroupOpen.value = true;
};

const submit = () => {
    if (form.id) {
        form.put(`/settings/labels/${form.id}`, { onSuccess: () => isOpen.value = false });
    } else {
        form.post("/settings/labels", { onSuccess: () => isOpen.value = false });
    }
};

const submitCreateGroup = () => {
    createGroupForm.post("/settings/labels/create-group", { 
        onSuccess: () => {
            isCreateGroupOpen.value = false;
            createGroupForm.reset();
        }
    });
};

const destroy = (id) => {
    if (confirm("Are you sure?")) {
        router.delete(`/settings/labels/${id}`);
    }
};
</script>