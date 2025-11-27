<template>
    <div :class="rtlClass">
        <MobileSidebar :user="user" :config="config" :organization="organization" :organizations="organizations"
            :title="currentPageTitle" :displayCreateBtn="displayCreateBtn" :displayTopBar="viewTopBar"></MobileSidebar>

        <div class="md:mt-0 md:pt-0 flex md:h-screen w-full tracking-[0.3px] bg-gray-300/10"
            :class="viewTopBar === false ? 'mt-0 pt-0' : ''">
            <Sidebar :user="user" :config="config" :organization="organization" :organizations="organizations"
                :unreadMessages="unreadMessages"></Sidebar>
            
           <div class="md:min-h-screen flex flex-col w-full min-w-0">
    
   <div class="md:min-h-screen flex flex-col w-full min-w-0">
<!--     
    <div v-if="isTrial == 1" 
         class="sticky top-0 z-50 bg-green-50 text-green-800 border-b border-green-200 py-3 px-4 shadow-md flex items-center justify-center space-x-2">
        
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 rtl:ml-2" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
        </svg>

        <span class="text-sm font-semibold">
           {{ $t('trial_alert.message') }}      
        </span>
        
            <a :href="whatsapp_link"
        target="_blank"
        class="bg-green-600 text-white text-xs font-bold py-1.5 px-4 rounded-lg hover:bg-green-700 transition duration-200 shadow-lg inline-flex items-center gap-2">

        {{ $t('trial_alert.cta') }}

        <svg xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 32 32"
            class="w-5 h-5 fill-white">
            <path d="M16.027 3C9.38 3 4 8.375 4 15.016c0 2.566.77 4.953 2.105 6.938L4 29l7.27-2.07A12.02 12.02 0 0 0 16.027 27C22.67 27 28 21.625 28 14.984 28 8.344 22.67 3 16.027 3zm5.918 17.402c-.25.703-1.46 1.36-2.02 1.453-.541.098-1.23.14-1.984-.123-.457-.156-1.043-.34-1.793-.668-3.155-1.37-5.207-4.57-5.36-4.785-.152-.215-1.28-1.705-1.28-3.253 0-1.55.807-2.316 1.094-2.63.285-.312.623-.39.832-.39.207 0 .416.003.598.01.193.008.448-.073.701.535.25.609.85 2.094.926 2.246.076.152.127.33.025.543-.102.215-.152.33-.305.512-.152.18-.32.4-.457.535-.152.152-.31.317-.133.623.18.312.797 1.312 1.715 2.125 1.18 1.047 2.176 1.375 2.49 1.527.316.152.5.127.684-.076.18-.203.785-.91.996-1.223.213-.312.406-.254.684-.152.278.102 1.758.828 2.06.977.304.152.508.228.582.355.07.13.07.75-.18 1.45z"/>
        </svg>
        </a>

    </div> -->
<div v-if="isTrial == 1" 
     class="fixed bottom-6 left-1/2 transform -translate-x-1/2 z-50 w-[90%] sm:w-auto">
    
    <div class="bg-white/90 backdrop-blur-md border border-green-200/60 rounded-2xl shadow-[0_8px_30px_rgb(0,0,0,0.12)] p-2 pr-4 pl-2 flex items-center justify-between sm:justify-center gap-4 transition-all hover:scale-[1.02] duration-300">
        
        <div class="flex items-center gap-3">
            <div class="bg-green-100/80 p-2 rounded-xl text-green-600 shadow-inner">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                </svg>
            </div>
            <span class="text-sm font-bold text-gray-700 whitespace-nowrap">
                {{ $t('trial_alert.message') }}
            </span>
        </div>

        <a :href="whatsapp_link" target="_blank"
           class="group bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-500 hover:to-emerald-500 text-white text-xs font-bold py-2 px-4 rounded-xl shadow-lg shadow-green-500/30 flex items-center gap-2 transition-all duration-300">
            <span>{{ $t('trial_alert.cta') }}</span>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" class="w-4 h-4 fill-white transition-transform duration-300 group-hover:rotate-12">
                <path d="M16.027 3C9.38 3 4 8.375 4 15.016c0 2.566.77 4.953 2.105 6.938L4 29l7.27-2.07A12.02 12.02 0 0 0 16.027 27C22.67 27 28 21.625 28 14.984 28 8.344 22.67 3 16.027 3zm5.918 17.402c-.25.703-1.46 1.36-2.02 1.453-.541.098-1.23.14-1.984-.123-.457-.156-1.043-.34-1.793-.668-3.155-1.37-5.207-4.57-5.36-4.785-.152-.215-1.28-1.705-1.28-3.253 0-1.55.807-2.316 1.094-2.63.285-.312.623-.39.832-.39.207 0 .416.003.598.01.193.008.448-.073.701.535.25.609.85 2.094.926 2.246.076.152.127.33.025.543-.102.215-.152.33-.305.512-.152.18-.32.4-.457.535-.152.152-.31.317-.133.623.18.312.797 1.312 1.715 2.125 1.18 1.047 2.176 1.375 2.49 1.527.316.152.5.127.684-.076.18-.203.785-.91.996-1.223.213-.312.406-.254.684-.152.278.102 1.758.828 2.06.977.304.152.508.228.582.355.07.13.07.75-.18 1.45z"/>
            </svg>
        </a>

    </div>
</div>

    <slot :user="user" :toggleNavBar="toggleTopBar" @testEmit="doSomething"></slot>
</div>

    <slot :user="user" :toggleNavBar="toggleTopBar" @testEmit="doSomething"></slot>
</div>
        </div>

        <audio ref="audioPlayer" allow="autoplay"></audio>
    </div>
</template>

<script setup>
    import { usePage } from "@inertiajs/vue3";
    import Sidebar from "./Sidebar.vue";
    import { defineProps, ref, computed, watch, onMounted } from 'vue';
    import { toast } from 'vue3-toastify';
    import MobileSidebar from "./MobileSidebar.vue";
    import 'vue3-toastify/dist/index.css';
    import { getEchoInstance } from '../../../echo';
    import { useRtl } from '@/Composables/useRtl';

    const { rtlClass, isRtl } = useRtl();

    const viewTopBar = ref(true);
    const user = computed(() => usePage().props.auth.user);
    const config = computed(() => usePage().props.config);
    const organization = computed(() => usePage().props.organization);
    const organizations = computed(() => usePage().props.organizations);
    const currentPageTitle = computed(() => usePage().props.title);
    const displayCreateBtn = computed(() => usePage().props.allowCreate);
    const unreadMessages = ref(usePage().props.unreadMessages);
    const isTrial = computed(() => usePage().props.is_trial); 
    const whatsapp_link = computed(() => usePage().props.whatsapp_link);
    const audioPlayer = ref(null);

    watch(() => [usePage().props.flash, { deep: true }], () => {
        if(usePage().props.flash.status != null){
            toast(usePage().props.flash.status.message, {
                autoClose: 3000,
            });
        }
    });

    const toggleTopBar = () => {
        viewTopBar.value = !viewTopBar.value;
    };

    const getValueByKey = (key) => {
        const found = config.value.find(item => item.key === key);
        return found ? found.value : '';
    };

    const setupSound = () => {
        const settings = organization.value.metadata ? JSON.parse(organization.value.metadata) : {};
        const notifications = settings.notifications || {};

        if (notifications?.enable_sound && audioPlayer.value) {
            audioPlayer.value.src = notifications?.tone;
            audioPlayer.value.volume = notifications?.volume || 1.0;
        }
    };

    const playSound = () => {
        if (audioPlayer.value) {
            audioPlayer.value.play().catch((error) => {
                console.warn("Audio playback failed:", error);
            });
        }
    };

    onMounted(() => {
        setupSound();
        const pusherKey = getValueByKey('pusher_app_key');
        const pusherCluster = getValueByKey('pusher_app_cluster');

        if (pusherKey && pusherCluster) {
             const echo = getEchoInstance(pusherKey, pusherCluster);
             
             echo.channel('chats.ch' + organization.value.id).listen('NewChatEvent', (event) => {
                const chat = event.chat;
                if (chat[0].value.deleted_at == null && chat[0].value.type === 'inbound') {
                    playSound();
                    unreadMessages.value += 1;
                }
            });
        }
    });
</script>