<template>
  <div ref="messagesRef" class="flex-1 overflow-y-auto px-4 py-3 space-y-3 chat-bg" style="scroll-behavior: smooth;">
    <!-- Empty state -->
    <div v-if="messages.length === 0 && !isTyping" class="flex items-center justify-center h-full">
      <div class="text-center py-8 px-4">
        <div class="inline-block bg-white/80 rounded-xl px-4 py-3 text-sm text-gray-500 shadow-sm backdrop-blur-sm">
          <div class="text-2xl mb-1">&#128172;</div> {{ $t("Type a message to test your automations") }} </div>
      </div>
    </div>

    <template v-for="(msg, idx) in messages" :key="idx">
      <!-- System message -->
      <div v-if="msg.sender === 'system'" class="flex justify-center my-2">
        <div class="inline-flex items-center gap-2 bg-amber-50 border border-amber-200 rounded-lg px-4 py-2 shadow-sm max-w-[85%]">
          <svg class="w-4 h-4 text-amber-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <span class="text-xs text-amber-800 font-medium text-center">{{ msg.text }}</span>
        </div>
      </div>

      <!-- Bot message -->
      <div v-if="msg.sender === 'bot'" class="me-auto max-w-[70%]">
        <div class="rounded-lg rounded-tl-none bg-white p-2.5 text-sm text-gray-800 relative speech-bubble-left shadow-sm">
          <!-- Text -->
          <div v-if="msg.whatsapp?.type === 'text'" class="whitespace-pre-wrap">{{ msg.whatsapp.body }}</div>

          <!-- Interactive buttons - body only -->
          <div v-else-if="msg.whatsapp?.type === 'interactive_buttons'">
            <div v-if="msg.whatsapp.header" class="font-semibold text-sm mb-1">{{ msg.whatsapp.header }}</div>
            <div class="whitespace-pre-wrap">{{ msg.whatsapp.body }}</div>
            <div v-if="msg.whatsapp.footer" class="text-xs text-gray-400 mt-1">{{ msg.whatsapp.footer }}</div>
          </div>

          <!-- Interactive list - body only -->
          <div v-else-if="msg.whatsapp?.type === 'interactive_list'">
            <div v-if="msg.whatsapp.header" class="font-semibold text-sm mb-1">{{ msg.whatsapp.header }}</div>
            <div class="whitespace-pre-wrap">{{ msg.whatsapp.body }}</div>
            <div v-if="msg.whatsapp.footer" class="text-xs text-gray-400 mt-1">{{ msg.whatsapp.footer }}</div>
          </div>

          <!-- Question -->
          <div v-else-if="msg.whatsapp?.type === 'question'">
            <div class="whitespace-pre-wrap">{{ msg.whatsapp.body }}</div>
            <div v-if="msg.whatsapp.input_type && msg.whatsapp.input_type !== 'text'" class="text-[11px] text-gray-400 mt-1 border-t border-gray-100 pt-1"> {{ $t("Expects:") }} {{ msg.whatsapp.input_type }}
            </div>
          </div>

          <!-- Template message -->
          <div v-else-if="msg.whatsapp?.type === 'template'">
            <div class="flex items-center gap-1.5 mb-1">
              <span class="text-xs">&#128203;</span>
              <span class="text-[11px] font-medium text-teal-700 bg-teal-50 px-1.5 py-0.5 rounded">{{ $t("Template") }}</span>
              <span class="text-[11px] text-gray-400">{{ msg.whatsapp.name }}</span>
              <span v-if="msg.whatsapp.language" class="text-[10px] text-gray-400">({{ msg.whatsapp.language }})</span>
            </div>
          </div>

          <!-- MetaFlow / WhatsApp Flow -->
          <div v-else-if="msg.whatsapp?.type === 'flow'">
            <div class="whitespace-pre-wrap">{{ msg.whatsapp.body }}</div>
            <div class="mt-2 bg-[#034737] text-white text-center py-2 rounded-lg text-sm font-medium opacity-60 cursor-default">
              {{ msg.whatsapp.cta }}
            </div>
            <div class="text-[11px] text-gray-400 mt-1">{{ $t("WhatsApp Flow:") }} {{ msg.whatsapp.name }}</div>
          </div>

          <!-- Portal redirect -->
          <div v-else-if="msg.whatsapp?.type === 'portal'" class="text-center">
            <svg class="w-6 h-6 mx-auto text-teal-500 mb-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <circle cx="12" cy="12" r="10"/><path d="M12 2a10 10 0 0 1 0 20"/><path d="M2 12h20"/>
            </svg>
            <div class="text-sm font-medium">{{ msg.whatsapp.body }}</div>
          </div>

          <!-- Human transfer -->
          <div v-else-if="msg.whatsapp?.type === 'human_transfer'" class="flex flex-col items-center gap-2 py-2">
            <div class="flex items-center gap-2.5">
              <div class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
              </div>
              <div>
                <div class="text-sm font-semibold text-amber-700">{{ $t("Transfer to Human Agent") }}</div>
                <div class="text-xs text-gray-500">{{ msg.whatsapp.body }}</div>
              </div>
            </div>
            <div class="text-[11px] text-gray-400 border-t border-gray-200 pt-1.5 w-full text-center"> {{ $t("Bot session ended — agent is now handling this conversation") }} </div>
          </div>

          <!-- Delay -->
          <div v-else-if="msg.whatsapp?.type === 'delay'" class="flex items-center gap-1.5 text-sm text-gray-500">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
            </svg>
            {{ msg.whatsapp.body }}
          </div>

          <!-- AI action -->
          <div v-else-if="msg.whatsapp?.type === 'ai_action'" class="flex items-center gap-1.5 text-sm">
            <svg class="w-4 h-4 shrink-0 text-purple-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
            </svg>
            <span class="text-purple-600 font-medium">{{ msg.whatsapp.body }}</span>
          </div>

          <!-- Generic action notification -->
          <div v-else-if="msg.whatsapp?.type === 'action'" class="flex items-center gap-1.5 text-sm text-gray-500">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
            {{ msg.whatsapp.body }}
          </div>

          <!-- Media labels -->
          <div v-else-if="msg.whatsapp?.type === 'image'" class="flex items-center gap-1.5">
            <span class="text-sm">&#128247;</span>
            <span>{{ $t("Image") }}{{ msg.whatsapp.caption ? ': ' + msg.whatsapp.caption : '' }}</span>
          </div>
          <div v-else-if="msg.whatsapp?.type === 'video'" class="flex items-center gap-1.5">
            <span class="text-sm">&#127916;</span>
            <span>{{ $t("Video") }}{{ msg.whatsapp.caption ? ': ' + msg.whatsapp.caption : '' }}</span>
          </div>
          <div v-else-if="msg.whatsapp?.type === 'document'" class="flex items-center gap-1.5">
            <span class="text-sm">&#128206;</span>
            <span>{{ $t("Document") }}{{ msg.whatsapp.caption ? ': ' + msg.whatsapp.caption : '' }}</span>
          </div>
          <div v-else-if="msg.whatsapp?.type === 'audio'" class="flex items-center gap-1.5">
            <span class="text-sm">&#127925;</span>
            <span>{{ $t("Audio") }}</span>
          </div>

          <!-- Fallback -->
          <div v-else-if="msg.whatsapp" class="whitespace-pre-wrap">{{ msg.whatsapp.body || msg.text }}</div>
          <div v-else class="whitespace-pre-wrap">{{ msg.text }}</div>

          <div class="text-[11px] text-[#8c8c8c] text-right mt-0.5">{{ msg.time }}</div>
        </div>
      </div>

      <!-- User message -->
      <div v-if="msg.sender === 'user'" class="ms-auto max-w-[70%]">
        <div class="rounded-lg rounded-tr-none bg-[#d8fad4] p-2.5 text-sm text-gray-800 relative speech-bubble-right shadow-sm">
          <div class="whitespace-pre-wrap">{{ msg.text }}</div>
          <div class="text-[11px] text-[#8c8c8c] text-right mt-0.5">{{ msg.time }}</div>
        </div>
      </div>

      <!-- Interactive buttons (outside bubble) -->
      <div v-if="msg.options && msg.sender === 'bot' && msg.whatsapp?.type === 'interactive_buttons'" class="max-w-[70%]" style="margin-left: 0; margin-right: auto;">
        <div class="space-y-1.5 mt-1">
          <button
            v-for="opt in msg.options"
            :key="opt.id"
            @click="$emit('select-option', msg, opt)"
            class="block w-full text-center bg-[#034737] text-white rounded-lg px-4 py-2 text-sm font-medium hover:bg-[#025a3f] transition-colors active:scale-[0.98]"
          >
            {{ opt.title }}
          </button>
        </div>
      </div>

      <!-- Interactive list (outside bubble) -->
      <div v-if="msg.options && msg.sender === 'bot' && msg.whatsapp?.type === 'interactive_list'" class="max-w-[70%]" style="margin-left: 0; margin-right: auto;">
        <div class="mt-1 bg-white rounded-lg shadow-sm overflow-hidden">
          <button
            v-for="opt in msg.options"
            :key="opt.id"
            @click="$emit('select-option', msg, opt)"
            class="w-full text-left px-3 py-2 text-sm text-[#00a5f4] hover:bg-gray-50 transition-colors border-b border-gray-100 last:border-0 active:bg-gray-100"
          >
            <div class="font-medium">{{ opt.title }}</div>
            <div v-if="opt.description" class="text-xs text-gray-400">{{ opt.description }}</div>
          </button>
        </div>
      </div>

      <!-- Portal options (outside bubble) -->
      <div v-if="msg.options && msg.sender === 'bot' && msg.whatsapp?.type === 'portal'" class="max-w-[70%]" style="margin-left: 0; margin-right: auto;">
        <div class="space-y-1.5 mt-1">
          <button
            v-for="opt in msg.options"
            :key="opt.id"
            @click="$emit('select-option', msg, opt)"
            class="block w-full text-center rounded-lg px-4 py-2 text-sm font-medium transition-colors active:scale-[0.98]"
            :class="opt.id === 'portal_continue' ? 'bg-purple-600 text-white hover:bg-purple-700' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
          >
            {{ opt.title }}
          </button>
        </div>
      </div>
    </template>

    <!-- Typing indicator -->
    <div v-if="isTyping" class="me-auto max-w-[70%]">
      <div class="rounded-lg rounded-tl-none bg-white p-3 speech-bubble-left shadow-sm">
        <div class="flex gap-1.5">
          <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms"></span>
          <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms"></span>
          <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms"></span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, nextTick } from 'vue'

const props = defineProps({
  messages: { type: Array, required: true },
  isTyping: { type: Boolean, default: false },
})

const emit = defineEmits(['select-option'])

const messagesRef = ref(null)

watch(() => props.messages.length, () => {
  nextTick(() => {
    if (messagesRef.value) {
      messagesRef.value.scrollTop = messagesRef.value.scrollHeight
    }
  })
})

watch(() => props.isTyping, () => {
  nextTick(() => {
    if (messagesRef.value) {
      messagesRef.value.scrollTop = messagesRef.value.scrollHeight
    }
  })
})
</script>