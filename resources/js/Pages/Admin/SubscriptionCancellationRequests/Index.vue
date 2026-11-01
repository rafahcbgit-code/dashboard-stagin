<template>
    <AppLayout>
        <div class="min-h-full bg-white px-4 py-8 text-gray-900 md:bg-inherit md:p-8">
            <div class="mb-6">
                <h1 class="text-xl font-semibold">{{ $t('Subscription cancellation requests') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ $t('Review cancellation requests submitted by organization owners.') }}</p>
            </div>

            <div class="mb-4 flex gap-2">
                <Link v-for="filter in filtersList" :key="filter.value" :href="`/admin/subscription-cancellation-requests?status=${filter.value}`"
                    class="rounded-md border px-3 py-2 text-sm"
                    :class="props.filters.status === filter.value ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-700'">
                    {{ $t(filter.label) }}
                </Link>
            </div>

            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">{{ $t('Organization') }}</th>
                            <th class="px-4 py-3">{{ $t('Requested by') }}</th>
                            <th class="px-4 py-3">{{ $t('Reason') }}</th>
                            <th class="px-4 py-3">{{ $t('Submitted at') }}</th>
                            <th class="px-4 py-3">{{ $t('Status') }}</th>
                            <th class="px-4 py-3">{{ $t('Administrator response') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="item in props.rows.data" :key="item.id" class="align-top">
                            <td class="whitespace-nowrap px-4 py-4 font-medium">{{ item.organization?.name || '—' }}</td>
                            <td class="px-4 py-4">
                                <div>{{ item.user?.first_name }} {{ item.user?.last_name }}</div>
                                <div class="text-xs text-gray-500">{{ item.user?.email }}</div>
                            </td>
                            <td class="max-w-md whitespace-pre-line px-4 py-4">{{ item.reason }}</td>
                            <td class="whitespace-nowrap px-4 py-4">{{ item.created_at }}</td>
                            <td class="whitespace-nowrap px-4 py-4">
                                <span class="rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="item.status === 'pending' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'">
                                    {{ $t(item.status === 'pending' ? 'Pending review' : 'Reviewed') }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4">
                                <form v-if="item.status === 'pending'" @submit.prevent="respond(item)" class="min-w-64 space-y-2">
                                    <textarea v-model="responses[item.id]" required minlength="2" maxlength="2000" rows="2"
                                        class="w-full rounded border border-gray-300 p-2 text-xs" :placeholder="$t('Write your response to the owner')"></textarea>
                                    <button type="submit" :disabled="processingId === item.id"
                                        class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                                        {{ processingId === item.id ? $t('Sending...') : $t('Send response and close request') }}
                                    </button>
                                </form>
                                <div v-else class="max-w-sm whitespace-pre-line text-xs text-gray-700">
                                    {{ item.admin_response }}
                                    <div class="mt-1 text-gray-500">{{ item.reviewer ? `${item.reviewer.first_name} ${item.reviewer.last_name}` : '' }}</div>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="props.rows.data.length === 0">
                            <td colspan="6" class="px-4 py-10 text-center text-gray-500">{{ $t('No cancellation requests found.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="props.rows.links?.length > 3" class="mt-4 flex flex-wrap gap-2">
                <Link v-for="(link, index) in props.rows.links" :key="index" :href="link.url || '#'" :preserve-scroll="true" class="rounded border px-3 py-1.5 text-sm"
                    :class="link.active ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white'"
                    v-html="link.label" />
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../Layout/App.vue';

const props = defineProps({ title: String, rows: Object, filters: Object });
const processingId = ref(null);
const responses = ref({});
const filtersList = [
    { value: 'pending', label: 'Pending review' },
    { value: 'reviewed', label: 'Reviewed' },
    { value: 'all', label: 'All requests' },
];

const respond = (item) => {
    processingId.value = item.id;
    router.post(`/admin/subscription-cancellation-requests/${item.id}/review`, { admin_response: responses.value[item.id] }, {
        preserveScroll: true,
        onFinish: () => { processingId.value = null; },
        onSuccess: () => { delete responses.value[item.id]; },
    });
};
</script>
