<template>
  <div class="webhook-node">
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
              <circle cx="12" cy="12" r="10"/>
              <path d="M2 12h20"/>
              <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
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
          <span class="font-medium">Webhook:</span> {{ config.url }}
        </div>
        <div v-if="config.response_mapping && config.response_mapping.length > 0" class="mt-1">
          <span class="text-gray-500">{{ config.response_mapping.length }} response mapping(s)</span>
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
    <div v-if="showEditModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" @wheel.stop>
      <div class="bg-white rounded-lg max-w-md w-full mx-4 max-h-[80vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-semibold text-gray-900">Edit Webhook Action</h3>
        </div>

        <form @submit.prevent="saveAction" class="p-6">
          <div class="space-y-4">
            <!-- URL -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">URL</label>
              <FormInput
                v-model="editForm.config.url"
                type="url"
                placeholder="https://api.example.com/webhook"
                required
              />
              <span class="text-xs text-gray-400">Use flow.variable_name syntax to insert flow variables</span>
            </div>

            <!-- Method -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Method</label>
              <FormSelect
                v-model="editForm.config.method"
                name="Method"
                :options="[
                  { value: 'GET', label: 'GET' },
                  { value: 'POST', label: 'POST' },
                  { value: 'PUT', label: 'PUT' },
                  { value: 'PATCH', label: 'PATCH' },
                  { value: 'DELETE', label: 'DELETE' }
                ]"
                required
              />
            </div>

            <!-- Headers -->
            <div>
              <div class="flex items-center justify-between mb-2">
                <label class="block text-sm font-medium text-gray-700">Headers</label>
                <button type="button" @click="addHeader" class="text-xs text-teal-600 hover:text-teal-700">+ Add Header</button>
              </div>
              <div v-for="(header, index) in editForm.config.headers" :key="index" class="flex gap-2 mb-2">
                <FormInput v-model="header.key" placeholder="Header name" class="flex-1" />
                <FormInput v-model="header.value" placeholder="Value" class="flex-1" />
                <button type="button" @click="editForm.config.headers.splice(index, 1)" class="text-red-400 hover:text-red-600 px-1">&times;</button>
              </div>
              <p v-if="!editForm.config.headers || editForm.config.headers.length === 0" class="text-xs text-gray-400">No custom headers</p>
            </div>

            <!-- Body -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Custom Body (JSON, optional)</label>
              <textarea
                v-model="editForm.config.body"
                class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono"
                rows="4"
                placeholder='{"key": "value"}'
              ></textarea>
              <span class="text-xs text-gray-400">Merges with contact data. Use flow.* and contact.* syntax for variables.</span>
            </div>

            <!-- Response Mapping -->
            <div>
              <div class="flex items-center justify-between mb-2">
                <label class="block text-sm font-medium text-gray-700">Response Mapping</label>
                <button type="button" @click="addMapping" class="text-xs text-teal-600 hover:text-teal-700">+ Add Mapping</button>
              </div>
              <div v-for="(map, index) in editForm.config.response_mapping" :key="index" class="flex gap-2 mb-2">
                <div class="flex-1">
                  <FormInput v-model="map.response_field" placeholder="Response field (e.g. status)" />
                </div>
                <div class="flex items-center text-gray-400">&#8594;</div>
                <div class="flex-1">
                  <FormInput v-model="map.save_to_variable" placeholder="Save to variable (e.g. order_status)" />
                </div>
                <button type="button" @click="editForm.config.response_mapping.splice(index, 1)" class="text-red-400 hover:text-red-600 px-1">&times;</button>
              </div>
              <p v-if="!editForm.config.response_mapping || editForm.config.response_mapping.length === 0" class="text-xs text-gray-400">Map webhook response fields to flow variables</p>
            </div>

            <!-- Info box -->
            <div class="bg-gray-50 border border-blue-200 rounded-lg p-3">
              <p class="text-xs text-gray-600 mb-1">
                <strong>What happens:</strong> A webhook request will be sent to the specified URL with the contact data and flow variables.
              </p>
              <p class="text-xs text-gray-500">
                Response fields can be mapped to flow variables for use in later nodes.
              </p>
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
import FormInput from '../../../../../../../../resources/js/Components/FormInput.vue'

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

const actionName = computed(() => {
  return 'Webhook'
})

const editForm = reactive({
  config: {},
  is_active: true
})

const toggleEdit = () => {
  editForm.config = {
    url: config.value.url || '',
    method: config.value.method || 'POST',
    headers: (config.value.headers || []).map(h => ({ ...h })),
    body: config.value.body || '',
    response_mapping: (config.value.response_mapping || []).map(m => ({ ...m })),
  }
  editForm.is_active = isActive.value
  showEditModal.value = true
}

const closeEditModal = () => {
  showEditModal.value = false
}

const addHeader = () => {
  if (!editForm.config.headers) editForm.config.headers = []
  editForm.config.headers.push({ key: '', value: '' })
}

const addMapping = () => {
  if (!editForm.config.response_mapping) editForm.config.response_mapping = []
  editForm.config.response_mapping.push({ response_field: '', save_to_variable: '' })
}

const saveAction = () => {
  // Clean up empty headers and mappings before saving
  const cleanHeaders = (editForm.config.headers || []).filter(h => h.key || h.value)
  const cleanMappings = (editForm.config.response_mapping || []).filter(m => m.response_field || m.save_to_variable)

  node.node.data = {
    ...node.node.data,
    actionType: 'webhook',
    config: {
      url: editForm.config.url,
      method: editForm.config.method,
      headers: cleanHeaders,
      body: editForm.config.body || null,
      response_mapping: cleanMappings,
    },
    is_active: editForm.is_active
  }

  closeEditModal()
}

const deleteNode = () => {
  if (confirm('Are you sure you want to delete this action?')) {
    removeNodes([props.id])
  }
}

onMounted(() => {
  // Node data loaded
})
</script>

<style scoped>
.webhook-node {
  position: relative;
}

.webhook-node:hover {
  z-index: 10;
}
</style>