<script setup>
    import { ref } from 'vue';
    import debounce from 'lodash/debounce';
    import { router } from '@inertiajs/vue3';
    import { useForm } from "@inertiajs/vue3";
    import AlertModal from '@/Components/AlertModal.vue';
    import { useAlertModal } from '@/Composables/useAlertModal';
    import 'vue3-toastify/dist/index.css';
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
        }
    });

    const { isOpenAlert, openAlert, confirmAlert } = useAlertModal();

    const form = useForm({'test': null});

    const deleteAction = (id) => {
    form.delete('/admin/otp-config/' + id);
};



    const isLastRow = (index) => {
      return index === props.rows.data.length - 1;
    }
    
    const params = ref({
        search: props.filters.search,
    });

    const isSearching = ref(false);
    const emit = defineEmits(['delete']);

    function deleteItem(id) {
        emit('delete', id);
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
        router.visit('/admin/otp-config', {
            method: 'get',
            data: params.value,
        })
    }
</script>
<template>
   <div class="mt-6"></div>
    <Table :rows="rows">
        <TableHeader>
            <TableHeaderRow>
                 <TableHeaderRowItem :position="'first'">{{ $t('Id') }}</TableHeaderRowItem>
                <TableHeaderRowItem :position="'first'">{{ $t('Uuid') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Template Name') }}</TableHeaderRowItem>
                <TableHeaderRowItem class="whitespace-nowrap w-[100px]">{{ $t('Contact Phone') }}</TableHeaderRowItem>
                <TableHeaderRowItem class="whitespace-nowrap w-[100px]">{{ $t('Calendly Url') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Last updated') }}</TableHeaderRowItem>
                <TableHeaderRowItem :position="'last'"></TableHeaderRowItem>
            </TableHeaderRow>
        </TableHeader>
        <TableBody>
            <TableBodyRow v-for="(item, index) in rows.data" :key="index" :class="!isLastRow(index) ? 'border-b' : ''">
                <TableBodyRowItem :position="'first'" class="capitalize">{{ item.id }}</TableBodyRowItem>
                <TableBodyRowItem :position="'first'" class="capitalize">{{ item.uuid }}</TableBodyRowItem>
                <TableBodyRowItem :position="'first'" class="capitalize">{{ item.template_name }}</TableBodyRowItem>
                <TableBodyRowItem :position="'first'" class="max-w-xs truncate">{{ item.contact_phone }}</TableBodyRowItem>
                <TableBodyRowItem :position="'first'" >{{ item.calendly_url }}</TableBodyRowItem>
                <TableBodyRowItem :position="'first'" class="capitalize">{{ item.created_at }}</TableBodyRowItem>

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
                                <!-- <DropdownItem :href="'/admin/otp-config/' + item.uuid">{{ $t('View/edit') }}</DropdownItem> -->
                                <DropdownItem as="button" @click="openAlert(item.id)">{{ $t('Delete') }}</DropdownItem>
                            </DropdownItemGroup>
                        </template>
                    </Dropdown>
                </TableBodyRowItem>
            </TableBodyRow>
        </TableBody>
    </Table>

    <!-- Alert Modal Component-->
    <AlertModal 
        v-model="isOpenAlert" 
        @confirm="() => confirmAlert(deleteAction)"
        :label = "$t('Delete row')" 
        :description = "$t('Are you sure you want to delete this row? This action can not be undone')"
    />
</template>
  