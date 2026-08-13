<template>
  <div class="label-node">
    <Handle
      type="target"
      :position="Position.Top"
      class="w-3 h-3 !bg-purple-500"
    />

    <div class="bg-white border-2 border-purple-200 rounded-lg p-4 shadow-sm w-[400px]" style="min-width: 400px;">
      <div class="flex items-center mb-3">
        <div class="flex items-center gap-2 flex-1">
          <div class="w-8 h-8 bg-slate-50 rounded-lg flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-purple-600">
              <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
              <line x1="7" y1="7" x2="7.01" y2="7"/>
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
          <span class="font-medium">{{ config.action === 'add' ? 'Add labels:' : 'Remove labels:' }}</span>
          {{ selectedLabelNames }}
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
      class="w-3 h-3 !bg-purple-500"
    />

    <!-- Edit Modal -->
    <div v-if="showEditModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" @wheel.stop>
      <div class="bg-white rounded-lg max-w-md w-full mx-4 max-h-[80vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-semibold text-gray-900">Edit Label Action</h3>
        </div>

        <form @submit.prevent="saveAction" class="p-6">
          <div class="space-y-4">
            <!-- Action mode toggle -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Action</label>
              <div class="flex rounded-lg border border-gray-200 overflow-hidden">
                <button
                  type="button"
                  @click="editForm.config.action = 'add'"
                  class="flex-1 px-4 py-2 text-sm font-medium transition-colors"
                  :class="editForm.config.action === 'add'
                    ? 'bg-purple-600 text-white'
                    : 'bg-white text-gray-600 hover:bg-gray-50'"
                >
                  Add Labels
                </button>
                <button
                  type="button"
                  @click="editForm.config.action = 'remove'"
                  class="flex-1 px-4 py-2 text-sm font-medium transition-colors"
                  :class="editForm.config.action === 'remove'
                    ? 'bg-purple-600 text-white'
                    : 'bg-white text-gray-600 hover:bg-gray-50'"
                >
                  Remove Labels
                </button>
              </div>
            </div>

            <!-- Label selection -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Select Labels</label>
              <div v-if="availableLabels.length === 0" class="text-xs text-red-500">
                No labels available. Please create labels first.
              </div>
              <div v-else class="border border-gray-200 rounded-lg max-h-48 overflow-y-auto">
                <label
                  v-for="label in availableLabels"
                  :key="label.value"
                  class="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 cursor-pointer"
                >
                  <input
                    type="checkbox"
                    :value="label.value"
                    v-model="editForm.config.label_ids"
                    class="h-4 w-4 rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                  />
                  <span
                    class="w-3 h-3 rounded-full flex-shrink-0"
                    :style="{ backgroundColor: label.color || '#6b7280' }"
                  ></span>
                  <span class="text-sm text-gray-700">{{ label.label }}</span>
                </label>
              </div>
            </div>

            <!-- Info callout -->
            <div class="bg-gray-50 border border-blue-200 rounded-lg p-3">
              <p class="text-xs text-gray-600 mb-1">
                <strong>What happens:</strong>
                {{ editForm.config.action === 'add'
                  ? 'The selected labels will be added to the contact. If a label is already attached, it will be skipped.'
                  : 'The selected labels will be removed from the contact. If a label is not attached, it will be skipped.'
                }}
              </p>
            </div>

            <!-- Active Status -->
            <div class="flex items-center">
              <FormCheckbox
                v-model="editForm.is_active"
                label="Active"
                class="h-4 w-4 text-purple-600 focus:ring-purple-500 border-gray-300 rounded"
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
              class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors"
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
import { ref, reactive, computed, onMounted, inject, unref } from 'vue'
import { Handle, Position, useVueFlow, useNode } from '@vue-flow/core'
import FormCheckbox from '../../../../../../../../resources/js/Components/FormCheckbox.vue'

const props = defineProps({
  id: String,
  data: Object,
  selected: Boolean,
})

const { removeNodes } = useVueFlow()
const node = useNode()

// Inject labels from parent component
const availableLabels = inject('labels', [])
const labels = computed(() => unref(availableLabels) || [])

const config = computed(() => props.data?.config || {})
const isActive = computed(() => props.data?.is_active !== false)

const showEditModal = ref(false)

const selectedLabelNames = computed(() => {
  const ids = config.value.label_ids || []
  if (ids.length === 0) return 'No labels selected'
  const names = ids.map(id => {
    const label = labels.value.find(l => l.value == id)
    return label ? label.label : `ID: ${id}`
  })
  return names.join(', ')
})

const actionName = computed(() => {
  const action = config.value.action || 'add'
  const mode = action === 'add' ? 'Add' : 'Remove'
  const count = (config.value.label_ids || []).length
  if (count === 0) return `${mode} Labels`
  return `${mode} ${count} Label${count > 1 ? 's' : ''}`
})

const editForm = reactive({
  config: {},
  is_active: true
})

const toggleEdit = () => {
  editForm.config = {
    action: config.value.action || 'add',
    label_ids: [...(config.value.label_ids || [])]
  }
  editForm.is_active = isActive.value
  showEditModal.value = true
}

const closeEditModal = () => {
  showEditModal.value = false
}

const saveAction = () => {
  node.node.data = {
    ...node.node.data,
    actionType: 'label',
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

onMounted(() => {
  // Node data loaded
})
</script>

<style scoped>
.label-node {
  position: relative;
}

.label-node:hover {
  z-index: 10;
}
</style>
