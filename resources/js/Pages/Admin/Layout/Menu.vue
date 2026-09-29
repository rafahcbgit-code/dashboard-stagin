<template>
    <div class="flex items-center justify-between px-5 pt-5 h-20 mb-2">
        <Link href="/admin/dashboard" class="text-2xl font-semibold">{{ getValueByKey('company_name') }}</Link>
        <LangToggle class="text-black" :languages="languages" :currentLanguage="currentLanguage" />
    </div>

    <div class="flex-grow space-y-3 px-2 overflow-y-scroll">
        <ul class="space-y-1 text-sm">
            <template v-for="item in primaryItems" :key="item.href">
                <li v-if="can(item.module, 'view')" class="rounded-[5px] px-2 hover:bg-slate-50">
                    <Link :href="item.href" class="flex items-center p-2 space-x-3 rounded-md" :class="isActive(item.href) ? 'bg-slate-50 text-black' : ''" @click="closeSidebar">
                        <span>{{ $t(item.label) }}</span>
                    </Link>
                </li>
            </template>
        </ul>

        <div class="px-4"><hr></div>

        <ul class="space-y-1 text-sm">
            <template v-for="item in secondaryItems" :key="item.href">
                <li v-if="can(item.module, item.action ?? 'view')" class="rounded-[5px] px-2 hover:bg-slate-50">
                    <Link :href="item.href" class="flex items-center p-2 space-x-3 rounded-md" :class="isActive(item.href) ? 'bg-slate-50 text-black' : ''" @click="closeSidebar">
                        <span>{{ $t(item.label) }}</span>
                    </Link>
                </li>
            </template>
        </ul>
    </div>

    <Link v-if="can('addons', 'view')" href="/admin/addons" class="border-2 border-primary text-sm rounded-[5px] mb-1 m-3 py-2 px-4 flex items-center justify-between" @click="closeSidebar">
        <span>{{ $t('Addons') }}</span>
        <span>›</span>
    </Link>

    <div class="flex items-center m-3 p-2 rounded-xl h-14 space-x-4 justify-between bg-slate-50">
        <div class="flex space-x-2 items-center">
            <img v-if="user.avatar" class="rounded-full w-9 h-9" :src="'/media/' + user.avatar">
            <div v-else class="rounded-full w-9 h-9 bg-slate-200 flex items-center justify-center">{{ user.first_name?.[0] }}</div>
            <div class="text-[15px] capitalize truncate w-[8em]">{{ user.first_name }} {{ user.last_name }}</div>
        </div>
        <Link href="/logout" class="p-2" aria-label="Logout">↪</Link>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import LangToggle from '@/Components/LangToggle.vue';
import { useAdminPermissions } from '@/Composables/useAdminPermissions';

const props = defineProps(['config', 'user', 'isSidebarOpen']);
const emit = defineEmits(['closeSidebar']);
const { can } = useAdminPermissions();
const page = usePage();
const languages = computed(() => page.props.languages);
const currentLanguage = computed(() => page.props.currentLanguage);

const primaryItems = [
    { href: '/admin/dashboard', label: 'Dashboard', module: 'dashboard' },
    { href: '/admin/organizations', label: 'Organizations', module: 'organizations' },
    { href: '/admin/chatbot-studio', label: 'Chatbot Studio', module: 'organizations' },
    { href: '/admin/users', label: 'Users', module: 'customers' },
    { href: '/admin/payment-logs', label: 'Billing', module: 'billing' },
    { href: '/admin/support', label: 'Support desk', module: 'support' },
    { href: '/admin/team/users', label: 'Team', module: 'team' },
    { href: '/admin/otp-config', label: 'Otp Config', module: 'otp_config' },
];

const secondaryItems = [
    { href: '/admin/team/roles', label: 'Roles', module: 'roles' },
    { href: '/admin/plans', label: 'Subscription plans', module: 'subscription_plans' },
    { href: '/admin/resource-addons', label: 'Resource add-ons', module: 'resource_addons' },
    { href: '/admin/faqs', label: 'FAQs', module: 'settings', action: 'frontend' },
    { href: '/admin/testimonials', label: 'Reviews', module: 'settings', action: 'frontend' },
    { href: '/admin/settings/general', label: 'Settings', module: 'settings', action: 'general' },
    { href: '/admin/settings/managed-notifications', label: 'Managed notifications', module: 'notifications', action: 'view' },
    { href: '/admin/notifications', label: 'Notification inbox', module: 'notifications', action: 'view' },
    { href: '/admin/salla/app-installations', label: 'Salla App Installations', module: 'logs' },
];

const getValueByKey = (key) => props.config.find(item => item.key === key)?.value ?? '';
const isActive = (href) => page.url.startsWith(href);
const closeSidebar = () => emit('closeSidebar', true);
</script>
