<template>
    <AppLayout>
        <div class="flex flex-col items-center justify-center min-h-[70vh] px-4">
            <div class="bg-white border border-red-100 shadow-md rounded-2xl p-8 text-center w-full max-w-2xl">
                <div class="flex items-center justify-center mb-4">
                    <div class="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-10 h-10 text-red-600">
                            <path fill="currentColor" d="M12 2C6.488 2 2 6.492 2 12s4.488 10 10 10 10-4.492 10-10S17.512 2 12 2zm0 4a1.5 1.5 0 0 1 1.5 1.5V12a1.5 1.5 0 0 1-3 0V7.5A1.5 1.5 0 0 1 12 6zm0 9a1.5 1.5 0 1 1 0 3a1.5 1.5 0 0 1 0-3z"/>
                        </svg>
                    </div>
                </div>
                <h1 class="text-2xl font-semibold text-slate-900 mb-2">Account deactivated</h1>
                <p class="text-sm text-slate-600 mb-4">
                    Your subscription has been deactivated, so navigation to sections like Chats, Campaigns, and Contacts is disabled.
                </p>
                <p class="text-sm text-slate-600 mb-6">
                    Please email <span class="font-semibold text-slate-900">{{ props.supportEmail }}</span> to resolve account status.
                </p>
                <a :href="`mailto:${props.supportEmail}`" class="inline-flex items-center justify-center px-5 py-2 rounded-full bg-red-600 text-white text-sm font-semibold hover:bg-red-700 transition">
                    Contact support
                </a>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
import AppLayout from "./../Layout/App.vue";

const props = defineProps({
    supportEmail: {
        type: String,
        required: true,
    },
});
</script>
