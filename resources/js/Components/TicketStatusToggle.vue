<script setup>
    import { ref, onMounted, onUnmounted } from 'vue';
    import { Link } from "@inertiajs/vue3";

    import { useChatFilterStore, setChatFilters } from '@/stores/chatFilterStore.js';

    const props = defineProps({
        languages: Object,
        currentLanguage: String,
        status: String,
        rowCount: Number,
        teamRole: {
            type: String,
            default: 'owner'
        }
    })

    const isOpen = ref(false);

    const toggleDropdown = () => {
        isOpen.value = !isOpen.value;
    }

    const handleClickOutside = (event) => {
        if (isOpen.value && !event.target.closest('.status-dd')) {
            isOpen.value = false;
        }
    }

    const capitalizeString = (str) => {
        // Check if the string is empty or null
        if (!str) return '';
        
        // Capitalize the first character and concatenate it with the rest of the string
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    onMounted(() => {
        document.body.addEventListener('click', handleClickOutside);
    });

    onUnmounted(() => {
        document.body.removeEventListener('click', handleClickOutside);
    });

    const buildStatusHref = (status) => {
        const filterStore = useChatFilterStore();
        const qs = new URLSearchParams();
        if (filterStore.labels) qs.set('labels', filterStore.labels);
        if (filterStore.assigned_to) qs.set('assigned_to', filterStore.assigned_to);
        if (filterStore.search) qs.set('search', filterStore.search);
        if (status) qs.set('status', status);
        const str = qs.toString();
        return '/chats' + (str ? ('?' + str) : '');
    }
</script>
<template>
    <div class="relative text-sm">
        <div @click="toggleDropdown()" class="status-dd">
            <div class="cursor-pointer flex items-center space-x-1 bg-slate-50 w-[fit-content] h-[fit-content] rounded-md p-1 px-2 text-sm">
                <span class="capitalize">{{ $t(capitalizeString(props.status)) }}</span>
                <span>
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" fill-rule="evenodd" d="M16.53 8.97a.75.75 0 0 1 0 1.06l-4 4a.75.75 0 0 1-1.06 0l-4-4a.75.75 0 1 1 1.06-1.06L12 12.44l3.47-3.47a.75.75 0 0 1 1.06 0" clip-rule="evenodd"/></svg>
                </span>
            </div>
        </div>
        <div v-if="isOpen" class="absolute bg-white z-10 px-1 py-2 mt-2 shadow w-full rounded-md text-black">
            <div>
                <Link 
                    v-if="teamRole === 'agent'" 
                    :href="buildStatusHref('assigned_to_me')" 
                    class="block px-2 py-1 cursor-pointer hover:bg-slate-100 rounded-md"
                >
                    <div class="flex items-center space-x-2">
                        <span>{{ $t('Assigned to Me') }}</span>
                    </div>
                </Link>

                <Link :href="buildStatusHref('unassigned')" class="block px-2 py-1 cursor-pointer hover:bg-slate-100 rounded-md">
                    {{ $t('Unassigned')}} 
                </Link>
                <Link :href="buildStatusHref('open')" class="block px-2 py-1 cursor-pointer hover:bg-slate-100 rounded-md">
                    {{ $t('Open')}} 
                </Link>
                <Link :href="buildStatusHref('closed')" class="block px-2 py-1 cursor-pointer hover:bg-slate-100 rounded-md">
                    {{ $t('Closed')}} 
                </Link>
                <Link :href="buildStatusHref('all')" class="block px-2 py-1 cursor-pointer hover:bg-slate-100 rounded-md">
                    {{ $t('All')}} 
                </Link>
                <Link :href="buildStatusHref('unread')" class="block px-2 py-1 cursor-pointer hover:bg-slate-100 rounded-md">
                    {{ $t('Unread Only')}} 
                </Link>
            </div>
        </div>
    </div>
</template>