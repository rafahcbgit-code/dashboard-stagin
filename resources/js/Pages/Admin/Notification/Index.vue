<script setup>
import AppLayout from '../Layout/App.vue'
import { Link, router } from '@inertiajs/vue3'

defineProps({ notifications: Object })

const markRead = async (notification) => {
    try {
        await fetch(`/admin/notifications/${notification.id}/read`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        })
        router.reload()
    } catch (_) {
        router.reload()
    }
}
</script>

<template>
    <AppLayout>
        <div class="max-w-3xl p-6">
            <h1 class="mb-5 text-2xl font-semibold">{{ $t('Notification inbox') }}</h1>
            <p v-if="!notifications.data.length" class="text-gray-600">{{ $t('No notifications yet') }}</p>
            <button v-for="notification in notifications.data" :key="notification.id" type="button" @click="markRead(notification)" class="block w-full text-left border-b p-4" :class="notification.clicked ? 'bg-white' : 'bg-blue-50'">
                <span class="block text-sm font-medium">{{ notification.payload?.message || notification.notification_type }}</span>
                <span class="block text-xs text-gray-500">{{ notification.sent_at }}</span>
            </button>
            <div class="mt-4 flex gap-3 text-sm">
                <Link v-if="notifications.prev_page_url" :href="notifications.prev_page_url">{{ $t('Previous') }}</Link>
                <Link v-if="notifications.next_page_url" :href="notifications.next_page_url">{{ $t('Next') }}</Link>
            </div>
        </div>
    </AppLayout>
</template>
