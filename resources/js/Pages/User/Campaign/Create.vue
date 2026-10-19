<template>
    <AppLayout>
        <div class="flex min-h-full flex-col bg-white border-l text-[#000] md:h-full md:min-h-0 md:overflow-hidden">
            <div class="z-10 flex shrink-0 justify-between border-b bg-white px-4 py-4 pb-2 capitalize sm:px-8 md:static">
                <div>
                    <h2 class="text-xl mb-1">{{ $t('New campaign') }}</h2>
                    <p class="flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ml-1 mt-1">{{ $t('Create campaign') }}</span>
                    </p>
                </div>
                <div class="space-s-2 flex items-center">
                    <Link href="/campaigns" class="rounded-md bg-black px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Back') }}</Link>
                </div>
            </div>
            
            <div class="min-h-0 flex-1 md:overflow-hidden">
                <CampaignForm 
                    :templates="templates" 
                    :contactGroups="contactGroups" 
                    :settings="settings"
                    :contactFields="contactFields"
                    :resourceUsage="resourceUsage"
                    :totalContacts="totalContacts"
                    :campaignDraft="campaignDraft"
                />
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
    import axios from "axios";
    import AppLayout from "./../Layout/App.vue";
    import CampaignForm from '@/Components/CampaignForm.vue';
    import WhatsappTemplate from '@/Components/WhatsappTemplate.vue';
    import { ref, computed, onMounted } from 'vue';
    import { Link, useForm } from "@inertiajs/vue3";
    import 'vue3-toastify/dist/index.css';
    import { trans } from 'laravel-vue-i18n';

    const props = defineProps(['templates', 'contactGroups', 'settings', 'contactFields', 'resourceUsage', 'totalContacts', 'campaignDraft']);
</script>
