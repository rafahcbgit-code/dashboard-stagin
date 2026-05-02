<script setup>
    import axios from 'axios';
    import { computed, ref, watchEffect } from 'vue';
    import { router, useForm } from '@inertiajs/vue3';
    import FormModal from '@/Components/FormModal.vue';
    import { trans } from 'laravel-vue-i18n';
    import { useI18n } from 'vue-i18n';
    
    const props = defineProps(['group']);
    const group = ref(props.group);
    const { t } = useI18n();
    const audienceHelp = computed(() => ({
        name: t('The audience name helps you understand what type of customers are inside this group when preparing a WhatsApp campaign.'),
        edit: t('Edit the audience name if its purpose changes or you want a clearer name for the team.'),
        delete: t('Delete the audience only if you no longer need this classification. Check its campaign impact before deleting it.'),
        totalContacts: t('This number shows the audience size and how many customers can be targeted when using this group.'),
    }));

    watchEffect(() => {
        group.value = props.group;
    });

    const isOpenFormModal = ref(false);
    const form = ref({
        name: group.value.name,
    });

    const formInputs = [
        {
            inputType: 'FormInput',
            name: 'name',
            label: trans('name'),
            type: 'text',
            className: 'sm:col-span-6',
        },
    ];

    const form2 = useForm({'test': null});

    const deleteRow = async() => {
        router.visit('/contact-groups', {
            method: 'delete',
            data: { 'uuids': [ group.value.uuid ]},
            preserveState: true
        })
    }

    const openModal = () => {
        isOpenFormModal.value = true;
    }
</script>
<template>
    <div>
        <div class="pt-20">
            <div class="flex justify-center space-x-8 items-center pb-6 pr-20 border-gray-300 border-b">
                <div>
                    <div class="rounded-full p-1 bg-white">
                        <div class="rounded-full text-3xl flex justify-center items-center h-24 w-24 capitalize">{{ group.name.substring(0, 1) }}</div>
                    </div>
                </div>
                <div>
                    <h1 class="text-3xl" :title="audienceHelp.name" data-help-icon>{{ group.name }}</h1>
                    <div class="flex space-x-3 mt-4">
                        <button class="bg-gray-200 py-2 px-4 h-9 rounded-md flex items-center" :title="audienceHelp.edit" data-help-icon @click="openModal('edit')">
                            <span class="text-[14px]">{{ $t('Edit') }}</span>
                        </button>
                        <button @click="deleteRow()" class="bg-gray-200 py-2 px-4 h-9 rounded-md flex items-center" :title="audienceHelp.delete" data-help-icon>
                            <span class="text-[14px]">{{ $t('Delete') }}</span>
                        </button>
                    </div>
                </div>
            </div>
            <div class="pr-20 border-gray-300 border-b py-4">
                <div class="grid grid-cols-2 space-x-8 text-[14px]">
                    <div class="text-right text-slate-500 pb-2">
                        <span :title="audienceHelp.name" data-help-icon>{{ $t('Group name') }}</span>
                    </div>
                    <div>
                        <span :title="audienceHelp.name" data-help-icon>{{ group.name }}</span>
                    </div>
                    <div class="text-right text-slate-500 pb-2">
                        <span :title="audienceHelp.totalContacts" data-help-icon>{{ $t('Total contacts') }}</span>
                    </div>
                    <div>
                        <span class="p-1 bg-gray-50 text-xs rounded-lg text-gray-600" :title="audienceHelp.totalContacts" data-help-icon>{{ group.contact_count }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <FormModal 
        v-model="isOpenFormModal" 
        :label="$t('Edit group')" 
        :url="'/contact-groups/' + group?.uuid" 
        :form="form"
        :formInputs="formInputs"
    />
</template>
<style>
[data-help-icon] {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

[data-help-icon]::after {
    content: '?';
    display: inline-flex;
    width: 0.95rem;
    height: 0.95rem;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    border: 1.5px solid #94a3b8;
    border-radius: 9999px;
    color: #64748b;
    font-size: 0.62rem;
    font-weight: 800;
    line-height: 1;
}
</style>
