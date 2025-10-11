<script setup>
import { ref } from 'vue';
import { useForm } from "@inertiajs/vue3";

const props = defineProps({
    assignedToSpecificAgents: Boolean,
    agents: Array,       // قائمة الوكلاء
    selectedAgents: Array 
});

const form = useForm({
    assigned_to_specific_agents: props.assignedToSpecificAgents,
    active: true,
    selected_agents: props.selectedAgents || []
});

const submitForm = () => {
    form.post('/settings/tickets', { preserveScroll: true });
}
</script>

<template>
  <!-- يتحكم بالاتجاه حسب اللغة -->
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
        <!-- بدلنا mr-2 بـ margin ديناميكي حسب الاتجاه -->
        <input 
          type="checkbox" 
          class="mx-2" 
          :value="agent.id" 
          v-model="form.selected_agents"
        >
        <span>{{ agent.first_name }}</span>
      </li>
    </ul>

    <button 
      @click="submitForm"
      class="mt-4 bg-primary text-white px-4 py-2 rounded hover:bg-primary-dark"
    >
      {{ $t('Save Selected Agents') }}
    </button>
  </div>
</template>
