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
    
    <div v-if="isTrial == 1" 
         class="sticky top-0 z-50 bg-green-50 text-green-800 border-b border-green-200 py-3 px-4 shadow-md flex items-center justify-center space-x-2">
        
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 rtl:ml-2" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
        </svg>

        <span class="text-sm font-semibold">
           {{ $t('trial_alert.message') }}        </span>
        
        <a href="https://wa.me/966542922948" 
           class="bg-green-600 text-white text-xs font-bold py-1.5 px-4 rounded-lg hover:bg-green-700 transition duration-200 shadow-lg">
           {{ $t('trial_alert.cta') }}
        </a>
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
    
    // ================= بداية التعديل: استقبال المتغير =================
    // نفترض أنك مررت المتغير من الميدلوير باسم is_trial
    const isTrial = computed(() => usePage().props.is_trial); 
    // ================= نهاية التعديل =================

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

        // التأكد من وجود المفاتيح قبل محاولة الاتصال بـ Echo لتجنب الأخطاء
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