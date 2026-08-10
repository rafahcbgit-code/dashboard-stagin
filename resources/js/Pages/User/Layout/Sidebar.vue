<template>
    <aside class="md:flex flex-col h-full bg-white hidden relative md:sticky md:top-0 md:h-screen" :class="menuIconsOnly ? 'w-20' : 'w-80'">
        <button @click="toggleMenu"
            class="absolute end-[-18px] top-[32px] bg-slate-100 px-2 py-2 rounded-full shadow-md z-10">
            <svg v-if="menuIconsOnly" xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 256 256">
                <path fill="currentColor"
                    d="m144.49 136.49l-80 80a12 12 0 0 1-17-17L119 128L47.51 56.49a12 12 0 0 1 17-17l80 80a12 12 0 0 1-.02 17m80-17l-80-80a12 12 0 1 0-17 17L199 128l-71.52 71.51a12 12 0 0 0 17 17l80-80a12 12 0 0 0 .01-17Z" />
            </svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 256 256">
                <path fill="currentColor"
                    d="M208.49 199.51a12 12 0 0 1-17 17l-80-80a12 12 0 0 1 0-17l80-80a12 12 0 0 1 17 17L137 128ZM57 128l71.52-71.51a12 12 0 0 0-17-17l-80 80a12 12 0 0 0 0 17l80 80a12 12 0 0 0 17-17Z" />
            </svg>
        </button>
        <Menu :config="props.config" :user="props.user" :unreadMessages="unreadMessages" :unreadNotifications="unreadNotifications"
            :organization="props.organization" :organizations="props.organizations" :menuIconsOnly="menuIconsOnly">
        </Menu>
    </aside>
</template>
<script setup>
import { Link, useForm } from "@inertiajs/vue3";
import { defineProps, ref, onMounted, onUnmounted } from "vue";
import axios from "axios";
import Menu from "./Menu.vue";

const props = defineProps(['user', 'organization', 'organizations', 'config', 'unreadMessages']);

const unreadNotifications = ref(0);

onMounted(async () => {
    try {
        const response = await axios.get('/notifications/unread-count');
        unreadNotifications.value = response.data.count || 0;
    } catch (e) {
        // Silently fail — notification count is non-critical
    }
});

const incrementUnreadNotifications = () => {
    unreadNotifications.value = Number(unreadNotifications.value || 0) + 1;
};

onMounted(() => window.addEventListener('notification:received', incrementUnreadNotifications));
onUnmounted(() => window.removeEventListener('notification:received', incrementUnreadNotifications));

const menuIconsOnly = ref(localStorage.getItem('MenuOpen') === 'true' ?? false);

const toggleMenu = () => {
    menuIconsOnly.value = !menuIconsOnly.value;
    localStorage.setItem('MenuOpen', menuIconsOnly.value)
}
</script>
