<template>
  <div class="metaflow-node">
    <Handle
      type="target"
      :position="Position.Top"
      class="w-3 h-3 !bg-purple-500"
    />

    <div class="bg-white border-2 border-purple-200 rounded-lg p-4 shadow-sm w-[400px]" style="min-width: 400px;">
      <div class="flex items-center mb-3">
        <div class="flex items-center gap-2 flex-1">
          <div class="w-8 h-8 bg-purple-50 rounded-lg flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-purple-600">
              <rect x="3" y="3" width="18" height="18" rx="2"/>
              <path d="M3 9h18"/>
              <path d="M9 21V9"/>
            </svg>
          </div>
          <div>
            <h3 class="font-medium text-gray-900 text-sm">{{ displayName }}</h3>
          </div>
        </div>
        <div class="flex items-center gap-1 ms-auto">
          <button
            @click="toggleEdit"
            class="p-1 text-gray-400 hover:text-gray-600 rounded"
            :title='$t("Edit MetaFlow")'
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
              <path d="m18.5 2.5 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
          </button>
          <button
            @click="deleteNode"
            class="p-1 text-red-400 hover:text-red-600 rounded"
            :title='$t("Delete MetaFlow")'
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
        <div v-if="fields.metaflow_uuid">
          <span class="font-medium">{{ $t("Flow:") }}</span> {{ fields.metaflow_name || 'Unknown' }}
        </div>
        <div v-else class="text-gray-400 italic">{{ $t("No flow selected") }}</div>
      </div>

      <div v-if="fields.body" class="text-xs text-gray-500 mb-2 line-clamp-2">
        {{ fields.body === 'Please complete the form.' ? 'يرجى إكمال النموذج.' : fields.body }}
      </div>

      <div class="flex items-center justify-between text-xs">
        <span class="text-gray-500">{{ $t("WhatsApp Flow") }}</span>
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

    <!-- Single exit handle -->
    <Handle
      type="source"
      :position="Position.Bottom"
      class="w-3 h-3 !bg-purple-500"
    />

    <!-- Edit Modal -->
    <div v-if="showEditModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" @wheel.stop>
      <div class="bg-white rounded-lg max-w-lg w-full mx-4 max-h-[80vh] overflow-y-auto" @wheel.stop>
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-semibold text-gray-900">{{ $t("Edit MetaFlow Message") }}</h3>
        </div>

        <form @submit.prevent="saveAction" class="p-6">
          <div class="space-y-4">
            <!-- MetaFlow selector -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t("Select Flow") }}</label>
              <select
                v-model="editForm.metaflow_uuid"
                @change="onFlowSelected"
                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 focus:border-purple-500 focus:ring-1 focus:ring-purple-500/30 focus:outline-none"
                required
              >
                <option value="">{{ $t("Choose a flow...") }}</option>
                <option v-for="flow in availableFlows" :key="flow.uuid" :value="flow.uuid">
                  {{ flow.name }}
                </option>
              </select>
              <div v-if="availableFlows.length === 0" class="text-xs text-red-500 mt-1"> {{ $t("No published flows available. Please create and publish a flow first.") }} </div>
            </div>

            <!-- Body text -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t("Body Text") }}</label>
              <textarea
                v-model="editForm.body"
                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 focus:border-purple-500 focus:ring-1 focus:ring-purple-500/30 focus:outline-none resize-none"
                rows="3"
                maxlength="1024"
                :placeholder='$t("Message shown above the flow card...")'
              ></textarea>
              <div class="flex justify-between text-xs text-gray-400 mt-1">
                <span>{{ $t("Use variables like") }} {{ variableExample }} {{ $t("for personalization") }}</span>
                <span>{{ bodyCharCount }}/1024</span>
              </div>
            </div>

            <!-- CTA button text -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t("Button Text (CTA)") }}</label>
              <input
                v-model="editForm.cta"
                type="text"
                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 focus:border-purple-500 focus:ring-1 focus:ring-purple-500/30 focus:outline-none"
                maxlength="20"
                :placeholder='$t("e.g. Open, Start, Continue")'
              />
              <div class="flex justify-between text-xs text-gray-400 mt-1">
                <span>{{ $t("The button label contacts tap to open the flow") }}</span>
                <span>{{ ctaCharCount }}/20</span>
              </div>
            </div>

            <!-- Flow info -->
            <div v-if="selectedFlowInfo" class="rounded-md bg-purple-50 border border-purple-200 px-3 py-2 text-xs text-purple-700">
              <p><strong>{{ selectedFlowInfo.name }}</strong></p>
            </div>

            <!-- Info callout -->
            <div class="rounded-md bg-slate-50 border border-slate-200 px-3 py-2 text-xs text-slate-500">
              <div class="flex items-start gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 mt-0.5 text-slate-400"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <p>{{ $t("The flow continues to the next step after the WhatsApp Flow message is sent.") }}</p>
              </div>
            </div>

            <!-- Active Status -->
            <div class="flex items-center">
              <FormCheckbox
                v-model="editForm.is_active"
                :label='$t("Active")'
                class="h-4 w-4 text-purple-600 focus:ring-purple-500 border-gray-300 rounded"
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
              class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors"
            > {{ $t("Save Changes") }} </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, inject } from 'vue'
import { Handle, Position, useVueFlow, useNode } from '@vue-flow/core'
import FormCheckbox from '../../../../../../../resources/js/Components/FormCheckbox.vue'

const props = defineProps({
  id: String,
  data: Object,
  selected: Boolean,
})

const { removeNodes } = useVueFlow()
const node = useNode()

const availableFlows = inject('metaFlows', [])
const fields = computed(() => props.data?.metadata?.fields || {})
const isActive = computed(() => props.data?.is_active !== false)

// Use string concatenation to avoid Vue SFC double-curly-brace syntax errors
const variableExample = '{' + '{contact.first_name}' + '}'

const bodyCharCount = computed(() => (editForm.body || '').length)
const ctaCharCount = computed(() => (editForm.cta || '').length)

const showEditModal = ref(false)

const selectedFlowInfo = computed(() => {
  if (!editForm.metaflow_uuid) return null
  const flows = availableFlows.value || availableFlows
  return flows.find(f => f.uuid === editForm.metaflow_uuid) || null
})

const displayName = computed(() => {
  if (fields.value.metaflow_name) {
    return fields.value.metaflow_name
  }
  return 'إرسال تدفق ميتا'
})

const editForm = reactive({
  metaflow_uuid: '',
  metaflow_name: '',
  body: 'يرجى إكمال النموذج.',
  cta: 'Open',
  is_active: true,
})

const toggleEdit = () => {
  editForm.metaflow_uuid = fields.value.metaflow_uuid || ''
  editForm.metaflow_name = fields.value.metaflow_name || ''
  editForm.body = fields.value.body === 'Please complete the form.' ? 'يرجى إكمال النموذج.' : (fields.value.body || 'يرجى إكمال النموذج.')
  editForm.cta = fields.value.cta || 'Open'
  editForm.is_active = isActive.value

  showEditModal.value = true
}

const closeEditModal = () => {
  showEditModal.value = false
}

const onFlowSelected = () => {
  const flows = availableFlows.value || availableFlows
  const selected = flows.find(f => f.uuid === editForm.metaflow_uuid)

  if (selected) {
    editForm.metaflow_name = selected.name
  } else {
    editForm.metaflow_name = ''
  }
}

const saveAction = () => {
  const nodeFields = {
    type: 'metaflow',
    metaflow_uuid: editForm.metaflow_uuid,
    metaflow_name: editForm.metaflow_name,
    body: editForm.body,
    cta: editForm.cta,
  }

  node.node.data = {
    ...node.node.data,
    title: editForm.metaflow_name || 'إرسال تدفق ميتا',
    metadata: {
      ...(node.node.data.metadata || {}),
      fields: nodeFields,
    },
    is_active: editForm.is_active,
  }

  closeEditModal()
}

const deleteNode = () => {
  if (confirm('هل أنت متأكد من حذف عقدة تدفق ميتا؟')) {
    removeNodes([props.id])
  }
}
</script>

<style scoped>
.metaflow-node {
  position: relative;
}

.metaflow-node:hover {
  z-index: 10;
}
</style>
