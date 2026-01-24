<script setup>
    import { ref, onMounted, onUnmounted } from 'vue';
    import { Link, router } from "@inertiajs/vue3";
    import axios from 'axios';

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
    const isMarkingAllRead = ref(false);

    const toggleDropdown = () => {
        isOpen.value = !isOpen.value;
    }

    const markAllAsRead = async () => {
        if (isMarkingAllRead.value) return;
        
        if (!confirm('Are you sure you want to mark all messages as read?')) {
            return;
        }
        
        isMarkingAllRead.value = true;
        try {
            const response = await axios.post('/chats/mark-all-read');
            if (response.data.success) {
                // Refresh the current page
                router.reload();
            }
        } catch (error) {
            console.error('Error marking all as read:', error);
            alert('Failed to mark all messages as read');
        } finally {
            isMarkingAllRead.value = false;
        }
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
                <button @click="markAllAsRead" 
                        :disabled="isMarkingAllRead"
                        class="block w-full text-left px-2 py-1 cursor-pointer hover:bg-slate-100 rounded-md disabled:opacity-50 disabled:cursor-not-allowed">
                    <div class="flex items-center space-x-2">
                        <svg v-if="isMarkingAllRead" class="animate-spin w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <svg v-else xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
                            <path fill="currentColor" d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1s1 .45 1 1s-.45 1-1 1zm-1-9H6v4h12V3z"/>
                        </svg>
                        <span>{{ $t('Mark All Read') }}</span>
                    </div>
                </button>
            </div>
        </div>
    </div>
</template>