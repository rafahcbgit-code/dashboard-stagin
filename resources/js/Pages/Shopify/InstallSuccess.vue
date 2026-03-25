<template>
    <div class="min-h-screen flex items-center justify-center bg-slate-50 px-4 py-12">
        <div class="max-w-2xl w-full bg-white border border-slate-200 shadow-sm rounded-2xl p-10 text-center">
            <div class="mb-6">
                <h1 class="text-2xl font-semibold text-slate-900 mb-2">App Installed Successfully</h1>
                <p class="text-sm text-slate-500">
                    Thank you for connecting your Shopify store with ChatBerry. To continue with a new ChatBerry
                    account please reach out using the channels listed below.
                </p>
            </div>

            <div class="space-y-4 text-left">
                <div
                    class="flex items-center justify-between rounded-lg bg-slate-50 border border-slate-100 px-4 py-3">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Email</p>
                        <p class="text-base font-medium text-slate-900">
                            <a :href="`mailto:${contactEmail}`" class="text-primary hover:underline">
                                {{ contactEmail }}
                            </a>
                        </p>
                    </div>
                </div>

                <div
                    class="flex items-center justify-between rounded-lg bg-slate-50 border border-slate-100 px-4 py-3">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">WhatsApp</p>
                        <p class="text-base font-medium text-slate-900">
                            <a :href="whatsappLink" target="_blank" rel="noreferrer"
                                class="text-primary hover:underline">
                                {{ whatsapp }}
                            </a>
                        </p>
                    </div>
                    
                </div>
            </div>

            <div class="mt-10 text-sm text-slate-500">
                <p>
                    If you need extra assistance or custom onboarding, our team is ready to respond during business
                    hours.
                </p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Link, router } from "@inertiajs/vue3";
import { ref, onMounted } from 'vue';
import { trans } from 'laravel-vue-i18n';

const props = defineProps({
    contactEmail: {
        type: String,
        default: 'info@chatberry.net',
    },
    whatsapp: {
        type: String,
        default: '+966542922948',
    },
});

const whatsappLink = computed(() => {
    if (!props.whatsapp) {
        return '#';
    }

    const digits = props.whatsapp.replace(/\D/g, '');
    return `https://wa.me/${digits}`;
});
</script>
