<template>
  <div class="bg-[#034737] flex items-center px-4 py-3 gap-3 shrink-0">
    <div class="w-10 h-10 bg-[#25D366] rounded-full flex items-center justify-center">
      <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3z"/>
      </svg>
    </div>
    <div class="flex-1 min-w-0">
      <div class="text-white font-semibold truncate">{{ orgName }}</div>
      <div class="text-green-200 text-xs">online</div>
    </div>
    <div class="flex items-center gap-2">
      <span v-if="automationEnabled" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-green-500/20 text-green-200">
        <span class="w-1.5 h-1.5 rounded-full bg-green-400"></span>
        Automation On
      </span>
      <span v-else class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-red-500/20 text-red-200">
        <span class="w-1.5 h-1.5 rounded-full bg-red-400"></span>
        Automation Off
      </span>

      <span v-if="duringWorkingHours" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-green-500/20 text-green-200">
        Working Hours
      </span>
      <span v-else class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-500/20 text-amber-200">
        Outside Hours
      </span>

      <span v-if="aimodule && aiEnabled" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-purple-500/20 text-purple-200">
        AI On
      </span>
    </div>
  </div>
</template>

<script setup>
defineProps({
  orgName: { type: String, default: 'ChatBerry' },
  automationEnabled: { type: Boolean, default: true },
  withinWorkingHours: { type: Boolean, default: true },
  duringWorkingHours: { type: Boolean, default: true },
  aiEnabled: { type: Boolean, default: false },
  aimodule: { type: Boolean, default: false },
})
</script>