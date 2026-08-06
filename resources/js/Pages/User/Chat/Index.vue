<template>
  <AppLayout v-slot:default="slotProps">
    <div class="md:flex md:flex-grow md:overflow-visible">
      <div class="md:w-[30%] md:flex flex-col h-full bg-white border-r border-l" :class="contact ? 'hidden' : ''">
        <ChatTable :rows="rows" :filters="props.filters" :rowCount="props.rowCount"
          :ticketingIsEnabled="ticketingIsEnabled" :status="props?.status"
          :chatSortDirection="props.chat_sort_direction" :teamRole="props.teamRole"
                    :selectedContactUuid="contact?.uuid" :templates="templates" :contactFields="contactFields" />
      </div>
      <div class="min-w-0 bg-cover flex flex-col chat-bg"
        :class="contact ? 'h-screen md:w-[70%]' : 'md:h-screen md:w-[70%]'">
        <ChatHeader v-if="contact" :ticketingIsEnabled="ticketingIsEnabled" :contact="contact"
          :displayContactInfo="displayContactInfo" :labelColors="labelColors" :ticket="ticket" :addon="addon"
          :teamRole="props.teamRole" :currentPage="rows.meta?.current_page" @toggleView="toggleContactView"
          @deleteThread="deleteThread" @closeThread="closeThread" @labelsUpdated="handleLabelsUpdated" />
        <div v-if="contact && !displayTemplate" class="flex-1 overflow-y-auto" ref="scrollContainer2">
          <ChatThread v-if="!displayContactInfo && !loadingThread && !displayTemplate" :contactId="contact.id"
            :initialMessages="chatThread" :hasMoreMessages="hasMoreMessages" :initialNextPage="nextPage" />
          <Contact v-if="displayContactInfo && !displayTemplate" class="bg-white h-full" :fields="props.fields"
            :contact="contact" :locationSettings="props.locationSettings" />
        </div>
        <div v-if="
          contact && !displayContactInfo && !formLoading && !displayTemplate
        " class="w-full py-4">
          <ChatForm :contact="contact" :chatThread="chatThread" :simpleForm="simpleForm" :chatLimitReached="isChatLimitReached"
            @viewTemplate="displayTemplate = true" />
        </div>
        <div v-if="displayTemplate" class="flex-1 overflow-y-hidden">
          <div v-if="isContactBlocked" class="h-full bg-white flex items-center justify-center p-6">
            <div class="max-w-md text-center border border-red-200 bg-red-50 rounded-xl p-6">
              <h3 class="text-base font-semibold text-red-700 mb-2">{{ $t('Blocked contact') }}</h3>
              <p class="text-sm text-red-700 mb-4">{{ $t('This contact is blocked. Sending templates is disabled.') }}</p>
              <button type="button" class="rounded-md bg-white border border-red-300 px-4 py-2 text-sm text-red-700"
                @click="displayTemplate = false">
                {{ $t('Back') }}
              </button>
            </div>
          </div>
          <CampaignForm v-else class="bg-white h-full" :contact="contact.uuid" :templates="templates"
            :contactGroups="[]" :settings="props.settings" :displayCancelBtn="false" :displayTitle="true"
            :isCampaignFlow="false" :scheduleTemplate="false" :sendText="'Send Message'"
            @viewTemplate="displayTemplate = false" :contactFields="contactFields" />
        </div>
      </div>
    </div>
    <button class="hidden" ref="toggleNavbarBtn" @click="slotProps.toggleNavBar"></button>
  </AppLayout>
</template>
<script setup>
import AppLayout from "./../Layout/App.vue";
import { default as axios } from "axios";
import { router, useForm } from "@inertiajs/vue3";
import { useChatFilterStore } from '@/stores/chatFilterStore.js';
import { defineEmits, ref, onMounted, watch, onUnmounted, computed } from "vue";
import CampaignForm from "@/Components/CampaignForm.vue";
import ChatForm from "@/Components/ChatComponents/ChatForm.vue";
import ChatHeader from "@/Components/ChatComponents/ChatHeader.vue";
import ChatTable from "@/Components/ChatComponents/ChatTable.vue";
import ChatThread from "@/Components/ChatComponents/ChatThread.vue";
import ChatContact from "@/Components/ChatComponents/ChatContact.vue";
import Contact from "@/Components/ContactInfo.vue";
import { getEchoInstance } from "../../../echo";

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
  contactFields: {
    type: [Array, Object],
    default: () => [],
  },
  teamRole: String,
  labelColors: Array,
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
const contactFields = ref(props.contactFields);
const labelColors = ref(props.labelColors);
const isContactBlocked = computed(() => Boolean(contact.value?.is_blocked));


/* Chat Optimization Configuration */
const DEBUG_MODE = true; // Set to false for production

/* For Chat Optimizations */
let throttleTimer = null;
let pendingUpdate = false;
let pendingChats = [];
let isRequestInFlight = ref(false);
const THROTTLE_DELAY = 1000 * 30;
const BROADCAST_CHANNEL_NAME = `chat-sync-${props.organizationId}`;
let broadcastChannel = null;
let isLeaderTab = ref(false);
let leaderCheckInterval = null;
const LEADER_KEY = `chat-leader-${props.organizationId}`;
const LEADER_HEARTBEAT = 2000;
const TAB_ID = Math.random().toString(36).substring(2, 15);
let storageEventHandler = null;
let isComponentMounted = true;

// Debug logger
const debugLog = (...args) => {
  if (DEBUG_MODE) {
    console.log(...args);
  }
};

debugLog("Tab ID:", TAB_ID);

const becomeLeader = () => {
  isLeaderTab.value = true;
  const leaderData = JSON.stringify({
    timestamp: Date.now(),
    tabId: TAB_ID,
  });
  localStorage.setItem(LEADER_KEY, leaderData);
  debugLog("This tab is now the LEADER (ID:", TAB_ID + ")");
};

const checkLeadership = () => {
  const leaderData = localStorage.getItem(LEADER_KEY);

  if (!leaderData) {
    becomeLeader();
    return;
  }

  try {
    const parsed = JSON.parse(leaderData);

    // Migration: Check if old format (just a number/timestamp)
    if (typeof parsed === "number") {
      debugLog("Migrating old leader data format");
      localStorage.removeItem(LEADER_KEY);
      becomeLeader();
      return;
    }

    const { timestamp, tabId } = parsed;

    // Validate that both fields exist
    if (!timestamp || !tabId) {
      debugLog("Invalid leader data format, resetting");
      localStorage.removeItem(LEADER_KEY);
      becomeLeader();
      return;
    }

    const timeSinceLastHeartbeat = Date.now() - timestamp;

    // Check if WE are the leader
    if (tabId === TAB_ID) {
      if (!isLeaderTab.value) {
        isLeaderTab.value = true;
        debugLog("Confirmed as leader (ID:", TAB_ID + ")");
      }
      // Update our heartbeat
      localStorage.setItem(
        LEADER_KEY,
        JSON.stringify({
          timestamp: Date.now(),
          tabId: TAB_ID,
        })
      );
    } else {
      // Another tab is leader
      if (timeSinceLastHeartbeat > LEADER_HEARTBEAT * 2) {
        debugLog("Previous leader died (ID:", tabId + "), taking over");
        becomeLeader();
      } else {
        // Leader is alive, we're a follower
        if (isLeaderTab.value) {
          debugLog("Lost leadership to tab ID:", tabId);
        }
        isLeaderTab.value = false;
      }
    }
  } catch (e) {
    debugLog("Corrupted leader data, clearing and becoming leader");
    console.error("Parse error:", e);
    localStorage.removeItem(LEADER_KEY);
    becomeLeader();
  }
};

const setupLeaderElection = () => {
  // Add random delay (0-500ms) to reduce race condition on simultaneous opens
  const initialDelay = Math.random() * 500;

  setTimeout(() => {
    checkLeadership();

    // Check leadership every 2 seconds
    leaderCheckInterval = setInterval(checkLeadership, LEADER_HEARTBEAT);

    // Listen for storage changes (other tabs becoming leader)
    storageEventHandler = (e) => {
      if (e.key === LEADER_KEY) {
        debugLog("Leadership change detected");
        checkLeadership();
      }
    };

    window.addEventListener("storage", storageEventHandler);
  }, initialDelay);
};

watch(
  () => props.rows,
  (newRows) => {
    rows.value = newRows;
  }
);

watch(
  () => props.contact,
  (newContact) => {
    contact.value = newContact;

    if (newContact?.is_blocked && displayTemplate.value) {
      displayTemplate.value = false;
    }
  }
);

const toggleDropdown = () => {
  isOpen.value = !isOpen.value;
};

function toggleContactView(value) {
  displayContactInfo.value = value;
}

const scrollToBottom = () => {
  const container = scrollContainer2.value;
  if (container) {
    container.scrollTo({
      top: container.scrollHeight,
      behavior: "smooth",
    });
  }
};

const closeThread = () => {
  toggleNavbarBtn.value.click();

  // Preserve the current page in the URL when closing the thread so
  // the user returns to the same page they were browsing (e.g., page 10).
  try {
    const currentPage = rows.value?.meta?.current_page || 1;
    // Include active filters persisted in the shared store when closing the thread
    const filterStore = useChatFilterStore();
    let url = '/chats?page=' + currentPage;
    if (filterStore.labels) url += '&labels=' + filterStore.labels;
    if (filterStore.assigned_to) url += '&assigned_to=' + filterStore.assigned_to;
    if (filterStore.search) url += '&search=' + encodeURIComponent(filterStore.search);
    if (filterStore.status) url += '&status=' + encodeURIComponent(filterStore.status);
    if (filterStore.awaiting_reply) url += '&awaiting_reply=1';
    contact.value = null;
    router.visit(url, { replace: true, preserveState: true, preserveScroll: true });
  } catch (e) {
    // Fallback: just clear contact if routing fails
    contact.value = null;
    debugLog('Error preserving page on closeThread:', e);
  }
};

const deleteThread = async () => {
  chatThread.value = [];
  await axios.delete("/chats/" + contact.value.uuid);
};

const parseChatMetadata = (metadata) => {
  try {
    if (!metadata) return {};
    return typeof metadata === "string" ? JSON.parse(metadata) : metadata;
  } catch (e) {
    return {};
  }
};

const markRevokedMessageInThread = (chat) => {
  const incomingMetadata = parseChatMetadata(chat?.[0]?.value?.metadata);
  if (incomingMetadata.type !== "revoke") {
    return;
  }

  const originalMessageId = incomingMetadata.revoke?.original_message_id
    || incomingMetadata.raw?.revoke?.original_message_id;
  if (!originalMessageId) {
    return;
  }

  const originalIndex = chatThread.value.findIndex(
    (existingChat) => existingChat?.[0]?.value?.wam_id === originalMessageId
  );

  if (originalIndex === -1) {
    return;
  }

  const originalChat = chatThread.value[originalIndex];
  const originalValue = originalChat[0].value;
  const originalMetadata = parseChatMetadata(originalValue.metadata);

  originalMetadata.revoked = {
    is_revoked: true,
    revoked_at: incomingMetadata.timestamp || incomingMetadata.raw?.timestamp || Math.floor(Date.now() / 1000),
    revoke_wam_id: chat?.[0]?.value?.wam_id || incomingMetadata.revoke?.message_id || incomingMetadata.raw?.id || null,
  };

  chatThread.value.splice(originalIndex, 1, [{
    ...originalChat[0],
    value: {
      ...originalValue,
      metadata: JSON.stringify(originalMetadata),
    },
  }]);
};

const updateChatThread = (chat) => {
  markRevokedMessageInThread(chat);

  const wamId = chat[0].value.wam_id;
  const existingIndex = chatThread.value.findIndex(
    (existingChat) => existingChat[0].value.wam_id === wamId
  );

  if (existingIndex !== -1) {
    // Force Vue reactivity with splice
    chatThread.value.splice(existingIndex, 1, chat);
  } else if (chat[0].value.deleted_at == null) {
    chatThread.value.push(chat);
    setTimeout(scrollToBottom, 100);
  }
};

const handleLabelsUpdated = (updatedLabels) => {
  const normalizedLabels = Array.isArray(updatedLabels) ? updatedLabels : [];

  if (contact.value) {
    contact.value = {
      ...contact.value,
      labels: normalizedLabels,
    };
  }

  labelColors.value = normalizedLabels.map((label) => label.color);

  if (rows.value && Array.isArray(rows.value.data) && contact.value) {
    const updatedData = rows.value.data.map((row) => {
      if (row.uuid === contact.value.uuid) {
        return {
          ...row,
          labels: normalizedLabels,
        };
      }
      return row;
    });

    rows.value = {
      ...rows.value,
      data: updatedData,
    };
  }
};

const performChatUpdate = async () => {
  if (!isComponentMounted) {
    return;
  }
  if (!isLeaderTab.value) {
    debugLog("Not leader tab, skipping API call");
    pendingChats = [];
    return;
  }

  debugLog("Making API call (Throttled)");

  if (isRequestInFlight.value) {
    debugLog("Request already in flight, skipping");
    return;
  }

  isRequestInFlight.value = true;
  debugLog("Request locked");

  try {
    // Get active filters from the filter store
    const filterStore = useChatFilterStore();
    const params = {};

    // Only add filter parameters if they exist
    if (filterStore.search) params.search = filterStore.search;
    if (filterStore.labels) params.labels = filterStore.labels;
    if (filterStore.assigned_to) params.assigned_to = filterStore.assigned_to;
    if (filterStore.status) params.status = filterStore.status;
    if (filterStore.awaiting_reply) params.awaiting_reply = 1;

    debugLog("Fetching chats with filters:", params);

    const response = await axios.get("/chats", {
      params: params,
      headers: {
        Accept: "application/json",
      },
    });
    if (!isComponentMounted) {
      return;
    }
    debugLog("API call successful", response.data);

    // Check if server says to wait
    if (response.data.status === "wait") {
      debugLog("Server throttled request, waiting", response.data.wait_seconds);
      // Do nothing - just skip the update
      return;
    }

    if (response?.data?.result) {
      // Preserve current page to avoid jumping back to page 1 when new
      // messages arrive and the side panel is updated.
      const newResult = response.data.result;
      try {
        if (rows.value && rows.value.meta && rows.value.meta.current_page) {
          const currentPage = rows.value.meta.current_page;
          newResult.meta = newResult.meta || {};
          // Preserve the user's current page
          newResult.meta.current_page = currentPage;

          // If the user is NOT on page 1, keep the current page data intact
          // (don't replace the list shown) so new chats on page 1 don't
          // force the UI to show page 1.
          if (currentPage > 1 && rows.value.data) {
            newResult.data = rows.value.data;
          }
        }
      } catch (e) {
        debugLog('Error preserving page meta/data:', e);
      }

      rows.value = newResult;

      if (broadcastChannel) {
        try {
          // Broadcast the result that includes the preserved meta page
          broadcastChannel.postMessage({
            type: "ROWS_UPDATED",
            data: newResult,
            timestamp: Date.now(),
          });
          debugLog("Broadcasted to other tabs");
        } catch (e) {
          debugLog("BroadcastChannel post failed", e);
        }
      }
    }
  } catch (error) {
    console.error("Error updating side panel:", error);
  } finally {
    isRequestInFlight.value = false;
    debugLog("Request unlocked");
    pendingChats = [];
  }
};

const updateSidePanelThrottled = async () => {
  if (!isComponentMounted) {
    return;
  }
  // If we are already waiting on a timer, mark that we need another update when it finishes
  if (throttleTimer) {
    debugLog("Throttled - queuing update for later");
    pendingUpdate = true;
    return;
  }

  // Perform the update immediately
  await performChatUpdate();

  // Start the cooldown timer
  throttleTimer = setTimeout(() => {
    throttleTimer = null;
    if (!isComponentMounted) {
      pendingUpdate = false;
      return;
    }
    debugLog("Throttle timer finished");

    // If updates came in while we were waiting, run one more update now
    if (pendingUpdate) {
      debugLog("Executing queued update");
      pendingUpdate = false;
      updateSidePanelThrottled();
    }
  }, THROTTLE_DELAY);
};

const updateSidePanel = async (chat) => {
  if (!isComponentMounted) {
    return;
  }
  debugLog("New chat event received", chat[0]?.value);

  if (contact.value && contact.value.id == chat[0].value.contact_id) {
    updateChatThread(chat);
  }

  pendingChats.push(chat);
  debugLog("Pending chats:", pendingChats.length);
  updateSidePanelThrottled();
};

const onCloseDemoModal = () => {
  isDemoModalOpen.value = false;
};

const setupBroadcastChannel = () => {
  if ("BroadcastChannel" in window) {
    if (broadcastChannel) {
      broadcastChannel.close();
      broadcastChannel = null;
    }
    broadcastChannel = new BroadcastChannel(BROADCAST_CHANNEL_NAME);
    debugLog("BroadcastChannel created:", BROADCAST_CHANNEL_NAME);

    broadcastChannel.onmessage = (event) => {
      const { type, data, timestamp } = event.data;

      if (!isLeaderTab.value) {
        debugLog("Message received from LEADER tab:", type);
      }

      if (Date.now() - timestamp < 2000) {
        if (type === "ROWS_UPDATED") {
          // Preserve current page and page data when receiving broadcasted rows
          const incoming = data;
          try {
            if (rows.value && rows.value.meta && rows.value.meta.current_page && incoming && incoming.meta) {
              const currentPage = rows.value.meta.current_page;
              incoming.meta.current_page = currentPage;

              if (currentPage > 1 && rows.value.data) {
                incoming.data = rows.value.data;
              }
            }
          } catch (e) {
            debugLog('Error preserving page on broadcast receive:', e);
          }

          rows.value = incoming;
          debugLog("Rows updated from broadcast");
        }
      } else {
        debugLog("Message too old, ignoring");
      }
    };
  } else {
    console.warn(
      "BroadcastChannel not supported - each tab will make API calls"
    );
    // Fallback: disable leader election, let all tabs make calls
    isLeaderTab.value = true;
  }
};

onMounted(() => {
  // Migration: Clear old leader data format on mount
  try {
    const leaderData = localStorage.getItem(LEADER_KEY);
    if (leaderData) {
      const parsed = JSON.parse(leaderData);
      if (typeof parsed === "number") {
        debugLog("Clearing old leader data format on mount");
        localStorage.removeItem(LEADER_KEY);
      }
    }
  } catch (e) {
    debugLog("Clearing corrupted leader data on mount");
    localStorage.removeItem(LEADER_KEY);
  }

  // Setup leader election FIRST
  setupLeaderElection();

  // Setup cross-tab communication
  setupBroadcastChannel();

  const echo = getEchoInstance(
    props.pusherSettings["pusher_app_key"],
    props.pusherSettings["pusher_app_cluster"]
  );

  echo
    .channel("chats.ch" + props.organizationId)
    .listen("NewChatEvent", (event) => {
      updateSidePanel(event.chat);
    });

  scrollToBottom();
});

onUnmounted(() => {
  isComponentMounted = false;
  // Cleanup throttle timer
  if (throttleTimer) {
    clearTimeout(throttleTimer);
  }

  // Cleanup leader check interval
  if (leaderCheckInterval) {
    clearInterval(leaderCheckInterval);
  }

  // Remove storage event listener
  if (storageEventHandler) {
    window.removeEventListener("storage", storageEventHandler);
  }

  // If we're the leader, clear the flag so another tab can take over
  if (isLeaderTab.value) {
    localStorage.removeItem(LEADER_KEY);
    debugLog("Leader tab closed, releasing leadership");
  }

  // Close broadcast channel
  if (broadcastChannel) {
    broadcastChannel.close();
    broadcastChannel = null;
  }
});
</script>
