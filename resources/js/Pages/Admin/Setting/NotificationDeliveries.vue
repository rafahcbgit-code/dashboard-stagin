<script setup>
import AppLayout from './Layout/App.vue'
import { Link } from '@inertiajs/vue3'

defineProps({
    deliveries: Object,
})
</script>

<template>
    <AppLayout>
        <div class="max-w-5xl space-y-6">
            <div>
                <h1 class="text-2xl font-semibold">{{ $t('Recent delivery status') }}</h1>
                <Link href="/admin/settings/managed-notifications" class="mt-2 inline-block text-sm font-medium text-primary hover:underline">← {{ $t('Managed notifications') }}</Link>
            </div>

            <section class="rounded-lg border bg-white p-5">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="border-b">
                                <th class="p-2">ID</th>
                                <th class="p-2">{{ $t('Organization') }}</th>
                                <th class="p-2">{{ $t('Type') }}</th>
                                <th class="p-2">{{ $t('Channel') }}</th>
                                <th class="p-2">{{ $t('Status') }}</th>
                                <th class="p-2">{{ $t('Attempts') }}</th>
                                <th class="p-2">{{ $t('Error') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="delivery in deliveries.data" :key="delivery.id" class="border-b align-top">
                                <td class="p-2">{{ delivery.id }}</td>
                                <td class="p-2">{{ delivery.organization_id }}</td>
                                <td class="p-2">{{ delivery.type_key }}<span v-if="delivery.is_test"> (test)</span></td>
                                <td class="p-2">{{ delivery.channel }}</td>
                                <td class="p-2">{{ delivery.status }}</td>
                                <td class="p-2">{{ delivery.attempts }}</td>
                                <td class="p-2 break-words">{{ delivery.last_error || '—' }}</td>
                            </tr>
                            <tr v-if="!deliveries.data.length">
                                <td colspan="7" class="p-4 text-center text-gray-500">{{ $t('No deliveries yet') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-4 flex gap-3 text-sm">
                    <Link v-if="deliveries.prev_page_url" :href="deliveries.prev_page_url">{{ $t('Previous') }}</Link>
                    <Link v-if="deliveries.next_page_url" :href="deliveries.next_page_url">{{ $t('Next') }}</Link>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
