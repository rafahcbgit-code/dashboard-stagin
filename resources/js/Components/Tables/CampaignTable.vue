<script setup>
    import { Link } from "@inertiajs/vue3";
    import { ref } from 'vue';
    import debounce from 'lodash/debounce';
    import { router, useForm } from '@inertiajs/vue3';
    import AlertModal from '@/Components/AlertModal.vue';
    import { useAlertModal } from '@/Composables/useAlertModal';
    import Table from '@/Components/Table.vue';
    import TableHeader from '@/Components/TableHeader.vue';
    import TableHeaderRow from '@/Components/TableHeaderRow.vue';
    import TableHeaderRowItem from '@/Components/TableHeaderRowItem.vue';
    import TableBody from '@/Components/TableBody.vue';
    import TableBodyRow from '@/Components/TableBodyRow.vue';
    import TableBodyRowItem from '@/Components/TableBodyRowItem.vue';
    import Dropdown from '@/Components/Dropdown.vue';
    import DropdownItemGroup from '@/Components/DropdownItemGroup.vue';
    import DropdownItem from '@/Components/DropdownItem.vue';

    const props = defineProps({
        rows: {
            type: Object,
            required: true,
        },
        filters: {
            type: Object
        },
        role: String
    });

    const { isOpenAlert, openAlert, confirmAlert } = useAlertModal();

    const form = useForm({'test': null});

    const showScheduleEditor = ref(false);
    const isUpdatingSchedule = ref(false);
    const scheduleError = ref(null);
    const scheduledTime = ref('');
    const selectedCampaign = ref(null);

    const deleteAction = (key) => {
        form.delete('/campaigns/' + key);
    }
    
    const params = ref({
        search: props.filters.search,
        start_date: props.filters.start_date,
        end_date: props.filters.end_date,
    });

    const isLastRow = (index) => {
      return index === props.rows.data.length - 1;
    }

    const isSearching = ref(false);
    const emit = defineEmits(['delete']);

    const formatPercentageRate = (numeratorCount, contactCount, contactGroupCount) => {
        if(contactCount > 0){
            return (numeratorCount/contactCount * 100).toFixed(2) + '%';
        } else {
            return (numeratorCount/contactGroupCount * 100).toFixed(2) + '%';
        }
    };

    const formatStats = (numeratorCount, contactCount, contactGroupCount) => {
        if(contactCount > 0){
            return numeratorCount + '/' + contactCount;
        } else {
            return numeratorCount + '/' + contactGroupCount;
        }
    }

    const clearSearch = () => {
        params.value.search = null;
        runSearch();
    }

    const search = debounce(() => {
        isSearching.value = true;
        runSearch();
    }, 1000);

    const runSearch = () => {
        router.visit('/campaigns', {
            method: 'get',
            data: params.value,
        })
    }

    const minimumScheduleTime = () => {
        const date = new Date();
        date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
        return date.toISOString().slice(0, 16);
    };

    const scheduleTimeForInput = (value) => String(value || '')
        .replace(' ', 'T')
        .slice(0, 16);

    const openScheduleEditor = (campaign) => {
        selectedCampaign.value = campaign;
        scheduledTime.value = scheduleTimeForInput(campaign.scheduled_at);
        scheduleError.value = null;
        showScheduleEditor.value = true;
    };

    const closeScheduleEditor = () => {
        if (!isUpdatingSchedule.value) {
            showScheduleEditor.value = false;
            scheduleError.value = null;
            selectedCampaign.value = null;
        }
    };

    const updateSchedule = async () => {
        if (!selectedCampaign.value) return;

        isUpdatingSchedule.value = true;
        scheduleError.value = null;

        try {
            const response = await fetch(`/campaigns/${selectedCampaign.value.uuid}/schedule`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ time: scheduledTime.value }),
            });
            const data = await response.json();

            if (!response.ok) {
                scheduleError.value = data.errors?.time?.[0] || data.error || 'Unable to update the scheduled time.';
                return;
            }

            showScheduleEditor.value = false;
            selectedCampaign.value = null;
            router.reload({ only: ['rows'] });
        } catch (error) {
            scheduleError.value = 'A network error occurred while updating the scheduled time.';
        } finally {
            isUpdatingSchedule.value = false;
        }
    };
</script>
<template>
    <div class="bg-[#f0f2f5] md:bg-white flex items-center md:border md:border-primary md:border-none md:shadow-sm h-12 md:h-10 w-full md:w-80 rounded-xl mb-6 text-sm mt-6">
        <span class="pl-3">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 15l6 6m-11-4a7 7 0 1 1 0-14a7 7 0 0 1 0 14Z"/></svg>
        </span>
        <input @input="search" v-model="params.search" type="text" class="outline-none px-4 w-full bg-inherit" :placeholder="$t('Search campaigns')">
        <button v-if="isSearching === false && params.search" @click="clearSearch" type="button" class="pr-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10s10-4.5 10-10S17.5 2 12 2zm3.7 12.3c.4.4.4 1 0 1.4c-.4.4-1 .4-1.4 0L12 13.4l-2.3 2.3c-.4.4-1 .4-1.4 0c-.4-.4-.4-1 0-1.4l2.3-2.3l-2.3-2.3c-.4-.4-.4-1 0-1.4c.4-.4 1-.4 1.4 0l2.3 2.3l2.3-2.3c.4-.4 1-.4 1.4 0c.4.4.4 1 0 1.4L13.4 12l2.3 2.3z"/></svg>
        </button>
        <span v-if="isSearching" class="pr-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="0 12 12;90 12 12;180 12 12;270 12 12"/><animate attributeName="opacity" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" begin="0.2s" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="30 12 12;120 12 12;210 12 12;300 12 12"/><animate attributeName="opacity" begin="0.2s" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" begin="0.4s" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="60 12 12;150 12 12;240 12 12;330 12 12"/><animate attributeName="opacity" begin="0.4s" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle></svg>
        </span>
    </div>
    <Table :rows="rows">
        <TableHeader>
            <TableHeaderRow>
                <TableHeaderRowItem :position="'first'">{{ $t('Campaign name') }}</TableHeaderRowItem>
                <TableHeaderRowItem class="hidden md:table-cell">{{ $t('Template') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Delivery rate') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Read rate') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Status') }}</TableHeaderRowItem>
                <TableHeaderRowItem :position="'last'"></TableHeaderRowItem>
            </TableHeaderRow>
        </TableHeader>
        <TableBody>
            <TableBodyRow v-for="(item, index) in rows.data" :key="index" :class="!isLastRow(index) ? 'border-b' : ''">
                <TableBodyRowItem :position="'first'">
                    <Link :href="'/campaigns/' + item.uuid" class="block w-full">
                        <span class="block">{{ item.name }}</span>
                        <span v-if="item.scheduled_at" class="mt-1 block text-xs text-slate-500">
                            {{ $t('Scheduled time') }}: {{ item.scheduled_at }}
                        </span>
                    </Link>
                </TableBodyRowItem>
                <TableBodyRowItem class="hidden md:table-cell">
                    <Link :href="'/campaigns/' + item.uuid" class="block w-full">
                        {{ item.template.name }}
                    </Link>
                </TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">
                    <Link :href="'/campaigns/' + item.uuid" class="block w-full">
                        <div v-if="item.status == 'scheduled'">
                            {{ $t('N/A') }}
                        </div>
                        <div v-else>
                            <span class="bg-slate-200 px-1 py-1 rounded-lg mr-1 hidden md:inline-block">
                                {{ formatPercentageRate(item.delivery_count, item.contacts_count, item.contact_group_count) }}
                            </span>
                            {{ formatStats(item.delivery_count, item.contacts_count, item.contact_group_count) }}
                        </div>
                    </Link>
                </TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">
                    <Link :href="'/campaigns/' + item.uuid" class="block w-full">
                        <div v-if="item.status == 'scheduled'">
                            {{ $t('N/A') }}
                        </div>
                        <div v-else>
                            <span class="bg-slate-200 px-1 py-1 rounded-lg mr-1 hidden md:inline-block">
                                {{ formatPercentageRate(item.read_count, item.contacts_count, item.contact_group_count) }}
                            </span>
                            {{ formatStats(item.read_count, item.contacts_count, item.contact_group_count) }}
                        </div>
                    </Link>
                </TableBodyRowItem>
                <TableBodyRowItem>
                    <Link :href="'/campaigns/' + item.uuid" class="flex items-center w-full capitalize">
                        {{ item.status }}
                    </Link>
                </TableBodyRowItem>
                <TableBodyRowItem :position="'last'">
                    <Dropdown :align="'right'" class="mt-2">
                        <button class="inline-flex w-full justify-center rounded-md text-sm font-medium text-black hover:bg-opacity-30 focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-opacity-75">
                            <span class="hover:bg-[#F6F7F9] hover:rounded-full w-[fit-content] p-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
                                    <path fill="currentColor" d="M12 16a2 2 0 0 1 2 2a2 2 0 0 1-2 2a2 2 0 0 1-2-2a2 2 0 0 1 2-2m0-6a2 2 0 0 1 2 2a2 2 0 0 1-2 2a2 2 0 0 1-2-2a2 2 0 0 1 2-2m0-6a2 2 0 0 1 2 2a2 2 0 0 1-2 2a2 2 0 0 1-2-2a2 2 0 0 1 2-2Z"/>
                                </svg>
                            </span>
                        </button>
                        <template #items>
                            <DropdownItemGroup>
                                <DropdownItem :href="'/campaigns/' + item.uuid">{{ $t('View') }}</DropdownItem>
                                <DropdownItem v-if="item.status === 'scheduled'" as="button" @click="openScheduleEditor(item)">
                                    {{ $t('Edit scheduled time') }}
                                </DropdownItem>
                                <DropdownItem v-if="props.role !== 'agent'" as="button" @click="openAlert(item.uuid)">{{ $t('Delete') }}</DropdownItem>
                            </DropdownItemGroup>
                        </template>
                    </Dropdown>
                </TableBodyRowItem>
            </TableBodyRow>
        </TableBody>
    </Table>

    <div v-if="rows.data.length == 0" class="bg-white rounded-xl">
        <div class="p-4 py-8">
            <div class="flex justify-center mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 256 256"><path fill="currentColor" d="M216 80h-32V48a16 16 0 0 0-16-16H40a16 16 0 0 0-16 16v128a8 8 0 0 0 13 6.22L72 154v30a16 16 0 0 0 16 16h93.59L219 230.22a8 8 0 0 0 5 1.78a8 8 0 0 0 8-8V96a16 16 0 0 0-16-16M66.55 137.78L40 159.25V48h128v88H71.58a8 8 0 0 0-5.03 1.78M216 207.25l-26.55-21.47a8 8 0 0 0-5-1.78H88v-32h80a16 16 0 0 0 16-16V96h32Z"></path></svg>
            </div>
            <h3 class="text-center text-lg font-medium mb-4">{{ $t('You don\'t have any campaigns') }}</h3>
           <div v-if="props.role !== 'agent'" class="flex justify-center">
                <Link href="/campaigns/create" class="rounded-md px-3 py-2 text-sm hover:shadow-md text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 bg-primary" :disabled="isLoading">
                    <span v-if="!isLoading">{{ $t('Create campaign') }}</span>
                </Link>
            </div>
        </div>
    </div>

    <!-- Alert Modal Component-->
    <AlertModal 
        v-model="isOpenAlert" 
        @confirm="() => confirmAlert(deleteAction)"
        :label = "$t('Delete row')" 
        :description = "$t('Are you sure you want to delete this campaign? This action will only delete the campaign; sent messages will not be deleted from the chat history.')"
    />

    <div v-if="showScheduleEditor" class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="schedule-editor-title">
        <div class="flex min-h-screen items-end justify-center px-4 pb-20 pt-4 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="closeScheduleEditor"></div>
            <span class="hidden sm:inline-block sm:h-screen sm:align-middle">&#8203;</span>
            <div class="inline-block w-full max-w-lg transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:align-middle sm:p-6">
                <h3 id="schedule-editor-title" class="text-lg font-medium leading-6 text-gray-900">{{ $t('Edit scheduled time') }}</h3>
                <p class="mt-2 text-sm text-gray-500">{{ $t('Choose a new future date and time for this campaign.') }}</p>
                <label for="scheduled-time" class="mt-4 block text-sm font-medium text-gray-700">{{ $t('Scheduled time') }}</label>
                <input
                    id="scheduled-time"
                    v-model="scheduledTime"
                    type="datetime-local"
                    :min="minimumScheduleTime()"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                />
                <p v-if="scheduleError" class="mt-2 text-sm text-red-600">{{ scheduleError }}</p>
                <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" @click="closeScheduleEditor" :disabled="isUpdatingSchedule" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                        {{ $t('Cancel') }}
                    </button>
                    <button type="button" @click="updateSchedule" :disabled="isUpdatingSchedule || !scheduledTime" class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50">
                        {{ isUpdatingSchedule ? $t('Saving...') : $t('Save') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
  
