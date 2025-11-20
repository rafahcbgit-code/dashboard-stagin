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
    
    const props = defineProps(['rows']);

    const isLastRow = (index) => {
        return index === props.rows.data.length - 1;
    }

    const deleteRow = (uuid) => {
        if(confirm('Are you sure you want to delete this reply?')) {
            router.delete(`/pre-created-replies/${uuid}`);
        }
    }
</script>
