<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-10 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] h-full md:overflow-y-auto">
            <div class="flex justify-between mb-6">
                <div>
                    <h1 class="text-xl mb-1">{{ $t('Salla Message Logs') }}</h1>
                    <p class="text-sm leading-6 text-gray-600">
                        {{ $t('Track automatic messages sent from Salla automations.') }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="/settings/salla/automations"
                        class="rounded-md bg-white px-3 py-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                        {{ $t('Back') }}
                    </a>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-lg p-6">
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

const props = defineProps({
    title: String,
    rows: Object,
    filters: Object,
});
</script>

