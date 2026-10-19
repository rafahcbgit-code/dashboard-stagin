<script setup>
    import 'vue3-toastify/dist/index.css';
    import { computed, ref } from 'vue';
    import debounce from 'lodash/debounce';
    import { router } from '@inertiajs/vue3';
    import Modal from '@/Components/Modal.vue';
    import Table from '@/Components/Table.vue';
    import TableHeader from '@/Components/TableHeader.vue';
    import TableHeaderRow from '@/Components/TableHeaderRow.vue';
    import TableHeaderRowItem from '@/Components/TableHeaderRowItem.vue';
    import TableBody from '@/Components/TableBody.vue';
    import TableBodyRow from '@/Components/TableBodyRow.vue';
    import TableBodyRowItem from '@/Components/TableBodyRowItem.vue';

    const props = defineProps({
        rows: {
            type: Object,
            required: true,
        },
        filters: {
            type: Object
        },
        uuid: {
            type: String
        }
    });
    
    const params = ref({
        search: props.filters.search,
    });

    const logs = ref(null);
    const messageStatus = ref(null);
    const isOpenModal = ref(false);
    const isSearching = ref(false);
    const emit = defineEmits(['delete']);

    const clearSearch = () => {
        params.value.search = null;
        runSearch();
    }

    const search = debounce(() => {
        isSearching.value = true;
        runSearch();
    }, 1000);

    const runSearch = () => {
        router.visit('/campaigns/' + props.uuid, {
            method: 'get',
            data: params.value,
        })
    }

    const openModal = (status, value) => {
        messageStatus.value = status;
        logs.value = Array.isArray(value) ? value : [];
        isOpenModal.value = true;
    }

    const getStatus = (metadata) => {
        return JSON.parse(metadata).status;
    }

    const getErrorDetails = (metadata) => {
        if (metadata && typeof metadata === 'object') return metadata;
        try {
            return JSON.parse(metadata || '{}');
        } catch {
            return {};
        }
    }

    const failedErrors = computed(() => {
        const entries = Array.isArray(logs.value) ? logs.value : [];
        return entries.flatMap((log) => {
            const metadata = getErrorDetails(log?.metadata);
            if (Array.isArray(metadata.errors) && metadata.errors.length) return metadata.errors;
            if (metadata.error || metadata.message || metadata.title) return [metadata.error || metadata];
            return [];
        });
    });

    const getFriendlyErrorKey = (error) => {
        const message = [error?.title, error?.message, error?.error_data?.details].filter(Boolean).join(' ').toLowerCase();
        const code = String(error?.code || '');

        if (code === '131026' || message.includes('message undeliverable')) return 'WhatsApp could not deliver this message.';
        if (code === '130472' || message.includes('part of an experiment')) return 'Recipient number is part of a WhatsApp experiment.';
        if (code === '131049' || message.includes('healthy ecosystem engagement') || message.includes('healthy ecosystem')) return 'WhatsApp withheld this message to protect a healthy messaging experience.';
        if (code === '131047' || message.includes('re-engagement message')) return 'The 24-hour customer service window has ended. Send an approved template to contact this person.';
        if (code === '131048' || message.includes('spam rate limit')) return 'WhatsApp temporarily limited sending to protect recipients from unwanted messages.';
        if (code === '131056' || message.includes('pair rate limit')) return 'Messages to this recipient are being sent too quickly. Wait before trying again.';

        return 'This message could not be delivered. Check the recipient number and the WhatsApp details below.';
    };
</script>
<template>
    <div class="bg-white flex items-center shadow-sm h-10 w-80 rounded-[0.5rem] mb-6 text-sm">
        <span class="pl-3">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 15l6 6m-11-4a7 7 0 1 1 0-14a7 7 0 0 1 0 14Z"/></svg>
        </span>
        <input @input="search" v-model="params.search" type="text" class="outline-none px-4 w-full" :placeholder="$t('Search campaigns')">
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
                <TableHeaderRowItem :position="'first'" class="hidden sm:table-cell">{{ $t('Contact') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Phone') }}</TableHeaderRowItem>
                <TableHeaderRowItem class="hidden sm:table-cell">{{ $t('Last updated') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Retries') }}</TableHeaderRowItem>
                <TableHeaderRowItem>{{ $t('Status') }}</TableHeaderRowItem>
                <TableHeaderRowItem :position="'last'"></TableHeaderRowItem>
            </TableHeaderRow>
        </TableHeader>
        <TableBody>
            <TableBodyRow v-for="(item, index) in rows.data" :key="index">
                <TableBodyRowItem :position="'first'" class="hidden sm:table-cell">{{ item.contact.full_name }}</TableBodyRowItem>
                <TableBodyRowItem>
                    {{ item.contact.phone }}
                </TableBodyRowItem>
                <TableBodyRowItem class="hidden sm:table-cell">
                    <span v-if="item.status === 'success'" class="border-b border-dashed border-black">{{ item.chat.created_at }}</span>
                    <span v-else class="border-b border-dashed border-black">{{ item.created_at }}</span>
                </TableBodyRowItem>
                <TableBodyRowItem>
                    <span>
                        {{ item.retry_count }}
                    </span>
                </TableBodyRowItem>
                <TableBodyRowItem>
                    <span class="px-2 py-1 text-xs rounded-md capitalize" :class="item.status === 'success' ? 'bg-green-700 text-white' : 'bg-red-400 text-white'">
                        {{ item.status === 'success' ? item.chat.status : item.status }}
                    </span>
                </TableBodyRowItem>
                <TableBodyRowItem>
                    <div @click="openModal(item.chat.status,  item.chat?.logs)" class="flex items-center underline cursor-pointer">
                        <svg class="mr-1" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><g fill="currentColor"><path d="M11 10.98a1 1 0 1 1 2 0v6a1 1 0 1 1-2 0zm1-4.929a1 1 0 1 0 0 2a1 1 0 0 0 0-2"/><path fill-rule="evenodd" d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10s10-4.477 10-10S17.523 2 12 2M4 12a8 8 0 1 0 16 0a8 8 0 0 0-16 0" clip-rule="evenodd"/></g></svg>
                        <span>{{ $t('More info') }}</span>
                    </div>
                </TableBodyRowItem>
            </TableBodyRow>
        </TableBody>
    </Table>
    <Modal :label="$t('Message info')" :isOpen="isOpenModal">
        <div class="max-w-md w-full space-y-8">
            <div class="mt-8 space-y-2">
                
                <div v-if="messageStatus === 'success' || messageStatus === 'sent' || messageStatus === 'read' || messageStatus === 'delivered'" v-for="(log, index) in logs" class="text-sm border-b pb-2">
                    <div class="flex items-center capitalize">
                        <svg class="mr-1" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m1.75 9.75l2.5 2.5m3.5-4l2.5-2.5m-4.5 4l2.5 2.5l6-6.5"/></svg>
                        <span>{{ $t(getStatus(log.metadata)) }}</span>
                    </div>
                    <div>{{ log.created_at }}</div>
                </div>
                <div v-else-if="messageStatus === 'failed'" class="space-y-3" dir="auto">
                    <div v-if="failedErrors.length === 0" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">
                        {{ $t('The message was not delivered. WhatsApp did not provide a readable reason. Check the recipient number and try again later if appropriate.') }}
                    </div>
                    <article v-for="(error, index) in failedErrors" :key="`${error.code || 'error'}-${index}`" class="rounded-xl border border-red-200 bg-red-50 p-4 text-start">
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-700" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-1-11a1 1 0 1 1 2 0v3a1 1 0 1 1-2 0V7Zm1 7a1.25 1.25 0 1 0 0-2.5A1.25 1.25 0 0 0 10 14Z" clip-rule="evenodd"/></svg>
                            </span>
                            <div class="min-w-0 flex-1">
                                <h3 class="font-semibold text-red-900">{{ $t('Message not delivered') }}</h3>
                                <p class="mt-1 text-sm leading-6 text-red-900">{{ $t(getFriendlyErrorKey(error)) }}</p>
                                <p v-if="error.code" class="mt-2 text-xs text-red-800">
                                    {{ $t('WhatsApp error code') }}: <bdi dir="ltr">{{ error.code }}</bdi>
                                </p>
                            </div>
                        </div>
                        <details class="mt-3 border-t border-red-200 pt-3 text-sm text-slate-700">
                            <summary class="cursor-pointer font-medium">{{ $t('Show original WhatsApp error') }}</summary>
                            <dl class="mt-2 space-y-2 break-words">
                                <div v-if="error.title || error.message">
                                    <dt class="font-medium">{{ $t('Original error message') }}</dt>
                                    <dd class="mt-1 whitespace-pre-wrap" dir="auto">{{ error.title || error.message }}</dd>
                                </div>
                                <div v-if="error.error_data?.details">
                                    <dt class="font-medium">{{ $t('Additional technical details') }}</dt>
                                    <dd class="mt-1 whitespace-pre-wrap" dir="auto">{{ error.error_data.details }}</dd>
                                </div>
                                <div v-if="!error.title && !error.message && !error.error_data?.details">
                                    <dd>{{ $t('No additional technical details were provided.') }}</dd>
                                </div>
                            </dl>
                        </details>
                    </article>
                </div>
            </div>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
            <div class="mt-2 w-full">
                <button type="button" @click="isOpenModal = false" class="inline-flex float-right justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">{{ $t('Close') }}</button>
            </div>
        </div>
    </Modal>
</template>
