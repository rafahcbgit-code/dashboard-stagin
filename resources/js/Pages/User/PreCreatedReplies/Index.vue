<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] overflow-y-scroll capitalize">
            <div class="flex justify-between mt-8 md:mt-0 mb-6">
                <div>
                    <h2 class="text-xl mb-1">{{ $t('Saved Replies') }}</h2>
                    <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ml-1 mt-1">{{ $t('Manage your pre-created replies for quick use in chats.') }}</span>
                    </p>
                </div>
                <div class="space-s-2 flex items-center">
                    <Link href="/pre-created-replies/create" class="md:block hidden rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Create Reply') }}</Link>
                </div>
            </div>

            <div class="relative flex h-11 w-full items-center rounded-xl border border-slate-200 bg-slate-50 text-sm shadow-sm transition focus-within:border-indigo-400 focus-within:bg-white focus-within:ring-4 focus-within:ring-indigo-500/10 md:w-80">
                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 rtl:right-3 rtl:left-auto">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-4-4" /></svg>
                </span>
                <input v-model="params.search" @input="search" type="text" class="w-full bg-transparent py-2.5 pl-10 pr-10 text-sm text-slate-800 outline-none placeholder:text-slate-400 rtl:pl-10 rtl:pr-10" :placeholder="$t('Search by title')" />
                <button v-if="!isSearching && params.search" @click="clearSearch" type="button" class="absolute right-2 inline-flex h-7 w-7 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-200 hover:text-slate-700 rtl:right-auto rtl:left-2" :aria-label="$t('Clear search')">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10s10-4.5 10-10S17.5 2 12 2m3.7 12.3c.4.4.4 1 0 1.4c-.4.4-1 0-1.4 0L12 13.4l-2.3 2.3c-.4.4-1 0-1.4 0c-.4-.4 0-1 0-1.4l2.3-2.3l-2.3-2.3c-.4-.4 0-1.4 0-1.4c.4 0 1 0 1.4 0L12 10.6l2.3-2.3c.4-.4 1 0 1.4 0c.4 0 1 1 0 1.4L13.4 12z"/></svg>
                </button>
                <span v-if="isSearching" class="absolute right-3 text-slate-400 rtl:right-auto rtl:left-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" class="animate-spin"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="3" stroke-dasharray="42 14"/></svg>
                </span>
            </div>

            <Table :rows="rows">
                <TableHeader>
                    <TableHeaderRow>
                        <TableHeaderRowItem :position="'first'">{{ $t('Title') }}</TableHeaderRowItem>
                        <TableHeaderRowItem>{{ $t('Type') }}</TableHeaderRowItem>
                        <TableHeaderRowItem>{{ $t('Created At') }}</TableHeaderRowItem>
                        <TableHeaderRowItem :position="'last'"></TableHeaderRowItem>
                    </TableHeaderRow>
                </TableHeader>
                <TableBody>
                    <TableBodyRow v-for="(row, index) in rows.data" :key="row.id" :class="!isLastRow(index) ? 'border-b' : ''">
                        <TableBodyRowItem :position="'first'">{{ row.title }}</TableBodyRowItem>
                        <TableBodyRowItem class="capitalize">{{ row.type.replace('_', ' ') }}</TableBodyRowItem>
                        <TableBodyRowItem>{{ new Date(row.created_at).toLocaleDateString() }}</TableBodyRowItem>
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
                                        <DropdownItem :href="`/pre-created-replies/${row.uuid}/edit`">{{ $t('Edit') }}</DropdownItem>
                                        <DropdownItem as="button" @click="deleteRow(row.uuid)">{{ $t('Delete') }}</DropdownItem>
                                    </DropdownItemGroup>
                                </template>
                            </Dropdown>
                        </TableBodyRowItem>
                    </TableBodyRow>
                </TableBody>
            </Table>
            <div v-if="rows.data.length === 0" class="bg-white rounded-xl">
                <div class="p-4 py-8">
                    <div class="flex justify-center mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 32 32"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="2" d="M12 15h8m-8 4h8m8 5V11c0-1.105-.892-2-1.997-2H17c-2 0-2-3-5-3H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h20a2 2 0 0 0 2-2Z"></path></svg>
                    </div>
                    <h3 class="text-center text-lg font-medium mb-4">{{ $t('No replies found') }}</h3>
                    <div class="flex justify-center">
                        <Link href="/pre-created-replies/create" class="rounded-md px-3 py-2 text-sm hover:shadow-md text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 bg-primary">
                            <span>{{ $t('Create Reply') }}</span>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "../Layout/App.vue";
    import { Link, router } from "@inertiajs/vue3";
    import { ref } from 'vue';
    import debounce from 'lodash/debounce';
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
    
    const props = defineProps(['rows', 'filters']);

    const params = ref({
        search: props.filters?.search || '',
    });
    const isSearching = ref(false);

    const runSearch = () => {
        router.get('/pre-created-replies', params.value, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                isSearching.value = false;
            },
        });
    };

    const search = debounce(() => {
        isSearching.value = true;
        runSearch();
    }, 500);

    const clearSearch = () => {
        search.cancel();
        params.value.search = '';
        isSearching.value = true;
        runSearch();
    };

    const isLastRow = (index) => {
        return index === props.rows.data.length - 1;
    }

    const deleteRow = (uuid) => {
        if(confirm('Are you sure you want to delete this reply?')) {
            router.delete(`/pre-created-replies/${uuid}`);
        }
    }
</script>
