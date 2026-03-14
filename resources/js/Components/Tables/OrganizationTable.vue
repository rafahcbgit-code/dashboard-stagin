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

    const deleteAction = (key) => {
        form.delete('/admin/organizations/' + key);
    }

    const isLastRow = (index) => {
      return index === props.rows.data.length - 1;
    }
    
    const params = ref({
        search: props.filters.search || null,
        subscription_status: props.filters.subscription_status || 'all',
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
        const url = window.location.pathname;

        router.visit(url, {
            method: 'get',
            data: {
                search: params.value.search,
                subscription_status: params.value.subscription_status,
            },
            preserveState: true,
        })
    }
</script>
<template>
    <div class="space-y-2">
        <div class="md:bg-white flex items-center border border-primary md:border-none md:shadow-sm h-12 md:h-10 md:w-full rounded-[0.5rem] text-xl md:text-sm">
            <span class="pl-3">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 15l6 6m-11-4a7 7 0 1 1 0-14a7 7 0 0 1 0 14Z"/></svg>
            </span>
            <input @input="search" v-model="params.search" type="text" class="outline-none px-4 w-full bg-inherit" :placeholder="$t('Search organizations')">
            <button v-if="isSearching === false && params.search" @click="clearSearch" type="button" class="pr-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10s10-4.5 10-10S17.5 2 12 2zm3.7 12.3c.4.4.4 1 0 1.4c-.4.4-1 .4-1.4 0L12 13.4l-2.3 2.3c-.4.4-1 .4-1.4 0c-.4-.4-.4-1 0-1.4l2.3-2.3l-2.3-2.3c-.4-.4-.4-1 0-1.4c.4-.4 1-.4 1.4 0l2.3 2.3l2.3-2.3c.4-.4 1-.4 1.4 0c.4.4.4 1 0 1.4L13.4 12l2.3 2.3z"/></svg>
            </button>
            <span v-if="isSearching" class="pr-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="0 12 12;90 12 12;180 12 12;270 12 12"/><animate attributeName="opacity" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" begin="0.2s" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="30 12 12;120 12 12;210 12 12;300 12 12"/><animate attributeName="opacity" begin="0.2s" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" begin="0.4s" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="60 12 12;150 12 12;240 12 12;330 12 12"/><animate attributeName="opacity" begin="0.4s" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle></svg>
            </span>
        </div>
        <div class="flex items-center gap-2 text-xs md:text-sm">
            <select v-model="params.subscription_status" @change="runSearch" class="border rounded px-2 py-1 text-xs md:text-sm">
                <option value="all">{{ $t('All subscriptions') }}</option>
                <option value="trial">{{ $t('Only trial subscriptions') }}</option>
                <option value="active">{{ $t('Only active subscriptions') }}</option>
                <option value="suspended">{{ $t('Only suspended subscriptions') }}</option>
            </select>
        </div>
    </div>
    <Table :rows="rows">
        <TableHeader>
            <TableHeaderRow>
                <TableHeaderRowItem :position="'first'">{{ $t('Name') }}</TableHeaderRowItem>
                <TableHeaderRowItem class="hidden sm:table-cell">{{ $t('Owner') }}</TableHeaderRowItem>
                <TableHeaderRowItem class="hidden sm:table-cell">{{ $t('Owner email') }}</TableHeaderRowItem>
                <TableHeaderRowItem class="hidden sm:table-cell">
                    <span>{{ $t('Seats') }}</span>
                </TableHeaderRowItem>
                <TableHeaderRowItem class="hidden sm:table-cell">{{ $t('Plan') }}</TableHeaderRowItem>
                <TableHeaderRowItem class="hidden sm:table-cell">{{ $t('Is Connected') }}</TableHeaderRowItem>
                <TableHeaderRowItem class="hidden sm:table-cell">{{ $t('Start Date') }}</TableHeaderRowItem>
                <TableHeaderRowItem class="hidden sm:table-cell">{{ $t('Valid Until') }}</TableHeaderRowItem>
               <TableHeaderRowItem class="hidden sm:table-cell">
                    <span class="float-right">{{ $t('Last updated') }}</span>
                </TableHeaderRowItem>
                <TableHeaderRowItem :position="'last'"></TableHeaderRowItem>
            </TableHeaderRow>
        </TableHeader>
        <TableBody>
            <TableBodyRow v-for="(item, index) in rows.data" :key="index" :class="!isLastRow(index) ? 'border-b' : ''">
                <TableBodyRowItem :position="'first'" class="capitalize">{{ item.name }}</TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">{{ item.owner?.user?.full_name  ?? $t('Not set')}}</TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">{{ item.owner?.user?.email  ?? $t('Not set')}}</TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">
                    <span>{{ item.teams_count }}</span>
                </TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">
                    <span class="py-1 rounded-[5px] text-xs px-3 bg-[#ddebf7] text-slate-700">
                        {{ item.subscription?.plan?.name ?? $t('Not set') }}
                    </span>
                </TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">
                    <span class="py-1 rounded-[5px] text-xs px-3 bg-[#ddebf7] text-slate-700">
                        {{ item.metadata !== null ? $t('True') : $t('False') }}
                    </span>
                </TableBodyRowItem>

                  <TableBodyRowItem class="hidden sm:table-cell">
                    <span class="py-1 rounded-[5px] text-xs px-3 bg-[#ddebf7] text-slate-700">
                        {{ item.subscription?.start_date ?? $t('Not set') }}
                    </span>
                </TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">
                    <span class="py-1 rounded-[5px] text-xs px-3 bg-[#ddebf7] text-slate-700">
                        {{ item.subscription?.valid_until ?? $t('Not set') }}
                    </span>
                </TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">
                    <span class="float-right">{{ item.updated_at }}</span>
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
                                <DropdownItem :href="'/admin/organizations/' + item.uuid">{{ $t('View/edit') }}</DropdownItem>
                                <DropdownItem :href="'/admin/organizations/' + item.id + '/login-as'">{{ $t('Login as') }}</DropdownItem>
                                <DropdownItem as="button" @click="openAlert(item.uuid)">{{ $t('Delete') }}</DropdownItem>
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
  