<template>
  <div class="bg-[#F0F0F0] px-4 py-3 border-t border-gray-200 shrink-0">
    <div v-if="hint && hint !== 'text'" class="text-[11px] text-gray-400 mb-1 px-1"> {{ $t("Expects") }} {{ $t(hint) }} </div>
    <div class="flex items-center gap-2">
      <input
        :value="modelValue"
        @input="$emit('update:modelValue', $event.target.value)"
        @keyup.enter="$emit('send')"
        @keyup.escape="$emit('update:modelValue', '')"
        :disabled="disabled"
        :placeholder="hint && hint !== 'text' ? 'أدخل الإجابة المطلوبة...' : 'اكتب رسالة...'"
        class="flex-1 bg-white rounded-full px-4 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-[#034737]/30 placeholder:text-gray-400"
      />
      <button
        @click="$emit('send')"
        :disabled="!modelValue.trim() || disabled"
        class="w-10 h-10 bg-[#034737] rounded-full flex items-center justify-center hover:bg-[#025a3f] transition-colors disabled:opacity-50 disabled:cursor-not-allowed active:scale-95 shrink-0"
      >
        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/>
        </svg>
      </button>
    </div>
  </div>
</template>

<script setup>
defineProps({
  modelValue: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  hint: { type: String, default: null },
})

defineEmits(['update:modelValue', 'send'])
</script>
