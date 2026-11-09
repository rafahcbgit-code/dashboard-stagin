<template>
    <AppLayout>
        <div class="cb-page-shell overflow-y-hidden">
            <div class="flex justify-between">
                <div>
                    <h2 class="text-xl mb-1">{{ $t('Automation') }}</h2>
                    <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ms-1 mt-1">{{ $t('Respond automatically to messages based on your own criteria') }}</span>
                    </p>
                </div>
            </div>
        
            <div class="flex mt-4">
                <div class="md:w-1/5 bg-white border border-slate-200 h-[fit-content] rounded-lg pb-10 md:block hidden">
                    <ul class="pt-2 space-y-1 text-sm mb-2 px-2">
                        <li v-if="fbmodule" class="hover:bg-slate-50 hover:text-black rounded-[5px] px-1 truncate cursor-pointer" :class="$page.url.startsWith('/automation/triggers') ? 'bg-slate-50 text-black' : ''">
                            <Link rel="noopener noreferrer" href="/automation/triggers" class="flex items-center p-2 space-s-3 rounded-md">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2 12h3m14 0h3M12 2v3m0 14v3m-6.364-3.636l2.121-2.121m8.486-8.486l2.121-2.121M5.636 5.636l2.121 2.121m8.486 8.486l2.121 2.121"/><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                                <div class="overflow-hidden text-ellipsis">{{ $t('Triggers') }}</div>
                            </Link>
                        </li>
                        <li v-if="fbmodule" class="hover:bg-slate-50 hover:text-black rounded-[5px] px-1 truncate cursor-pointer" :class="$page.url.startsWith('/automation/smart-followup') ? 'bg-slate-50 text-black' : ''">
                            <Link rel="noopener noreferrer" href="/automation/smart-followup" class="flex items-center p-2 space-s-3 rounded-md">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M8 8h8"/><path d="M8 12h5"/></svg>
                                <div class="overflow-hidden text-ellipsis">{{ $t('Smart Followup') }}</div>
                            </Link>
                        </li>
                        <li v-if="fbmodule && !$page.props.automationAiSuspended" class="hover:bg-slate-50 hover:text-black rounded-[5px] px-1 truncate cursor-pointer" :class="$page.url.startsWith('/automation/knowledge-base') ? 'bg-slate-50 text-black' : ''">
                            <Link rel="noopener noreferrer" href="/automation/knowledge-base" class="flex items-center p-2 space-s-3 rounded-md">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7h8m-8 4h8m-8 4h5"/></svg>
                                <div class="overflow-hidden text-ellipsis">{{ $t('Knowledge Base') }}</div>
                            </Link>
                        </li>
                        <li v-if="fbmodule" class="hover:bg-slate-50 hover:text-black rounded-[5px] px-1 truncate cursor-pointer" :class="$page.url.startsWith('/automation/bot-settings') || $page.url === '/automation/ai-settings' ? 'bg-slate-50 text-black' : ''">
                            <Link rel="noopener noreferrer" href="/automation/bot-settings" class="flex items-center p-2 space-s-3 rounded-md">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446A9 9 0 1 1 12 3z"/><path d="M17 4a2 2 0 0 0 2 2a2 2 0 0 0-2 2a2 2 0 0 0-2-2a2 2 0 0 0 2-2"/></svg>
                                <div class="overflow-hidden text-ellipsis">{{ $t('Bot Behavior') }}</div>
                            </Link>
                        </li>
                        <li v-if="fbmodule && !$page.props.automationAiSuspended" class="hover:bg-slate-50 hover:text-black rounded-[5px] px-1 truncate cursor-pointer" :class="$page.url.startsWith('/automation/escalation') ? 'bg-slate-50 text-black' : ''">
                            <Link rel="noopener noreferrer" href="/automation/escalation" class="flex items-center p-2 space-s-3 rounded-md">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 2l5 5-5 5"/><path d="M12 7H9a5 5 0 0 0-5 5v2"/><path d="M17 2l5 5-5 5"/></svg>
                                <div class="overflow-hidden text-ellipsis">{{ $t('Escalation') }}</div>
                            </Link>
                        </li>
                        <li v-if="fbmodule" class="hover:bg-slate-50 hover:text-black rounded-[5px] px-1 truncate cursor-pointer" :class="$page.url.startsWith('/automation/flows') ? 'bg-slate-50 text-black' : ''">
                            <Link rel="noopener noreferrer" href="/automation/flows" class="flex items-center p-2 space-s-3 rounded-md">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 5c0-1.414 0-2.121.44-2.56C3.878 2 4.585 2 6 2s2.121 0 2.56.44C9 2.878 9 3.585 9 5s0 2.121-.44 2.56C8.122 8 7.415 8 6 8s-2.121 0-2.56-.44C3 7.122 3 6.415 3 5m0 14c0-1.414 0-2.121.44-2.56C3.878 16 4.585 16 6 16s2.121 0 2.56.44C9 16.878 9 17.585 9 19s0 2.121-.44 2.56C8.122 22 7.415 22 6 22s-2.121 0-2.56-.44C3 21.122 3 20.415 3 19M15 7c0-1.414 0-2.121.44-2.56C15.878 4 16.585 4 18 4s2.121 0 2.56.44C21 4.878 21 5.585 21 7s0 2.121-.44 2.56c-.439.44-1.146.44-2.56.44s-2.121 0-2.56-.44C15 9.122 15 8.415 15 7m-9 9V8m12 2a3 3 0 0 1-3 3H9a3 3 0 0 0-3 3" color="currentColor"/></svg>
                                <div class="overflow-hidden text-ellipsis">{{ $t('Automations') }}</div>
                            </Link>
                        </li>
                        <li v-if="fbmodule" class="hover:bg-slate-50 hover:text-black rounded-[5px] px-1 truncate cursor-pointer" :class="$page.url.startsWith('/automation/test') ? 'bg-slate-50 text-black' : ''">
                            <Link rel="noopener noreferrer" href="/automation/test" class="flex items-center p-2 space-s-3 rounded-md">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/><path d="M12 8v4l2 2"/></svg>
                                <div class="overflow-hidden text-ellipsis">{{ $t('Test') }}</div>
                            </Link>
                        </li>
                    </ul>
                </div>

                <div class="md:w-4/5 bg-white md:bg-inherit md:px-8 rounded-[5px] text-[#000] h-full md:overflow-y-auto">
                    <slot/>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./../Layout/App.vue";
    import { Link } from "@inertiajs/vue3";
    import { ref } from 'vue';
    import FormModal from '@/Components/FormModal.vue';
    import { trans } from 'laravel-vue-i18n';

    const props = defineProps(['fbmodule']);
</script>
