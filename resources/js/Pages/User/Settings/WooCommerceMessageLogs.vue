<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-10 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] h-full md:overflow-y-auto">
            <div class="flex justify-between mb-6">
                <div>
                    <h1 class="text-xl mb-1">{{ $t('WooCommerce Message Logs') }}</h1>
                    <p class="text-sm leading-6 text-gray-600">
                        {{ $t('Track automatic messages sent from WooCommerce automations.') }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="/settings/woocommerce/automations"
                        class="rounded-md bg-white px-3 py-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                        {{ $t('Back') }}
                    </a>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-lg p-6">
                <div class="mb-4 grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div>
                        <label class="text-xs text-gray-600">{{ $t('From') }}</label>
                        <input v-model="form.created_from" type="date" class="w-full border rounded-md px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="text-xs text-gray-600">{{ $t('To') }}</label>
                        <input v-model="form.created_to" type="date" class="w-full border rounded-md px-3 py-2 text-sm" />
                    </div>
                    <div class="flex items-end">
                        <button @click="applyFilters" class="bg-primary text-white px-4 py-2 rounded-md text-sm">{{ $t('Apply') }}</button>
                        <button @click="resetFilters" class="bg-slate-100 ml-2 px-4 py-2 rounded-md text-sm">{{ $t('Reset') }}</button>
                        <button @click="exportCsv" class="ml-2 bg-white border px-4 py-2 rounded-md text-sm">{{ $t('Export') }}</button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600">
                                <th class="py-2 pr-4">{{ $t('Created') }}</th>
                                <th class="py-2 pr-4">{{ $t('Event') }}</th>
                                <th class="py-2 pr-4">{{ $t('Automation') }}</th>
                                <th class="py-2 pr-4">{{ $t('Recipient') }}</th>
                                <th class="py-2 pr-4">{{ $t('Template') }}</th>
                                <th class="py-2 pr-4">{{ $t('Status') }}</th>
                                <th class="py-2 pr-4">{{ $t('Attempts') }}</th>
                                <th class="py-2 pr-4">{{ $t('Error') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows?.data ?? []" :key="row.id" class="border-t">
                                <td class="py-3 pr-4 whitespace-nowrap">{{ row.created_at }}</td>
                                <td class="py-3 pr-4 whitespace-nowrap">{{ row.source_event }}</td>
                                <td class="py-3 pr-4 whitespace-nowrap">{{ row.automation_key }}</td>
                                <td class="py-3 pr-4 whitespace-nowrap">{{ row.recipient_e164 }}</td>
                                <td class="py-3 pr-4 whitespace-nowrap">{{ row.template_name || row.template_id }}</td>
                                <td class="py-3 pr-4 whitespace-nowrap">{{ row.status }}</td>
                                <td class="py-3 pr-4 whitespace-nowrap">{{ row.attempts }}</td>
                                <td class="py-3 pr-4">
                                    <div class="max-w-[420px] truncate" :title="row.last_error_message || ''">
                                        {{ row.last_error_message || '' }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="(rows?.data ?? []).length === 0" class="text-sm text-gray-600 mt-4">
                        {{ $t('No logs yet.') }}
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '../Layout/App.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    title: String,
    rows: Object,
    filters: Object,
});

const form = useForm({
    status: props.filters?.status ?? '',
    event: props.filters?.event ?? '',
    template_id: props.filters?.template_id ?? '',
    created_from: props.filters?.created_from ?? null,
    created_to: props.filters?.created_to ?? null,
});

const applyFilters = () => {
    form.get('/settings/woocommerce/message-logs', { preserveState: true, preserveScroll: true });
};

const resetFilters = () => {
    form.created_from = null;
    form.created_to = null;
    form.status = '';
    form.event = '';
    form.template_id = '';
    applyFilters();
};

const exportCsv = () => {
    const params = new URLSearchParams();
    if (form.created_from) params.append('created_from', form.created_from);
    if (form.created_to) params.append('created_to', form.created_to);
    if (form.status) params.append('status', form.status);
    if (form.event) params.append('event', form.event);
    if (form.template_id) params.append('template_id', form.template_id);

    const url = `/settings/woocommerce/message-logs/export?${params.toString()}`;
    window.location.href = url;
};
</script>

