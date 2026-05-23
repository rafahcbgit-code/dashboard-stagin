<template>
  <div class="flex flex-col h-full min-h-[400px] lg:min-h-0 bg-[#ECE5DD] rounded-xl overflow-hidden shadow-sm border border-gray-200">
    <TestChatHeader
      :org-name="orgName"
      :automation-enabled="automationEnabled"
      :within-working-hours="withinWorkingHours"
      :during-working-hours="duringWorkingHours"
      :ai-enabled="aiEnabled"
      :aimodule="aimodule"
    />

    <TestChatMessages
      :messages="messages"
      :is-typing="isTyping"
      @select-option="(msg, opt) => $emit('select-option', msg, opt)"
    />

    <TestChatInput
      :model-value="inputMessage"
      @update:model-value="$emit('update:input-message', $event)"
      :disabled="isTyping"
      @send="$emit('send')"
    />
  </div>
</template>

<script setup>
import TestChatHeader from './TestChatHeader.vue'
import TestChatMessages from './TestChatMessages.vue'
import TestChatInput from './TestChatInput.vue'

defineProps({
  orgName: { type: String, default: 'ChatBerry' },
  automationEnabled: { type: Boolean, default: true },
  withinWorkingHours: { type: Boolean, default: true },
  duringWorkingHours: { type: Boolean, default: true },
  aiEnabled: { type: Boolean, default: false },
  aimodule: { type: Boolean, default: false },
  messages: { type: Array, required: true },
  isTyping: { type: Boolean, default: false },
  inputMessage: { type: String, default: '' },
})

defineEmits(['send', 'select-option', 'update:input-message'])
</script>