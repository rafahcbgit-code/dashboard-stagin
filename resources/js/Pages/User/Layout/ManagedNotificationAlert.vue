<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import axios from 'axios'

const notification = ref(null)
const closing = ref(false)
const closeError = ref(false)
let timer

const refresh = async () => {
    if (closing.value) return
    try {
        const response = await axios.get('/notifications/managed-unread')
        if (closing.value) return
        const next = response.data.notification
        if (next?.id !== notification.value?.id) {
            notification.value = next
            closeError.value = false
            window.dispatchEvent(new CustomEvent('notification:changed'))
        }
    } catch (_) {
        // Keep the current alert visible until the next successful check.
    }
}

const close = async () => {
    if (!notification.value || closing.value) return
    closing.value = true
    closeError.value = false
    try {
        await axios.post(`/notifications/${notification.value.id}/click`, {}, {
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        })
        notification.value = null
        window.dispatchEvent(new CustomEvent('notification:changed'))
    } catch (_) {
        closeError.value = true
    } finally {
        closing.value = false
    }
    if (!closeError.value) refresh()
}

onMounted(() => {
    refresh()
    timer = window.setInterval(refresh, 15000)
    window.addEventListener('focus', refresh)
    window.addEventListener('notification:received', refresh)
})

onUnmounted(() => {
    window.clearInterval(timer)
    window.removeEventListener('focus', refresh)
    window.removeEventListener('notification:received', refresh)
})
</script>

<template>
    <div v-if="notification" role="alert" aria-live="assertive" class="fixed bottom-4 end-4 z-[60] w-[calc(100vw-2rem)] max-w-sm rounded-lg border border-blue-200 bg-white p-4 shadow-xl">
        <div class="flex items-start gap-3">
            <p class="min-w-0 flex-1 whitespace-pre-wrap break-words text-sm text-gray-900">{{ notification.message }}</p>
            <button type="button" :disabled="closing" :aria-label="$t('Close notification')" class="shrink-0 rounded p-1 text-gray-500 hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-50" @click="close">×</button>
        </div>
        <p v-if="closeError" class="mt-2 text-xs text-red-700">{{ $t('Could not close the notification. Please try again.') }}</p>
    </div>
</template>
