<template>
  <Layout :fbmodule="fbmodule">
    <div class="flex flex-col items-center">
      <!-- Header -->
      <div class="w-full flex items-center justify-between mb-4">
        <div>
          <h3 class="text-lg font-semibold text-gray-900">Automation Test</h3>
          <p class="text-sm text-gray-500 mt-0.5">Test how your automations respond. No real messages are sent.</p>
        </div>
        <div class="flex items-center gap-3">
          <div class="flex items-center gap-3 text-xs text-gray-500">
            <span :class="automationEnabled ? 'text-green-600' : 'text-red-600'">{{ automationEnabled ? 'Automation On' : 'Automation Off' }}</span>
            <span :class="withinWorkingHours ? 'text-green-600' : 'text-amber-600'">{{ withinWorkingHours ? 'Working Hours' : 'Outside Hours' }}</span>
            <span v-if="aimodule" :class="aiEnabled ? 'text-green-600' : 'text-gray-400'">{{ aiEnabled ? 'AI On' : 'AI Off' }}</span>
          </div>
          <button @click="showDebug = !showDebug" class="text-sm text-gray-500 hover:text-gray-700 flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
            {{ showDebug ? 'Hide' : 'Show' }} Debug
          </button>
        </div>
      </div>

      <!-- Context toggles -->
      <div class="w-full flex flex-wrap items-center gap-5 mb-5 px-1">
        <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
          <input type="checkbox" v-model="isNewContact" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500" />
          <span class="text-gray-700">New contact</span>
        </label>
        <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
          <input type="checkbox" v-model="duringWorkingHours" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500" />
          <span class="text-gray-700">During working hours</span>
        </label>
        <button v-if="chatMessages.length > 0" @click="resetChat" class="ml-auto text-xs text-gray-400 hover:text-red-500 transition-colors flex items-center gap-1">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
          Reset
        </button>
      </div>

      <!-- Phone mockup — centered -->
      <div class="flex flex-col items-center select-none">
        <div class="w-[300px] bg-[#1a1a2e] rounded-[2.2rem] p-2.5 shadow-2xl border border-[#2a2a4e]">
          <!-- Status bar -->
          <div class="flex items-center justify-between px-4 py-1 mb-1">
            <span class="text-white text-[9px] font-medium">9:41</span>
            <div class="flex items-center gap-1">
              <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M1 9l2 2c4.97-4.97 13.03-4.97 18 0l2-2C16.93 2.93 7.08 2.93 1 9zm8 8l3 3 3-3c-1.65-1.66-4.34-1.66-6 0zm-4-4l2 2c2.76-2.76 7.24-2.76 10 0l2-2C15.14 9.14 8.87 9.14 5 13z"/></svg>
              <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M17 4h-1V2h-2v2H10V2H8v2H7c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H7V9h10v11z"/></svg>
            </div>
          </div>

          <!-- Screen area -->
          <div class="bg-[#ECE5DD] rounded-[1.7rem] overflow-hidden" style="height: 500px; display: flex; flex-direction: column;">
            <!-- WhatsApp header -->
            <div class="bg-[#034737] flex items-center px-3 py-2 gap-2 shrink-0">
              <div class="w-7 h-7 bg-[#25D366] rounded-full flex items-center justify-center">
                <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3z"/></svg>
              </div>
              <div class="flex-1 min-w-0">
                <div class="text-white text-xs font-semibold truncate">{{ orgName }}</div>
                <div class="text-green-200 text-[10px]">online</div>
              </div>
            </div>

            <!-- Chat messages -->
            <div ref="chatArea" class="flex-1 overflow-y-auto px-2 py-2 space-y-1.5" style="scroll-behavior: smooth;">
              <!-- Empty state -->
              <div v-if="chatMessages.length === 0" class="text-center py-8 px-4">
                <div class="inline-block bg-white/80 rounded-lg px-3 py-2 text-[11px] text-gray-500 shadow-sm">Type a message to test your automations</div>
              </div>

              <template v-for="(msg, idx) in chatMessages" :key="idx">
                <!-- Bot message -->
                <div v-if="msg.sender === 'bot'" class="mr-auto max-w-[230px]">
                  <div class="rounded-lg rounded-tl-none bg-white p-1.5 text-[13px] text-gray-800 relative speech-bubble-left shadow-sm">
                    <!-- Text -->
                    <div v-if="msg.whatsapp?.type === 'text'" class="whitespace-pre-wrap">{{ msg.whatsapp.body }}</div>

                    <!-- Interactive buttons - body only -->
                    <div v-else-if="msg.whatsapp?.type === 'interactive_buttons'">
                      <div v-if="msg.whatsapp.header" class="font-semibold text-[13px] mb-1">{{ msg.whatsapp.header }}</div>
                      <div class="whitespace-pre-wrap">{{ msg.whatsapp.body }}</div>
                      <div v-if="msg.whatsapp.footer" class="text-[11px] text-gray-400 mt-1">{{ msg.whatsapp.footer }}</div>
                    </div>

                    <!-- Interactive list - body only -->
                    <div v-else-if="msg.whatsapp?.type === 'interactive_list'">
                      <div v-if="msg.whatsapp.header" class="font-semibold text-[13px] mb-1">{{ msg.whatsapp.header }}</div>
                      <div class="whitespace-pre-wrap">{{ msg.whatsapp.body }}</div>
                      <div v-if="msg.whatsapp.footer" class="text-[11px] text-gray-400 mt-1">{{ msg.whatsapp.footer }}</div>
                    </div>

                    <!-- Question -->
                    <div v-else-if="msg.whatsapp?.type === 'question'" class="whitespace-pre-wrap">{{ msg.whatsapp.body }}</div>

                    <!-- Template label -->
                    <div v-else-if="msg.whatsapp?.type === 'template'" class="flex items-center gap-1.5">
                      <span class="text-[11px]">📋</span>
                      <span class="whitespace-pre-wrap">{{ msg.whatsapp.body }}</span>
                    </div>

                    <!-- MetaFlow label -->
                    <div v-else-if="msg.whatsapp?.type === 'flow'">
                      <div class="whitespace-pre-wrap">{{ msg.whatsapp.body }}</div>
                      <div class="mt-1.5 bg-[#034737] text-white text-center py-1.5 rounded-md text-[12px] font-medium opacity-60">{{ msg.whatsapp.cta }}</div>
                    </div>

                    <!-- Portal redirect -->
                    <div v-else-if="msg.whatsapp?.type === 'portal'" class="text-center">
                      <svg class="w-5 h-5 mx-auto text-teal-500 mb-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 2a10 10 0 0 1 0 20"/><path d="M2 12h20"/></svg>
                      <div class="text-[13px] font-medium">{{ msg.whatsapp.body }}</div>
                    </div>

                    <!-- Human transfer -->
                    <div v-else-if="msg.whatsapp?.type === 'human_transfer'" class="flex items-center gap-2 py-0.5">
                      <div class="w-6 h-6 rounded-full bg-amber-100 flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                      </div>
                      <div>
                        <div class="text-[12px] font-medium text-amber-700">Transfer to Human Agent</div>
                        <div class="text-[11px] text-gray-500">{{ msg.whatsapp.body }}</div>
                      </div>
                    </div>

                    <!-- Delay -->
                    <div v-else-if="msg.whatsapp?.type === 'delay'" class="flex items-center gap-1.5 text-[12px] text-gray-500">
                      <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                      {{ msg.whatsapp.body }}
                    </div>

                    <!-- AI action -->
                    <div v-else-if="msg.whatsapp?.type === 'ai_action'" class="flex items-center gap-1.5 text-[12px]">
                      <svg class="w-3.5 h-3.5 shrink-0 text-purple-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                      <span class="text-purple-600 font-medium">{{ msg.whatsapp.body }}</span>
                    </div>

                    <!-- Generic action notification -->
                    <div v-else-if="msg.whatsapp?.type === 'action'" class="flex items-center gap-1.5 text-[12px] text-gray-500">
                      <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                      {{ msg.whatsapp.body }}
                    </div>

                    <!-- Media labels -->
                    <div v-else-if="msg.whatsapp?.type === 'image'" class="flex items-center gap-1.5">
                      <span class="text-[11px]">📷</span>
                      <span>Image{{ msg.whatsapp.caption ? ': ' + msg.whatsapp.caption : '' }}</span>
                    </div>
                    <div v-else-if="msg.whatsapp?.type === 'video'" class="flex items-center gap-1.5">
                      <span class="text-[11px]">🎥</span>
                      <span>Video{{ msg.whatsapp.caption ? ': ' + msg.whatsapp.caption : '' }}</span>
                    </div>
                    <div v-else-if="msg.whatsapp?.type === 'document'" class="flex items-center gap-1.5">
                      <span class="text-[11px]">📎</span>
                      <span>Document{{ msg.whatsapp.caption ? ': ' + msg.whatsapp.caption : '' }}</span>
                    </div>
                    <div v-else-if="msg.whatsapp?.type === 'audio'" class="flex items-center gap-1.5">
                      <span class="text-[11px]">🎵</span>
                      <span>Audio</span>
                    </div>

                    <!-- Fallback -->
                    <div v-else-if="msg.whatsapp" class="whitespace-pre-wrap">{{ msg.whatsapp.body || msg.text }}</div>
                    <div v-else class="whitespace-pre-wrap">{{ msg.text }}</div>

                    <div class="text-[10px] text-[#8c8c8c] text-right mt-0.5">{{ msg.time }}</div>
                  </div>
                </div>

                <!-- User message -->
                <div v-else-if="msg.sender === 'user'" class="ml-auto max-w-[230px]">
                  <div class="rounded-lg rounded-tr-none bg-[#d8fad4] p-1.5 text-[13px] text-gray-800 relative speech-bubble-right shadow-sm">
                    <div class="whitespace-pre-wrap">{{ msg.text }}</div>
                    <div class="text-[10px] text-[#8c8c8c] text-right mt-0.5">{{ msg.time }}</div>
                  </div>
                </div>

                <!-- Interactive buttons (outside bubble) -->
                <div v-if="msg.options && msg.sender === 'bot' && msg.whatsapp?.type === 'interactive_buttons'" class="max-w-[230px]" style="margin-left: 0; margin-right: auto;">
                  <div class="space-y-1 mt-1">
                    <button
                      v-for="opt in msg.options"
                      :key="opt.id"
                      @click="selectOption(msg, opt)"
                      class="block w-full text-center bg-[#034737] text-white rounded-lg px-3 py-1.5 text-[13px] font-medium hover:bg-[#025a3f] transition-colors active:scale-[0.98]"
                    >
                      {{ opt.title }}
                    </button>
                  </div>
                </div>

                <!-- Interactive list (outside bubble) -->
                <div v-if="msg.options && msg.sender === 'bot' && msg.whatsapp?.type === 'interactive_list'" class="max-w-[230px]" style="margin-left: 0; margin-right: auto;">
                  <div class="mt-1 bg-white rounded-lg shadow-sm overflow-hidden">
                    <button
                      v-for="opt in msg.options"
                      :key="opt.id"
                      @click="selectOption(msg, opt)"
                      class="w-full text-left px-3 py-1.5 text-[13px] text-[#00a5f4] hover:bg-gray-50 transition-colors border-b border-gray-100 last:border-0 active:bg-gray-100"
                    >
                      <div class="font-medium">{{ opt.title }}</div>
                      <div v-if="opt.description" class="text-[11px] text-gray-400">{{ opt.description }}</div>
                    </button>
                  </div>
                </div>

                <!-- Portal options -->
                <div v-if="msg.options && msg.sender === 'bot' && msg.whatsapp?.type === 'portal'" class="max-w-[230px]" style="margin-left: 0; margin-right: auto;">
                  <div class="space-y-1 mt-1">
                    <button
                      v-for="opt in msg.options"
                      :key="opt.id"
                      @click="selectOption(msg, opt)"
                      class="block w-full text-center rounded-lg px-3 py-1.5 text-[13px] font-medium transition-colors active:scale-[0.98]"
                      :class="opt.id === 'portal_continue' ? 'bg-purple-600 text-white hover:bg-purple-700' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
                    >
                      {{ opt.title }}
                    </button>
                  </div>
                </div>
              </template>

              <!-- Typing indicator -->
              <div v-if="isTyping" class="mr-auto max-w-[230px]">
                <div class="rounded-lg rounded-tl-none bg-white p-2 speech-bubble-left shadow-sm">
                  <div class="flex gap-1">
                    <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms"></span>
                    <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms"></span>
                    <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms"></span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Input bar -->
            <div class="bg-[#F0F0F0] px-2 py-2 flex items-center gap-1.5 shrink-0">
              <input
                v-model="inputMessage"
                @keyup.enter="sendMessage"
                placeholder="Type a message..."
                class="flex-1 bg-white rounded-full px-3 py-1.5 text-[13px] text-gray-800 focus:outline-none focus:ring-1 focus:ring-teal-500/30"
              />
              <button
                @click="sendMessage"
                :disabled="!inputMessage.trim() || isTyping"
                class="w-8 h-8 bg-[#034737] rounded-full flex items-center justify-center hover:bg-[#025a3f] transition-colors disabled:opacity-50 disabled:cursor-not-allowed active:scale-95"
              >
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
              </button>
            </div>
          </div>

          <!-- Home indicator -->
          <div class="flex justify-center mt-2">
            <div class="w-20 h-1 bg-gray-600 rounded-full"></div>
          </div>
        </div>
      </div>

      <!-- Debug panel — below the phone -->
      <div v-if="showDebug" class="w-full max-w-2xl mt-5">
        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
          <div class="px-4 py-2.5 bg-gray-50 border-b border-gray-200 flex items-center justify-between">
            <div class="flex items-center gap-2">
              <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
              <h4 class="text-sm font-medium text-gray-700">Debug</h4>
            </div>
            <button @click="showDebug = false" class="text-gray-400 hover:text-gray-600 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
          </div>

          <div class="p-3 space-y-1.5 max-h-[300px] overflow-y-auto">
            <div v-if="debugEntries.length === 0" class="text-center py-6 text-gray-400 text-sm">
              Send a message to see debug info
            </div>

            <div v-for="(entry, idx) in debugEntries" :key="idx" :class="debugEntryClass(entry.type)" class="rounded-lg px-3 py-1.5">
              <div class="flex items-start gap-2">
                <div class="mt-0.5 shrink-0">
                  <div v-if="entry.type === 'success'" class="w-2 h-2 rounded-full bg-green-500"></div>
                  <div v-else-if="entry.type === 'warning'" class="w-2 h-2 rounded-full bg-amber-500"></div>
                  <div v-else-if="entry.type === 'error'" class="w-2 h-2 rounded-full bg-red-500"></div>
                  <div v-else class="w-2 h-2 rounded-full bg-blue-400"></div>
                </div>
                <div class="min-w-0">
                  <div class="font-medium text-gray-800 text-xs">{{ entry.title }}</div>
                  <div v-if="entry.detail" class="text-[11px] text-gray-500 mt-0.5 truncate">{{ entry.detail }}</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Layout>
</template>

<script setup>
import { ref, nextTick } from 'vue'
import Layout from '../Layout.vue'
import axios from 'axios'

const props = defineProps({
  flows: { type: Array, default: () => [] },
  aiEnabled: { type: Boolean, default: false },
  aiFallbackEnabled: { type: Boolean, default: false },
  withinWorkingHours: { type: Boolean, default: true },
  automationEnabled: { type: Boolean, default: true },
  aimodule: { type: Boolean, default: false },
  fbmodule: { type: Boolean, default: false },
  orgName: { type: String, default: 'ChatBerry' },
})

// State
const isNewContact = ref(false)
const duringWorkingHours = ref(props.withinWorkingHours)
const showDebug = ref(false)
const inputMessage = ref('')
const isTyping = ref(false)
const chatMessages = ref([])
const debugEntries = ref([])

// Flow session state
const flowSessionId = ref(null)

const chatArea = ref(null)

const scrollToBottom = () => {
  nextTick(() => {
    if (chatArea.value) {
      chatArea.value.scrollTop = chatArea.value.scrollHeight
    }
  })
}

const now = () => {
  const d = new Date()
  return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0')
}

const addBotMessage = (whatsapp, options = null) => {
  chatMessages.value.push({
    sender: 'bot',
    whatsapp,
    time: now(),
    options,
  })
  scrollToBottom()
}

const addUserMessage = (text) => {
  chatMessages.value.push({
    sender: 'user',
    text,
    time: now(),
  })
  scrollToBottom()
}

const addDebug = (type, title, detail = '') => {
  debugEntries.value.push({ type, title, detail })
}

const resetChat = () => {
  chatMessages.value = []
  debugEntries.value = []
  flowSessionId.value = null
  inputMessage.value = ''
}

// --- Unified message sending ---
const sendMessage = async () => {
  const msg = inputMessage.value.trim()
  if (!msg || isTyping.value) return

  addUserMessage(msg)
  inputMessage.value = ''

  // If we're in a flow session, advance it
  if (flowSessionId.value) {
    await advanceFlow(msg)
    return
  }

  // Otherwise, evaluate triggers
  await evaluateAndStart(msg)
}

const evaluateAndStart = async (message) => {
  isTyping.value = true
  addDebug('info', 'Evaluating triggers...', `Message: "${message}"`)

  try {
    const response = await axios.post('/automation/test/trigger', {
      message,
      is_new_contact: isNewContact.value,
      during_working_hours: duringWorkingHours.value,
    })
    const data = response.data

    isTyping.value = false

    if (data.working_hours_overridden) {
      addDebug('info', 'Working hours overridden', `Simulating: ${duringWorkingHours.value ? 'During working hours' : 'Outside working hours'}`)
    }

    if (data.whatsapp) {
      addBotMessage(data.whatsapp)
    }

    if (data.matched && data.flow) {
      addDebug('success', `Trigger: "${data.trigger.name}"`, `${data.trigger.type}${data.trigger.matched_keyword ? ' · Keyword: ' + data.trigger.matched_keyword : ''}`)
      addDebug('info', `Starting flow: "${data.flow.name}"`)

      // Auto-start the matched flow
      await startFlow(data.flow.id)
    } else if (data.matched) {
      addDebug('success', `Trigger: "${data.trigger.name}"`, data.trigger.type)
      addDebug('warning', 'No flow linked to this trigger')
    } else {
      addDebug('warning', 'No trigger matched')

      // Show AI result if backend already handled it
      if (data.ai_result) {
        if (data.ai_result.type === 'answer') {
          addDebug('success', 'AI answered', data.ai_result.content)
        } else if (data.ai_result.type === 'escalation') {
          addDebug('warning', 'Escalation triggered')
        }
      }
    }

    // Show trace in debug
    if (data.trace && showDebug.value) {
      data.trace.forEach(t => {
        addDebug(t.matched ? 'success' : 'info', `${t.name} (${t.type})`, t.reason)
      })
    }
  } catch (error) {
    isTyping.value = false
    addBotMessage({ type: 'text', body: 'Error evaluating triggers.' })
    addDebug('error', 'Request failed', error.response?.data?.message || error.message)
  }
}

const startFlow = async (flowId) => {
  isTyping.value = true
  addDebug('info', 'Loading flow...')

  try {
    const response = await axios.post('/automation/test/flow/start', { flow_id: flowId })
    const data = response.data

    isTyping.value = false

    if (!data.success) {
      addBotMessage({ type: 'text', body: data.message || 'Failed to start flow.' })
      addDebug('error', 'Failed', data.message)
      return
    }

    flowSessionId.value = data.session_id
    addDebug('success', `Flow: "${data.flow.name}"`)

    processFlowMessages(data.messages || [])

    if (data.needs_input) {
      addDebug('warning', `Waiting for ${data.needs_input.node_type} input`)
    }
  } catch (error) {
    isTyping.value = false
    addBotMessage({ type: 'text', body: 'Error starting flow.' })
    addDebug('error', 'Request failed', error.response?.data?.message || error.message)
  }
}

const advanceFlow = async (userInput) => {
  if (!flowSessionId.value) return

  isTyping.value = true
  addDebug('info', `User: "${userInput}"`)

  try {
    const response = await axios.post('/automation/test/flow/step', {
      session_id: flowSessionId.value,
      user_input: userInput,
    })
    const data = response.data

    isTyping.value = false

    if (!data.success) {
      addBotMessage({ type: 'text', body: data.message || 'Error.' })
      addDebug('error', 'Error', data.message)
      flowSessionId.value = null
      return
    }

    const botMessages = (data.messages || []).filter(m => m.node_type !== 'user_message')
    processFlowMessages(botMessages)

    if (data.needs_input) {
      addDebug('warning', `Waiting for ${data.needs_input.node_type} input`)
    } else if (botMessages.some(m => m.category === 'flow_ended')) {
      flowSessionId.value = null
      addDebug('info', 'Flow complete')
    }
  } catch (error) {
    isTyping.value = false
    addBotMessage({ type: 'text', body: 'Error processing input.' })
    addDebug('error', 'Request failed', error.response?.data?.message || error.message)
  }
}

const selectOption = async (msg, option) => {
  // Disable options on this message
  const idx = chatMessages.value.indexOf(msg)
  if (idx !== -1) {
    chatMessages.value[idx].options = null
  }

  addUserMessage(option.title)
  isTyping.value = true
  addDebug('info', `Selected: "${option.title}"`)

  try {
    const response = await axios.post('/automation/test/flow/step', {
      session_id: flowSessionId.value,
      selected_option: option.id,
    })
    const data = response.data

    isTyping.value = false

    if (!data.success) {
      addBotMessage({ type: 'text', body: data.message || 'Error.' })
      addDebug('error', 'Error', data.message)
      flowSessionId.value = null
      return
    }

    const botMessages = (data.messages || []).filter(m => m.node_type !== 'user_message')
    processFlowMessages(botMessages)

    if (data.needs_input) {
      addDebug('warning', `Waiting for ${data.needs_input.node_type} input`)
    } else if (botMessages.some(m => m.category === 'flow_ended')) {
      flowSessionId.value = null
      addDebug('info', 'Flow complete')
    }
  } catch (error) {
    isTyping.value = false
    addBotMessage({ type: 'text', body: 'Error.' })
    addDebug('error', 'Request failed', error.response?.data?.message || error.message)
  }
}

const processFlowMessages = (messages) => {
  messages.forEach(msg => {
    if (msg.node_type === 'start') {
      addDebug('info', 'Flow started')
      return
    }

    if (msg.whatsapp) {
      const options = msg.options || null
      addBotMessage(msg.whatsapp, options)
    }

    if (msg.category === 'action_executed') {
      addDebug('info', msg.description || `Action: ${msg.node_type}`)
    } else if (msg.category === 'waiting_for_input') {
      addDebug('warning', msg.description || 'Waiting for input')
    } else if (msg.category === 'flow_redirect') {
      addDebug('info', msg.description || 'Redirecting')
    } else if (msg.category === 'flow_ended') {
      addDebug('info', msg.description || 'Flow ended')
    }
  })
}

const debugEntryClass = (type) => {
  switch (type) {
    case 'success': return 'bg-green-50'
    case 'warning': return 'bg-amber-50'
    case 'error': return 'bg-red-50'
    default: return 'bg-blue-50'
  }
}
</script>