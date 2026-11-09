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
        <div class="flex items-center gap-1 ms-auto">
          <span
            class="px-2 py-0.5 rounded-full text-xs font-medium"
            :class="fields.connection_mode === 'simple' ? 'bg-gray-100 text-gray-600' : 'bg-teal-100 text-teal-700'"
          >
            {{ fields.connection_mode === 'simple' ? 'Simple' : 'With Trigger' }}
          </span>
          <button
            @click="toggleEdit"
            class="p-1 text-gray-400 hover:text-gray-600 rounded"
            :title='$t("Edit Portal")'
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
              <path d="m18.5 2.5 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
          </button>
          <button
            @click="deleteNode"
            class="p-1 text-red-400 hover:text-red-600 rounded"
            :title='$t("Delete Portal")'
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
          <span class="font-medium">{{ $t("Target:") }}</span> {{ fields.target_flow_name || 'Unknown' }}
        </div>
        <div v-else class="text-gray-400 italic">{{ $t("No target flow selected") }}</div>
      </div>

      <div v-if="fields.connection_mode !== 'simple' && fields.trigger_keyword" class="text-xs text-gray-500 mb-2"> {{ $t("Keyword:") }} <span class="font-mono bg-gray-100 px-1 rounded">{{ fields.trigger_keyword }}</span>
        <span v-if="fields.trigger_created" class="text-green-500 ml-1">{{ $t("(trigger created)") }}</span>
        <span v-if="keywordDuplicate" class="text-red-500 ml-1 font-medium">{{ $t("(duplicate!)") }}</span>
      </div>

      <div class="flex items-center justify-between text-xs">
        <span class="text-gray-500">{{ $t("Terminal Node") }}</span>
        <div class="flex items-center gap-1">
          <span
            :class="isActive ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'"
            class="px-2 py-1 rounded-full text-xs"
          >
            {{ $t(isActive ? 'Active' : 'Inactive') }}
          </span>
        </div>
      </div>
    </div>

    <!-- Edit Modal -->
    <div v-if="showEditModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" @wheel.stop>
      <div class="bg-white rounded-lg max-w-lg w-full mx-4 max-h-[80vh] overflow-y-auto" @wheel.stop>
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-semibold text-gray-900">{{ $t("Edit Portal Node") }}</h3>
        </div>

        <form @submit.prevent="saveAction" class="p-6">
          <div class="space-y-4">
            <!-- Target flow selector -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t("Target Flow") }}</label>
              <select
                v-model="editForm.target_flow_uuid"
                @change="onFlowSelected"
                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 focus:border-teal-500 focus:ring-1 focus:ring-teal-500/30 focus:outline-none"
                required
              >
                <option value="">{{ $t("Choose a flow...") }}</option>
                <option v-for="flow in availableFlowsList" :key="flow.uuid" :value="flow.uuid">
                  {{ flow.name }}
                </option>
              </select>
              <div v-if="availableFlowsList.length === 0" class="text-xs text-red-500 mt-1"> {{ $t("No active flows available. Please publish a flow first.") }} </div>
            </div>

            <!-- Connection mode dropdown -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t("Connection Mode") }}</label>
              <select
                v-model="editForm.connection_mode"
                @change="onConnectionModeChange"
                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 focus:border-teal-500 focus:ring-1 focus:ring-teal-500/30 focus:outline-none"
              >
                <option value="simple">{{ $t("Connect to a flow") }}</option>
                <option value="with_trigger">{{ $t("Connect to a flow with creating trigger and trigger keyword") }}</option>
              </select>
              <p class="text-xs text-gray-500 mt-1">
                {{ editForm.connection_mode === 'simple'
                    ? 'ينتقل إلى التدفق المستهدف دون إنشاء كلمة مفتاحية قابلة لإعادة الاستخدام.'
                    : 'ينشئ كلمة مفتاحية للمشغّل تلقائيًا من عنوان الزر أو صف القائمة، لتعمل أيضًا خارج هذا التدفق.' }}
              </p>
            </div>

            <!-- Trigger-related fields (only in with_trigger mode) -->
            <template v-if="editForm.connection_mode === 'with_trigger'">
              <!-- No source connected warning -->
              <div v-if="!detectedSourceTitle" class="rounded-md bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-700">
                <div class="flex items-start gap-2">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 mt-0.5 text-amber-400"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                  <p>{{ $t("No button or list connection detected. Connect this portal to a button or list row to auto-generate a keyword, or use \"Connect to a flow\" mode instead.") }}</p>
                </div>
              </div>

              <!-- Auto-detected source title info -->
              <div v-if="detectedSourceTitle" class="rounded-md bg-teal-50 border border-teal-200 px-3 py-2 text-xs text-teal-700">
                <div class="flex items-start gap-2">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 mt-0.5 text-teal-400"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                  <p>{{ $t("Auto-detected from") }} {{ detectedSourceType }}: <strong>{{ detectedSourceTitle }}</strong></p>
                </div>
              </div>

              <!-- Trigger name (editable) -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t("Trigger Name") }}</label>
                <input
                  v-model="editForm.trigger_name"
                  type="text"
                  class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 focus:border-teal-500 focus:ring-1 focus:ring-teal-500/30 focus:outline-none"
                  maxlength="255"
                  :placeholder='$t("e.g. Sales, Support")'
                />
              </div>

              <!-- Trigger keyword (read-only, auto-populated) -->
              <div v-if="detectedSourceTitle">
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t("Trigger Keyword") }}</label>
                <div class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-600 flex items-center gap-2">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-gray-400 shrink-0"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                  <span class="font-mono">{{ editForm.trigger_keyword }}</span>
                </div>
                <p class="text-xs text-gray-400 mt-1">{{ $t("Keyword is auto-generated from the connected") }} {{ detectedSourceType }} {{ $t("title and cannot be edited.") }}</p>
                <!-- Keyword availability indicator -->
                <div v-if="keywordChecking" class="mt-1">
                  <svg class="animate-spin h-4 w-4 text-gray-400 inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  <span class="text-xs text-gray-400 ml-1">{{ $t("Checking...") }}</span>
                </div>
                <div v-else-if="keywordError" class="text-xs text-red-500 mt-1">
                  {{ keywordError }}
                </div>
                <div v-else-if="keywordAvailable === true" class="text-xs text-green-500 mt-1"> {{ $t("Keyword is available") }} </div>
              </div>
            </template>

            <!-- Info callout -->
            <div class="rounded-md bg-slate-50 border border-slate-200 px-3 py-2 text-xs text-slate-500">
              <div class="flex items-start gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 mt-0.5 text-slate-400"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <p>{{ $t("When a contact reaches this node, the current flow ends and the selected flow starts from the beginning. This is a terminal node — no nodes can follow it.") }}</p>
              </div>
            </div>

            <!-- Active Status -->
            <div class="flex items-center">
              <FormCheckbox
                v-model="editForm.is_active"
                :label='$t("Active")'
                class="h-4 w-4 text-teal-600 focus:ring-teal-500 border-gray-300 rounded"
              />
            </div>
          </div>

          <div class="flex justify-end gap-3 mt-6">
            <button
              type="button"
              @click="closeEditModal"
              class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
            > {{ $t("Cancel") }} </button>
            <button
              type="submit"
              :disabled="isSaveDisabled"
              class="px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            > {{ $t("Save Changes") }} </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, inject, watch, onMounted } from 'vue'
import { Handle, Position, useVueFlow, useNode } from '@vue-flow/core'
import axios from 'axios'
import FormCheckbox from '../../../../../../../resources/js/Components/FormCheckbox.vue'

const props = defineProps({
  id: String,
  data: Object,
  selected: Boolean,
})

const { removeNodes, removeEdges, edges, nodes } = useVueFlow()
const node = useNode()

const availableFlows = inject('activeFlows', [])
const fields = computed(() => props.data?.metadata?.fields || {})
const isActive = computed(() => props.data?.is_active !== false)

const availableFlowsList = computed(() => availableFlows.value || availableFlows)

const showEditModal = ref(false)
const detectedSourceTitle = ref('')
const detectedSourceType = ref('')

// Keyword validation state
const keywordChecking = ref(false)
const keywordAvailable = ref(null)
const keywordError = ref('')
const keywordDuplicate = ref(false)

let keywordCheckTimeout = null

const displayName = computed(() => {
  if (fields.value.target_flow_name) {
    return 'بوابة: ' + fields.value.target_flow_name
  }
  return 'بوابة'
})

const editForm = reactive({
  target_flow_uuid: '',
  target_flow_name: '',
  target_flow_id: null,
  connection_mode: 'with_trigger',
  trigger_name: '',
  trigger_keyword: '',
  trigger_created: false,
  trigger_uuid: '',
  is_active: true,
})

const isSaveDisabled = computed(() => {
  if (editForm.connection_mode === 'with_trigger' && keywordError.value) {
    return true
  }
  if (editForm.connection_mode === 'with_trigger' && !detectedSourceTitle.value) {
    return true
  }
  return false
})

// Clean up any outgoing edges from portal nodes (portal is a terminal node)
onMounted(() => {
  const outgoingEdges = edges.value.filter(e => e.source === props.id)
  if (outgoingEdges.length > 0) {
    outgoingEdges.forEach(e => removeEdges(e.id))
  }

  // Check if current keyword has a duplicate (for node body display)
  if (fields.value.trigger_keyword && !fields.value.trigger_created) {
    keywordDuplicate.value = false
  }
})

/**
 * Detect the source title from incoming edges.
 * Supports both buttons and list nodes.
 */
const detectSourceTitle = () => {
  const allEdges = edges.value || []
  const allNodes = nodes.value || []

  // Find incoming edges to this node
  const incomingEdges = allEdges.filter(e => e.target === props.id)

  for (const edge of incomingEdges) {
    const sourceNode = allNodes.find(n => n.id === edge.source)
    if (!sourceNode) continue

    // Handle buttons node
    if (sourceNode.type === 'buttons') {
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
        detectedSourceType.value = 'الزر'
        return buttons[buttonKey]
      }
    }

    // Handle list node
    if (sourceNode.type === 'list') {
      const listFields = sourceNode.data?.metadata?.fields || {}
      const sections = listFields.sections || []
      const handleId = edge.sourceHandle

      if (handleId && handleId.startsWith('a')) {
        for (let si = 0; si < sections.length; si++) {
          for (let ri = 0; ri < sections[si].rows.length; ri++) {
            if (handleId === 'a' + si + ri) {
              const rowTitle = sections[si].rows[ri].title
              if (rowTitle) {
                detectedSourceType.value = 'صف القائمة'
                return rowTitle
              }
            }
          }
        }
      }
    }
  }

  return ''
}

const toggleEdit = () => {
  editForm.target_flow_uuid = fields.value.target_flow_uuid || ''
  editForm.target_flow_name = fields.value.target_flow_name || ''
  editForm.target_flow_id = fields.value.target_flow_id || null
  // Backward compat: infer connection_mode from existing data
  if (fields.value.connection_mode) {
    editForm.connection_mode = fields.value.connection_mode
  } else if (fields.value.trigger_created || fields.value.trigger_keyword) {
    editForm.connection_mode = 'with_trigger'
  } else {
    editForm.connection_mode = 'simple'
  }
  editForm.trigger_name = fields.value.trigger_name || ''
  editForm.trigger_keyword = fields.value.trigger_keyword || ''
  editForm.trigger_created = fields.value.trigger_created || false
  editForm.trigger_uuid = fields.value.trigger_uuid || ''
  editForm.is_active = isActive.value

  // Detect source title from incoming edges
  const sourceTitle = detectSourceTitle()
  detectedSourceTitle.value = sourceTitle

  // Auto-fill trigger name/keyword when in with_trigger mode
  if (editForm.connection_mode === 'with_trigger' && sourceTitle) {
    if (!editForm.trigger_name) {
      editForm.trigger_name = sourceTitle
    }
    editForm.trigger_keyword = sourceTitle.toLowerCase().trim()
  }

  // Check keyword availability if keyword is set and in with_trigger mode
  if (editForm.connection_mode === 'with_trigger' && editForm.trigger_keyword) {
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
  keywordDuplicate.value = false
}

const onConnectionModeChange = () => {
  if (editForm.connection_mode === 'simple') {
    // Clear trigger fields
    editForm.trigger_name = ''
    editForm.trigger_keyword = ''
    keywordAvailable.value = null
    keywordError.value = ''
    keywordDuplicate.value = false
  } else if (editForm.connection_mode === 'with_trigger') {
    // Re-detect source title and auto-populate
    const sourceTitle = detectSourceTitle()
    detectedSourceTitle.value = sourceTitle

    if (sourceTitle) {
      editForm.trigger_name = sourceTitle
      editForm.trigger_keyword = sourceTitle.toLowerCase().trim()
      checkKeywordAvailability()
    } else {
      editForm.trigger_name = ''
      editForm.trigger_keyword = ''
      keywordAvailable.value = null
      keywordError.value = ''
    }
  }
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
    keywordDuplicate.value = false
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
        keywordDuplicate.value = false
      } else {
        keywordAvailable.value = false
        const triggerName = response.data.trigger_name || 'another trigger'
        keywordError.value = 'Keyword already used in trigger: ' + triggerName
        keywordDuplicate.value = true
      }
    } catch (error) {
      keywordAvailable.value = null
      keywordError.value = ''
      keywordDuplicate.value = false
    } finally {
      keywordChecking.value = false
    }
  }, 400)
}

/**
 * Save the portal node config and optionally create a trigger.
 */
const saveAction = async () => {
  // In with_trigger mode, validate keyword
  if (editForm.connection_mode === 'with_trigger') {
    if (!detectedSourceTitle.value) {
      return
    }

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
        keywordDuplicate.value = true
        return
      }
    } catch (error) {
      // If API fails, continue — backend validation is the safety net
    }

    // Create the trigger via JSON API
    if (!editForm.trigger_created) {
      try {
        const triggerResponse = await axios.post('/automation/triggers/api-store', {
          name: editForm.trigger_name || editForm.trigger_keyword,
          type: 'new_message',
          keywords: [editForm.trigger_keyword.trim().toLowerCase()],
          match_mode: 'exact',
          flow_id: editForm.target_flow_id,
          rate_limit_type: 'none',
        })

        editForm.trigger_uuid = triggerResponse.data.uuid
        editForm.trigger_created = true
      } catch (error) {
        const errors = error.response?.data?.errors
        if (errors) {
          const messages = Object.values(errors).flat().join(' ')
          alert('Failed to create trigger: ' + messages)
          return
        }
        const errorMsg = error.response?.data?.error
        if (errorMsg) {
          alert('Failed to create trigger: ' + errorMsg)
          return
        }
        alert('Failed to create trigger. Please check the Triggers page.')
        return
      }
    }
  }

  const nodeFields = {
    type: 'portal',
    target_flow_uuid: editForm.target_flow_uuid,
    target_flow_name: editForm.target_flow_name,
    target_flow_id: editForm.target_flow_id,
    connection_mode: editForm.connection_mode,
  }

  // Include trigger fields only in with_trigger mode
  if (editForm.connection_mode === 'with_trigger') {
    nodeFields.trigger_name = editForm.trigger_name
    nodeFields.trigger_keyword = editForm.trigger_keyword.trim().toLowerCase()
    nodeFields.trigger_created = editForm.trigger_created
    nodeFields.trigger_uuid = editForm.trigger_uuid
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
  if (confirm('هل أنت متأكد من حذف عقدة البوابة؟')) {
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
