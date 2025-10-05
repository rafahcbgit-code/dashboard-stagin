<template>
    <AppLayout v-slot:default="slotProps">
        <div class="md:flex md:flex-grow md:overflow-visible">
            <div class="md:w-[30%] md:flex flex-col h-full bg-white border-r border-l" :class="contact ? 'hidden' : ''">
                <ChatTable :rows="rows" :filters="props.filters" :rowCount="props.rowCount" :ticketingIsEnabled="ticketingIsEnabled" :status="props?.status" :chatSortDirection="props.chat_sort_direction"/>
            </div>
            <div class="min-w-0 bg-cover flex flex-col chat-bg" :class="contact ? 'h-screen md:w-[70%]' : 'md:h-screen md:w-[70%]'">
                <ChatHeader 
                    v-if="contact" 
                    :ticketingIsEnabled="ticketingIsEnabled" 
                    :contact="contact" 
                    :displayContactInfo="displayContactInfo" 
                    :ticket="ticket" 
                    :addon="addon" 
                    :teamRole="props.teamRole"
                    @toggleView="toggleContactView" 
                    @deleteThread="deleteThread" 
                    @closeThread="closeThread"
                />
                <div v-if="contact && !displayTemplate" class="flex-1 overflow-y-auto" ref="scrollContainer2">
                    <ChatThread 
                        v-if="!displayContactInfo && !loadingThread && !displayTemplate"
                        :contactId="contact.id"
                        :initialMessages="chatThread"
                        :hasMoreMessages="hasMoreMessages"
                        :initialNextPage="nextPage"
                    />
                    <Contact 
                        v-if="displayContactInfo && !displayTemplate" 
                        class="bg-white h-full" 
                        :fields="props.fields" 
                        :contact="contact" 
                        :locationSettings="props.locationSettings" 
                    />
                </div>
                <div v-if="contact && !displayContactInfo && !formLoading && !displayTemplate" class="w-full py-4">
                    <ChatForm 
                        :contact="contact" 
                        :simpleForm="simpleForm" 
                        :chatLimitReached="isChatLimitReached" 
                        @viewTemplate="displayTemplate = true;" 
                    />
                </div>
                <div v-if="displayTemplate" class="flex-1 overflow-y-hidden">
                    <CampaignForm 
                        v-if="displayTemplate" 
                        class="bg-white h-full" 
                        :contact="contact.uuid" 
                        :templates="templates" 
                        :contactGroups="[]" 
                        :settings="props.settings" 
                        :displayCancelBtn="false" 
                        :displayTitle="true" 
                        :isCampaignFlow="false" 
                        :scheduleTemplate="false" 
                        :sendText="'Send Message'" 
                        @viewTemplate="displayTemplate = false;"
                    />
                </div>
            </div>
            <!--<div v-if="contact" class="md:w-[25%] min-w-0 bg-cover flex flex-col bg-white border-l">
                <ChatContact v-if="contact" class="bg-white h-full" :contact="contact" />
            </div>-->
        </div>
        <button class="hidden" ref="toggleNavbarBtn" @click="slotProps.toggleNavBar"></button>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./../Layout/App.vue";
    import { default as axios } from 'axios';
    import { router, useForm } from '@inertiajs/vue3';
    import { defineEmits, ref, onMounted, watch, onUnmounted } from 'vue';
    import CampaignForm from '@/Components/CampaignForm.vue';
    import ChatForm from '@/Components/ChatComponents/ChatForm.vue';
    import ChatHeader from '@/Components/ChatComponents/ChatHeader.vue';
    import ChatTable from '@/Components/ChatComponents/ChatTable.vue';
    import ChatThread from '@/Components/ChatComponents/ChatThread.vue';
    import ChatContact from '@/Components/ChatComponents/ChatContact.vue';
    import Contact from '@/Components/ContactInfo.vue';
    import { getEchoInstance } from '../../../echo';

    const props = defineProps({
        rows: Array,
        rowCount: Number,
        pusherSettings: Object,
        organizationId: Number,
        isChatLimitReached: Boolean,
        toggleNavBar: Function,
        state: String,
        demoNumber: String,
        settings: Object,
        status: String,
        chatThread: Array,
        hasMoreMessages: Boolean,
        nextPage: Number,
        addon: Object,
        contact: Object,
        ticket: Object,
        chat_sort_direction: String,
        filters: Object,
        templates: Array,
        fields: Array,
        locationSettings: Object,
        simpleForm: Boolean,
        teamRole: String
    });

    const rows = ref(props.rows);
    const rowCount = ref(props.rowCount);
    const scrollContainer2 = ref(null);
    const loadingThread = ref(false);
    const displayContactInfo = ref(false);
    const displayTemplate = ref(false);
    const formLoading = ref(false);
    const isChatLimitReached = ref(props.isChatLimitReached);
    const toggleNavbarBtn = ref(null);
    const config = ref(props.settings.metadata);
    const settings = ref(config.value ? JSON.parse(config.value) : null);
    const ticketingIsEnabled = ref(settings.value?.tickets?.active ?? false);
    const chatThread = ref(props.chatThread);
    const contact = ref(props.contact);

    /* For Chat Optimizations */
    let updateDebounceTimer = null;
    let pendingChats = [];
    let isRequestInFlight = ref(false);
    const DEBOUNCE_DELAY = 1000;
    const BROADCAST_CHANNEL_NAME = `chat-sync-${props.organizationId}`;
    let broadcastChannel = null;
    let isLeaderTab = ref(false);
    let leaderCheckInterval = null;
    const LEADER_KEY = `chat-leader-${props.organizationId}`;
    const LEADER_HEARTBEAT = 2000; // 2 seconds

    const becomeLeader = () => {
        isLeaderTab.value = true;
        localStorage.setItem(LEADER_KEY, Date.now().toString());
        console.log('👑 This tab is now the LEADER');
    }

    const checkLeadership = () => {
        const leaderTimestamp = localStorage.getItem(LEADER_KEY);
        
        if (!leaderTimestamp) {
            // No leader exists, become leader
            becomeLeader();
            return;
        }
        
        const timeSinceLastHeartbeat = Date.now() - parseInt(leaderTimestamp);
        
        if (timeSinceLastHeartbeat > LEADER_HEARTBEAT * 2) {
            // Leader is dead, take over
            console.log('💀 Previous leader died, taking over');
            becomeLeader();
        } else if (isLeaderTab.value) {
            // Update heartbeat if we're the leader
            localStorage.setItem(LEADER_KEY, Date.now().toString());
        }
    }

    const setupLeaderElection = () => {
        checkLeadership();
        
        // Check leadership every 2 seconds
        leaderCheckInterval = setInterval(checkLeadership, LEADER_HEARTBEAT);
        
        // Listen for storage changes (other tabs becoming leader)
        window.addEventListener('storage', (e) => {
            if (e.key === LEADER_KEY) {
                const wasLeader = isLeaderTab.value;
                checkLeadership();
                
                if (wasLeader && !isLeaderTab.value) {
                    console.log('👋 Lost leadership to another tab');
                }
            }
        });
    }

    watch(() => props.rows, (newRows) => {
        rows.value = newRows;
    });

    const toggleDropdown = () => {
        isOpen.value = !isOpen.value;
    }

    function toggleContactView(value) {
        displayContactInfo.value = value;
    }

    const scrollToBottom = () => {
        const container = scrollContainer2.value;
        if (container) {
            container.scrollTo({
                top: container.scrollHeight,
                behavior: 'smooth',
            });
        }
    };

    const closeThread = () => {
        toggleNavbarBtn.value.click();
        contact.value = null;
    }

    const deleteThread = async () => {
        chatThread.value = [];
        await axios.delete('/chats/' + contact.value.uuid);
    }

    const updateChatThread = (chat) => {
        const wamId = chat[0].value.wam_id;
        const wamIdExists = chatThread.value.some(existingChat => existingChat[0].value.wam_id === wamId);

        if (!wamIdExists && chat[0].value.deleted_at == null) {
            chatThread.value.push(chat);
            setTimeout(scrollToBottom, 100);
        }
    }

    const updateSidePanelDebounced = async() => {
    if (updateDebounceTimer) {
        clearTimeout(updateDebounceTimer);
        console.log('⏱️ Timer reset - debouncing active');
    }

    updateDebounceTimer = setTimeout(async () => {
        if (!isLeaderTab.value) {
            console.log('⏭️ Not leader tab, skipping API call');
            pendingChats = []; // Clear pending
            return;
        }
        console.log('🚀 Making API call after debounce delay');
        if (isRequestInFlight.value) {
            console.log('⚠️ Request already in flight, skipping');
            return;
        }

        isRequestInFlight.value = true;
        console.log('🔒 Request locked');

        try {
            const response = await axios.get('/chats');
            console.log('✅ API call successful', response.data);
            if (response?.data?.result) {
                rows.value = response.data.result;
                
                if (broadcastChannel) {
                    broadcastChannel.postMessage({
                        type: 'ROWS_UPDATED',
                        data: response.data.result,
                        timestamp: Date.now()
                    });
                    console.log('📢 Broadcasted to other tabs');
                }
            }
        } catch (error) {
            console.error('❌ Error updating side panel:', error);
        } finally {
            isRequestInFlight.value = false;
            console.log('🔓 Request unlocked');
            pendingChats = [];
        }
    }, DEBOUNCE_DELAY);
}

    const updateSidePanel = async(chat) => {
        console.log('📨 New chat event received', chat[0]?.value);
        if(contact.value && contact.value.id == chat[0].value.contact_id){
            updateChatThread(chat);
        }

        pendingChats.push(chat);
        console.log('📋 Pending chats:', pendingChats.length);
        updateSidePanelDebounced();
    }

    const onCloseDemoModal = () => {
        isDemoModalOpen.value = false;
    }

    const setupBroadcastChannel = () => {
        if ('BroadcastChannel' in window) {
            broadcastChannel = new BroadcastChannel(BROADCAST_CHANNEL_NAME);
            console.log('📡 BroadcastChannel created:', BROADCAST_CHANNEL_NAME);
            
            broadcastChannel.onmessage = (event) => {
                const { type, data, timestamp } = event.data;
                
                // Non-leader tabs should always listen
                if (!isLeaderTab.value) {
                    console.log('📥 Message received from LEADER tab:', type);
                }
                
                if (Date.now() - timestamp < 2000) {
                    if (type === 'ROWS_UPDATED') {
                        rows.value = data;
                        console.log('✨ Rows updated from broadcast');
                    }
                } else {
                    console.log('⏰ Message too old, ignoring');
                }
            };
        } else {
            console.warn('⚠️ BroadcastChannel not supported');
        }
    }

    onMounted(() => {
        // Setup leader election FIRST
        setupLeaderElection();
        // Setup cross-tab communication
        setupBroadcastChannel();

        const echo = getEchoInstance(
            props.pusherSettings['pusher_app_key'],
            props.pusherSettings['pusher_app_cluster']
        );

        echo.channel('chats.ch' + props.organizationId)
            .listen('NewChatEvent', (event) => {
                updateSidePanel(event.chat);
            });

        scrollToBottom();
    });

    onUnmounted(() => {
        if (updateDebounceTimer) {
            clearTimeout(updateDebounceTimer);
        }
        
        if (leaderCheckInterval) {
            clearInterval(leaderCheckInterval);
        }
        
        // If we're the leader, clear the flag so another tab can take over
        if (isLeaderTab.value) {
            localStorage.removeItem(LEADER_KEY);
            console.log('👋 Leader tab closed, releasing leadership');
        }
        
        if (broadcastChannel) {
            broadcastChannel.close();
        }
    });
</script>