<template>
  <div class="assign-to-agent-node">
    <Handle
      type="target"
      :position="Position.Top"
      class="w-3 h-3 !bg-teal-500"
    />
    
    <div class="bg-white border-2 border-teal-200 rounded-lg p-4 shadow-sm w-[400px]" style="min-width: 400px;">
      <div class="flex items-center mb-3">
        <div class="flex items-center gap-2 flex-1">
          <div class="w-8 h-8 bg-slate-50 rounded-lg flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-teal-600">
              <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
              <circle cx="9" cy="7" r="4"/>
              <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
              <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
          </div>
          <div>
            <h3 class="font-medium text-gray-900 text-sm">{{ actionName }}</h3>
          </div>
        </div>
        <div class="flex items-center gap-1 ml-auto">
          <button
            @click="toggleEdit"
            class="p-1 text-gray-400 hover:text-gray-600 rounded"
            title="Edit Action"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
              <path d="m18.5 2.5 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
          </button>
          <button
            @click="deleteNode"
            class="p-1 text-red-400 hover:text-red-600 rounded"
            title="Delete Action"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M3 6h18"/>
              <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
              <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
            </svg>
          </button>
        </div>
      </div>
      
      <div class="text-xs text-gray-600 mb-2">
        <div>
          <span class="font-medium">Assign to:</span> {{ getAgentName(config.agent_id) }}
        </div>
      </div>
      
      <div class="flex items-center justify-between text-xs">
        <span class="text-gray-500">Action</span>
        <div class="flex items-center gap-1">
          <span 
            :class="isActive ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'"
            class="px-2 py-1 rounded-full text-xs"
          >
            {{ isActive ? 'Active' : 'Inactive' }}
          </span>
        </div>
      </div>
    </div>

    <!-- Single exit handle -->
    <Handle
      type="source"
      :position="Position.Bottom"
      class="w-3 h-3 !bg-teal-500"
    />

    <!-- Edit Modal -->
    <div v-if="showEditModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg max-w-md w-full mx-4 max-h-[80vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-semibold text-gray-900">Edit Assign to Agent Action</h3>
        </div>
        
        <form @submit.prevent="saveAction" class="p-6">
          <div class="space-y-4">
            <!-- Configuration Fields -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Configuration</label>
              <div class="space-y-3">
                <!-- Agent Selection -->
                <div>
                  <label class="block text-sm font-medium text-gray-700">Select Agent</label>
                  <FormSelect
                    v-model="editForm.config.agent_id"
                    :options="availableAgents"
                    placeholder="Select an agent"
                    required
                  />
                  <div v-if="availableAgents.length === 0" class="text-xs text-red-500 mt-1">
                    No agents available. Please add team members first.
                  </div>
                </div>

                <!-- What happens explanation -->
                <div class="bg-gray-50 border border-blue-200 rounded-lg p-3">
                  <p class="text-xs text-gray-600 mb-1">
                    <strong>What happens:</strong> The conversation will be assigned to the selected agent.
                  </p>
                  <p class="text-xs text-gray-500 mb-2">
                    • Chat ticket will be created/updated with the selected agent
                  </p>
                  <p class="text-xs text-gray-500 mb-2">
                    • Ticket status will be set to "open"
                  </p>
                  <p class="text-xs text-gray-500">
                    • Agent will receive a push notification (if enabled)
                  </p>
                </div>
              </div>
            </div>

            <!-- Active Status -->
            <div class="flex items-center">
              <FormCheckbox
                v-model="editForm.is_active"
                label="Active"
                class="h-4 w-4 text-teal-600 focus:ring-teal-500 border-gray-300 rounded"
              />
            </div>
          </div>

          <div class="flex justify-end space-x-3 mt-6">
            <button 
              type="button"
              @click="closeEditModal"
              class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
            >
              Cancel
            </button>
            <button 
              type="submit"
              class="px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-colors"
            >
              Save Changes
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { Handle, Position, useVueFlow, useNode } from '@vue-flow/core'
import FormSelect from '../../../../../../../../resources/js/Components/FormSelect.vue'
import FormCheckbox from '../../../../../../../../resources/js/Components/FormCheckbox.vue'
import axios from 'axios'

const props = defineProps({
  id: String,
  data: Object,
  selected: Boolean,
})

const { removeNodes } = useVueFlow()
const node = useNode()

const config = computed(() => props.data?.config || {})
const isActive = computed(() => props.data?.is_active !== false)

const showEditModal = ref(false)
const availableAgents = ref([])
const loadingAgents = ref(false)

const actionName = computed(() => {
  const actionConfig = config.value
  
  if (actionConfig.agent_id) {
    const agent = availableAgents.value.find(a => a.value == actionConfig.agent_id)
    return agent ? `Assign to ${agent.label}` : `Assign to Agent (ID: ${actionConfig.agent_id})`
  }
  return 'Assign to Agent'
})

const editForm = reactive({
  config: {},
  is_active: true
})

const getAgentName = (agentId) => {
  if (!agentId) return 'No agent selected'
  const agent = availableAgents.value.find(a => a.value == agentId)
  return agent ? agent.label : `Agent ID: ${agentId}`
}

const loadAgents = async () => {
  loadingAgents.value = true
  try {
    const response = await axios.get('/team', {
      headers: { 'Accept': 'application/json' }
    })
    
    availableAgents.value = response.data.rows.map(agent => ({
      value: agent.user_id,
      label: agent.user.full_name || agent.user.email
    }))
  } catch (error) {
    console.error('Error loading agents:', error)
  } finally {
    loadingAgents.value = false
  }
}

const toggleEdit = async () => {
  // Load agents when opening modal
  await loadAgents()
  
  // Initialize edit form with current values
  editForm.config = {
    agent_id: config.value.agent_id || ''
  }
  editForm.is_active = isActive.value
  showEditModal.value = true
}

const closeEditModal = () => {
  showEditModal.value = false
}

const saveAction = () => {
  // Update the node data directly using useNode
  node.node.data = {
    ...node.node.data,
    actionType: 'assign_to_agent',
    config: editForm.config,
    is_active: editForm.is_active
  }
  
  closeEditModal()
}

const deleteNode = () => {
  if (confirm('Are you sure you want to delete this action?')) {
    removeNodes([props.id])
  }
}

onMounted(async () => {
  // Load agents on mount for display
  await loadAgents()
})
</script>

<style scoped>
.assign-to-agent-node {
  position: relative;
}

.assign-to-agent-node:hover {
  z-index: 10;
}
</style>