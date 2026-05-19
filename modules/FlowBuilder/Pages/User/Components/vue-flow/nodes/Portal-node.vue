<template>
  <div class="portal-node">
    <Handle
      type="target"
      :position="Position.Top"
      class="w-3 h-3 !bg-teal-500"
    />

    <div class="bg-white border-2 border-teal-200 rounded-lg p-4 shadow-sm w-[400px]" style="min-width: 400px;">
      <div class="flex items-center mb-3">
        <div class="flex items-center gap-2 flex-1">
          <div class="w-8 h-8 bg-teal-50 rounded-lg flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-teal-600">
              <circle cx="12" cy="12" r="10"/>
              <path d="M12 2a10 10 0 0 1 0 20"/>
              <path d="M2 12h20"/>
            </svg>
          </div>
          <div>
            <h3 class="font-medium text-gray-900 text-sm">{{ displayName }}</h3>
            <p v-if="fields.target_flow_name" class="text-xs text-teal-600">-> {{ fields.target_flow_name }}</p>
          </div>
        </div>
        <div class="flex items-center gap-1 ml-auto">
          <button
            @click="toggleEdit"
            class="p-1 text-gray-400 hover:text-gray-600 rounded"
            title="Edit Portal"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
              <path d="m18.5 2.5 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
          </button>
          <button
            @click="deleteNode"
            class="p-1 text-red-400 hover:text-red-600 rounded"
            title="Delete Portal"
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
        <div v-if="fields.target_flow_uuid">
          <span class="font-medium">Target:</span> {{ fields.target_flow_name || 'Unknown' }}
        </div>
        <div v-else class="text-gray-400 italic">No target flow selected</div>
      </div>

      <div v-if="fields.trigger_keyword" class="text-xs text-gray-500 mb-2">
        Keyword: <span class="font-mono bg-gray-100 px-1 rounded">{{ fields.trigger_keyword }}</span>
        <span v-if="fields.trigger_created" class="text-green-500 ml-1">(trigger created)</span>
      </div>

      <div class="flex items-center justify-between text-xs">
        <span class="text-gray-500">Flow Transition</span>
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
    <div v-if="showEditModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" @wheel.stop>
      <div class="bg-white rounded-lg max-w-lg w-full mx-4 max-h-[80vh] overflow-y-auto" @wheel.stop>
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-semibold text-gray-900">Edit Portal Node</h3>
        </div>

        <form @submit.prevent="saveAction" class="p-6">
          <div class="space-y-4">
            <!-- Target flow selector -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Target Flow</label>
              <select
                v-model="editForm.target_flow_uuid"
                @change="onFlowSelected"
                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 focus:border-teal-500 focus:ring-1 focus:ring-teal-500/30 focus:outline-none"
                required
              >
                <option value="">Choose a flow...</option>
                <option v-for="flow in availableFlowsList" :key="flow.uuid" :value="flow.uuid">
                  {{ flow.name }}
                </option>
              </select>
              <div v-if="availableFlowsList.length === 0" class="text-xs text-red-500 mt-1">
                No active flows available. Please publish a flow first.
              </div>
            </div>

            <!-- Auto-detected button title info -->
            <div v-if="detectedButtonTitle" class="rounded-md bg-teal-50 border border-teal-200 px-3 py-2 text-xs text-teal-700">
              <div class="flex items-start gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 mt-0.5 text-teal-400"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <p>Auto-detected from button: <strong>{{ detectedButtonTitle }}</strong></p>
              </div>
            </div>

            <!-- Trigger name -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Trigger Name</label>
              <input
                v-model="editForm.trigger_name"
                type="text"
                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 focus:border-teal-500 focus:ring-1 focus:ring-teal-500/30 focus:outline-none"
                maxlength="255"
                placeholder="e.g. Sales, Support"
              />
            </div>

            <!-- Trigger keyword -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Trigger Keyword</label>
              <div class="relative">
                <input
                  v-model="editForm.trigger_keyword"
                  type="text"
                  class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 focus:border-teal-500 focus:ring-1 focus:ring-teal-500/30 focus:outline-none"
                  maxlength="255"
                  placeholder="e.g. sales, support"
                  @input="checkKeywordAvailability"
                />
                <div v-if="keywordChecking" class="absolute right-2 top-2.5">
                  <svg class="animate-spin h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                </div>
              </div>
              <div v-if="keywordError" class="text-xs text-red-500 mt-1">
                {{ keywordError }}
              </div>
              <div v-else-if="keywordAvailable === true" class="text-xs text-green-500 mt-1">
                Keyword is available
              </div>
            </div>

            <!-- Create trigger toggle -->
            <div>
              <FormCheckbox
                v-model="editForm.create_trigger"
                label="Create trigger keyword"
              />
              <p class="text-xs text-gray-500 -mt-1 ml-6">Creates a trigger so the keyword works outside this flow too</p>
            </div>

            <!-- Info callout -->
            <div class="rounded-md bg-slate-50 border border-slate-200 px-3 py-2 text-xs text-slate-500">
              <div class="flex items-start gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 mt-0.5 text-slate-400"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <p>When a contact reaches this node, the current flow ends and the selected flow starts from the beginning.</p>
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
              :disabled="keywordError && editForm.create_trigger"
              class="px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
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
import { ref, reactive, computed, inject, watch } from 'vue'
import { Handle, Position, useVueFlow, useNode } from '@vue-flow/core'
import axios from 'axios'
import FormCheckbox from '../../../../../../../resources/js/Components/FormCheckbox.vue'

const props = defineProps({
  id: String,
  data: Object,
  selected: Boolean,
})

const { removeNodes, edges, nodes } = useVueFlow()
const node = useNode()

const availableFlows = inject('activeFlows', [])
const fields = computed(() => props.data?.metadata?.fields || {})
const isActive = computed(() => props.data?.is_active !== false)

const availableFlowsList = computed(() => availableFlows.value || availableFlows)

const showEditModal = ref(false)
const detectedButtonTitle = ref('')

// Keyword validation state
const keywordChecking = ref(false)
const keywordAvailable = ref(null)
const keywordError = ref('')

let keywordCheckTimeout = null

const displayName = computed(() => {
  if (fields.value.target_flow_name) {
    return 'Portal: ' + fields.value.target_flow_name
  }
  return 'Portal'
})

const editForm = reactive({
  target_flow_uuid: '',
  target_flow_name: '',
  target_flow_id: null,
  trigger_name: '',
  trigger_keyword: '',
  trigger_created: false,
  trigger_uuid: '',
  create_trigger: true,
  is_active: true,
})

/**
 * Detect the button title from incoming edges.
 * Maps button handle IDs (a,b,c) to button labels (button1,button2,button3).
 */
const detectButtonTitle = () => {
  const allEdges = edges.value || []
  const allNodes = nodes.value || []

  // Find incoming edges to this node
  const incomingEdges = allEdges.filter(e => e.target === props.id)

  for (const edge of incomingEdges) {
    const sourceNode = allNodes.find(n => n.id === edge.source)
    if (!sourceNode || sourceNode.type !== 'buttons') continue

    const buttonFields = sourceNode.data?.metadata?.fields || {}
    const buttons = buttonFields.buttons || {}

    const handleMap = {
      'a': 'button1',
      'b': 'button2',
      'c': 'button3',
    }

    const handleId = edge.sourceHandle
    const buttonKey = handleMap[handleId]
    if (buttonKey && buttons[buttonKey]) {
      return buttons[buttonKey]
    }
  }

  return ''
}

const toggleEdit = () => {
  editForm.target_flow_uuid = fields.value.target_flow_uuid || ''
  editForm.target_flow_name = fields.value.target_flow_name || ''
  editForm.target_flow_id = fields.value.target_flow_id || null
  editForm.trigger_name = fields.value.trigger_name || ''
  editForm.trigger_keyword = fields.value.trigger_keyword || ''
  editForm.trigger_created = fields.value.trigger_created || false
  editForm.trigger_uuid = fields.value.trigger_uuid || ''
  editForm.create_trigger = !fields.value.trigger_created ? true : true
  editForm.is_active = isActive.value

  // Detect button title from incoming edges
  const btnTitle = detectButtonTitle()
  detectedButtonTitle.value = btnTitle

  // Auto-fill trigger name/keyword if empty and we detected a button
  if (btnTitle && !editForm.trigger_name) {
    editForm.trigger_name = btnTitle
    editForm.trigger_keyword = btnTitle.toLowerCase().trim()
  }

  // Check keyword availability if keyword is set
  if (editForm.trigger_keyword) {
    checkKeywordAvailability()
  } else {
    keywordAvailable.value = null
    keywordError.value = ''
  }

  showEditModal.value = true
}

const closeEditModal = () => {
  showEditModal.value = false
  keywordAvailable.value = null
  keywordError.value = ''
}

const onFlowSelected = () => {
  const flows = availableFlows.value || availableFlows
  const selected = flows.find(f => f.uuid === editForm.target_flow_uuid)

  if (selected) {
    editForm.target_flow_name = selected.name
    editForm.target_flow_id = selected.id
  } else {
    editForm.target_flow_name = ''
    editForm.target_flow_id = null
  }
}

/**
 * Check keyword availability via API with debounce.
 */
const checkKeywordAvailability = () => {
  if (keywordCheckTimeout) {
    clearTimeout(keywordCheckTimeout)
  }

  const keyword = editForm.trigger_keyword.trim().toLowerCase()

  if (!keyword) {
    keywordAvailable.value = null
    keywordError.value = ''
    keywordChecking.value = false
    return
  }

  keywordChecking.value = true
  keywordError.value = ''
  keywordAvailable.value = null

  keywordCheckTimeout = setTimeout(async () => {
    try {
      const response = await axios.get('/automation/triggers/check-keyword', {
        params: {
          keyword: keyword,
          exclude: editForm.trigger_uuid || '',
        },
      })

      if (response.data.available) {
        keywordAvailable.value = true
        keywordError.value = ''
      } else {
        keywordAvailable.value = false
        const triggerName = response.data.trigger_name || 'another trigger'
        keywordError.value = 'Keyword already used in trigger: ' + triggerName
      }
    } catch (error) {
      // If the API call fails, don't block the user
      keywordAvailable.value = null
      keywordError.value = ''
    } finally {
      keywordChecking.value = false
    }
  }, 400)
}

/**
 * Save the portal node config and optionally create a trigger.
 */
const saveAction = async () => {
  // Validate keyword if creating trigger
  if (editForm.create_trigger && editForm.trigger_keyword) {
    if (keywordError.value) {
      return
    }

    // Final check before saving
    try {
      const response = await axios.get('/automation/triggers/check-keyword', {
        params: {
          keyword: editForm.trigger_keyword.trim().toLowerCase(),
          exclude: editForm.trigger_uuid || '',
        },
      })

      if (!response.data.available) {
        const triggerName = response.data.trigger_name || 'another trigger'
        keywordError.value = 'Keyword already used in trigger: ' + triggerName
        return
      }
    } catch (error) {
      // If API fails, continue — backend validation is the safety net
    }

    // Create the trigger
    try {
      const triggerResponse = await axios.post('/automation/triggers', {
        name: editForm.trigger_name || editForm.trigger_keyword,
        type: 'new_message',
        keywords: [editForm.trigger_keyword.trim().toLowerCase()],
        match_mode: 'exact',
        flow_id: editForm.target_flow_id,
        rate_limit_type: 'none',
      })

      editForm.trigger_created = true
      // The response from Inertia redirect won't have the trigger UUID easily,
      // so we'll mark it as created and let the user manage it from the Triggers page
    } catch (error) {
      const errors = error.response?.data?.errors
      if (errors) {
        const messages = Object.values(errors).flat().join(' ')
        alert('Failed to create trigger: ' + messages)
        return
      }
      alert('Failed to create trigger. Please check the Triggers page.')
      return
    }
  }

  const nodeFields = {
    type: 'portal',
    target_flow_uuid: editForm.target_flow_uuid,
    target_flow_name: editForm.target_flow_name,
    target_flow_id: editForm.target_flow_id,
    trigger_name: editForm.trigger_name,
    trigger_keyword: editForm.trigger_keyword.trim().toLowerCase(),
    trigger_created: editForm.trigger_created,
    trigger_uuid: editForm.trigger_uuid,
  }

  node.node.data = {
    ...node.node.data,
    title: editForm.target_flow_name ? 'Portal: ' + editForm.target_flow_name : 'Portal',
    metadata: {
      ...(node.node.data.metadata || {}),
      fields: nodeFields,
    },
    is_active: editForm.is_active,
  }

  closeEditModal()
}

const deleteNode = () => {
  if (confirm('Are you sure you want to delete this Portal node?')) {
    removeNodes([props.id])
  }
}
</script>

<style scoped>
.portal-node {
  position: relative;
}

.portal-node:hover {
  z-index: 10;
}
</style>