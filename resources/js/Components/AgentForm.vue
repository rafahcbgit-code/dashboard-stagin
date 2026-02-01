<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    assignedToSpecificAgents: Boolean,
    agents: Array,       
    selectedAgents: Array 
});

const emit = defineEmits(['update:selected-agents']);

const localSelectedAgents = ref(props.selectedAgents || []);

// Sync with parent component
watch(() => props.selectedAgents, (newVal) => {
    localSelectedAgents.value = newVal || [];
}, { immediate: true });

// Emit changes to parent
watch(localSelectedAgents, (newVal) => {
    emit('update:selected-agents', newVal);
}, { deep: true });
</script>

<template>
  <div 
    :dir="$i18n.locale === 'ar' ? 'rtl' : 'ltr'" 
    class="bg-white border border-slate-200 rounded-lg p-4"
  >
    <h4 class="text-lg font-semibold mb-3">{{ $t('Select Agents') }}</h4>

    <ul class="space-y-2 max-h-64 overflow-y-auto">
      <li 
        v-for="agent in props.agents" 
        :key="agent.id" 
        class="flex items-center p-2 border rounded-md bg-gray-50"
      >
        <input 
          type="checkbox" 
          class="mx-2" 
          :value="agent.id" 
          v-model="localSelectedAgents"
        >
        <span>{{ agent.first_name }}</span>
      </li>
    </ul>
  </div>
</template>
