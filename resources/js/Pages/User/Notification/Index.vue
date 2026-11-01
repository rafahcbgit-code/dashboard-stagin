<script setup>
import AppLayout from '../Layout/App.vue'
import { ref, computed, onMounted } from 'vue'
import { Link, router } from '@inertiajs/vue3'

const props = defineProps({
    notifications: Object,
    modules: Array,
})

const markAllLoading = ref(false)

const formatDate = (dateStr) => {
    if (!dateStr) return ''
    const date = new Date(dateStr)
    const now = new Date()
    const diff = now - date

    if (diff < 60000) return 'Just now'
    if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`
    if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const getNotificationIcon = (type) => {
    switch (type) {
        case 'chat_assigned':
            return `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`
        case 'new_message':
            return `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5.6 19.92 1.524-1.219.01-.008c.318-.255.479-.383.658-.474.16-.082.331-.142.508-.178.199-.041.406-.041.822-.041h8.681c1.118 0 1.678 0 2.105-.218a2 2 0 0 0 .874-.874C21 16.48 21 15.92 21 14.804V7.197c0-1.118 0-1.678-.218-2.105a2.001 2.001 0 0 0-.875-.874C19.48 4 18.92 4 17.8 4H6.2c-1.12 0-1.68 0-2.108.218a1.999 1.999 0 0 0-.874.874C3 5.52 3 6.08 3 7.2v11.471c0 1.066 0 1.599.218 1.872a1 1 0 0 0 .783.377c.35 0 .766-.334 1.599-1Z"/></svg>`
        case 'chat_note_added':
            return `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H18a2 2 0 0 0 2-2V7l-5-5H6a2 2 0 0 0-2 2v15.5Z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg>`
        default:
            return `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>`
    }
}

const getContactUuid = (notification) => {
    return notification.payload?.contact_uuid || null
}

const handleClick = async (notification) => {
    const contactUuid = getContactUuid(notification)

    // Mark as clicked via API
    try {
        await fetch(`/notifications/${notification.id}/click`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        })
    } catch (e) {
        // Continue even if mark-as-read fails
    }

    if (contactUuid) {
        router.visit(`/chats/${contactUuid}`)
    } else {
        router.reload()
    }
}

const markAllRead = async () => {
    markAllLoading.value = true
    try {
        await fetch('/notifications/mark-all-read', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        })
        router.reload()
    } finally {
        markAllLoading.value = false
    }
}
</script>

<template>
    <AppLayout>
        <div class="cb-page-shell">
            <div class="mx-auto w-full max-w-3xl space-y-5">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-900">{{ $t('Notifications') }}</h2>
                        <p class="text-sm text-gray-500 mt-1">{{ $t('View and manage your notifications') }}</p>
                    </div>
                    <button
                        @click="markAllRead"
                        :disabled="markAllLoading"
                        class="text-sm text-gray-600 hover:text-gray-900 border border-gray-300 rounded-md px-3 py-1.5 hover:bg-gray-50 transition-colors disabled:opacity-50"
                    >
                        {{ $t('Mark all as read') }}
                    </button>
                </div>

                <div class="cb-section overflow-hidden">
                    <div v-if="notifications.data.length === 0" class="p-8 text-center">
                        <div class="text-gray-400 mb-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mx-auto">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                            </svg>
                        </div>
                        <p class="text-gray-500 text-sm">{{ $t('No notifications yet') }}</p>
                    </div>

                    <div v-else>
                        <div
                            v-for="notification in notifications.data"
                            :key="notification.id"
                            @click="handleClick(notification)"
                            class="flex items-start gap-3 px-4 py-3 border-b border-slate-100 last:border-b-0 transition-colors cursor-pointer"
                            :class="notification.clicked ? 'bg-white' : 'bg-blue-50/50 hover:bg-blue-50'"
                        >
                            <div
                                class="flex-shrink-0 mt-0.5 rounded-full p-2"
                                :class="notification.clicked ? 'bg-gray-100 text-gray-400' : 'bg-blue-100 text-blue-600'"
                                v-html="getNotificationIcon(notification.notification_type)"
                            />
                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-gray-900" :class="notification.clicked ? 'font-normal' : 'font-medium'">
                                    {{ notification.payload?.message || $t('New notification') }}
                                </p>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    {{ formatDate(notification.sent_at) }}
                                </p>
                            </div>
                            <div v-if="!notification.clicked" class="flex-shrink-0 mt-1.5">
                                <span class="inline-block w-2 h-2 bg-blue-500 rounded-full"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="notifications.last_page > 1" class="flex items-center justify-between mt-4">
                    <p class="text-sm text-gray-500">
                        {{ $t('Showing') }} {{ notifications.from }} {{ $t('to') }} {{ notifications.to }} {{ $t('of') }} {{ notifications.total }}
                    </p>
                    <div class="flex gap-2">
                        <Link
                            v-if="notifications.prev_page_url"
                            :href="notifications.prev_page_url"
                            class="px-3 py-1 text-sm border border-gray-300 rounded-md hover:bg-gray-50"
                        >
                            {{ $t('Previous') }}
                        </Link>
                        <Link
                            v-if="notifications.next_page_url"
                            :href="notifications.next_page_url"
                            class="px-3 py-1 text-sm border border-gray-300 rounded-md hover:bg-gray-50"
                        >
                            {{ $t('Next') }}
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
