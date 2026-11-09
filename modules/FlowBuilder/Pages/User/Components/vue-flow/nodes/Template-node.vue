<template>
  <div class="template-node">
    <Handle
      type="target"
      :position="Position.Top"
      class="w-3 h-3 !bg-blue-500"
    />

    <div class="bg-white border-2 border-blue-200 rounded-lg p-4 shadow-sm w-[400px]" style="min-width: 400px;">
      <div class="flex items-center mb-3">
        <div class="flex items-center gap-2 flex-1">
          <div class="w-8 h-8 bg-slate-50 rounded-lg flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-blue-600">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
              <polyline points="22,6 12,13 2,6"/>
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
            :title='$t("Edit Template")'
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
              <path d="m18.5 2.5 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
          </button>
          <button
            @click="deleteNode"
            class="p-1 text-red-400 hover:text-red-600 rounded"
            :title='$t("Delete Template")'
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
        <div v-if="fields.template_uuid">
          <span class="font-medium">{{ $t("Template:") }}</span> {{ fields.template_name || 'Unknown' }}
          <span class="text-gray-400 ml-1">({{ fields.template_language || '?' }})</span>
        </div>
        <div v-else class="text-gray-400 italic">{{ $t("No template selected") }}</div>
      </div>

      <div class="flex items-center justify-between text-xs">
        <span class="text-gray-500">{{ $t("Message") }}</span>
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
      class="w-3 h-3 !bg-blue-500"
    />

    <!-- Edit Modal -->
    <div v-if="showEditModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" @wheel.stop>
      <div class="bg-white rounded-lg max-w-lg w-full mx-4 max-h-[80vh] overflow-y-auto" @wheel.stop>
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-semibold text-gray-900">{{ $t("Edit Template Message") }}</h3>
        </div>

        <form @submit.prevent="saveAction" class="p-6">
          <div class="space-y-4">
            <!-- Template selector -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t("Select Template") }}</label>
              <select
                v-model="editForm.template_uuid"
                @change="onTemplateSelected"
                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-800 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none"
                required
              >
                <option value="">{{ $t("Choose a template...") }}</option>
                <option v-for="tpl in availableTemplates" :key="tpl.uuid" :value="tpl.uuid">
                  {{ tpl.name }} ({{ tpl.language }})
                </option>
              </select>
              <div v-if="availableTemplates.length === 0" class="text-xs text-red-500 mt-1"> {{ $t("No approved templates available. Please create and approve templates first.") }} </div>
            </div>

            <!-- Loading state -->
            <div v-if="loadingTemplate" class="flex items-center justify-center py-4">
              <svg class="animate-spin h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <span class="ms-2 text-sm text-gray-500">{{ $t("Loading template details...") }}</span>
            </div>

            <!-- Template parameters -->
            <div v-if="!loadingTemplate && templateDetails && hasParameters" class="space-y-3">
              <label class="block text-sm font-medium text-gray-700">{{ $t("Template Parameters") }}</label>

              <!-- Header parameters -->
              <div v-if="headerParamCount > 0" class="space-y-2">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">{{ $t("Header") }}</p>
                <div v-for="(_, index) in headerParamCount" :key="'h'+index" class="flex items-center gap-2">
                  <span class="text-xs text-gray-400 w-6">{{ paramLabel(index + 1) }}</span>
                  <input
                    v-model="editForm.parameters.header[index].value"
                    type="text"
                    class="flex-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none"
                    :placeholder="'Header parameter ' + (index + 1)"
                  />
                </div>
              </div>

              <!-- Body parameters -->
              <div v-if="bodyParamCount > 0" class="space-y-2">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">{{ $t("Body") }}</p>
                <div v-for="(_, index) in bodyParamCount" :key="'b'+index" class="flex items-center gap-2">
                  <span class="text-xs text-gray-400 w-6">{{ paramLabel(headerParamCount + index + 1) }}</span>
                  <input
                    v-model="editForm.parameters.body[index].value"
                    type="text"
                    class="flex-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none"
                    :placeholder="'Body parameter ' + (index + 1)"
                  />
                </div>
              </div>

              <!-- Placeholder hint -->
              <div class="rounded-md bg-gray-50 border border-gray-200 px-3 py-2 text-xs text-gray-500">
                <div class="flex items-start gap-2">
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 mt-0.5 text-gray-400"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                  <p>{{ $t("Use") }} <code class="bg-gray-100 px-1 rounded">{first_name}</code>, <code class="bg-gray-100 px-1 rounded">{email}</code>{{ $t(", or") }} <code class="bg-gray-100 px-1 rounded">{{ flowVarExample }}</code> {{ $t("to personalize.") }}</p>
                </div>
              </div>
            </div>

            <!-- No parameters message -->
            <div v-if="!loadingTemplate && templateDetails && !hasParameters" class="text-xs text-gray-400"> {{ $t("This template has no parameters to fill in.") }} </div>

            <!-- Template info -->
            <div v-if="templateDetails" class="rounded-md bg-blue-50 border border-blue-200 px-3 py-2 text-xs text-blue-700">
              <p><strong>{{ templateDetails.name }}</strong> &middot; {{ templateDetails.language }} &middot; {{ templateDetails.category }}</p>
            </div>

            <!-- Info callout -->
            <div class="rounded-md bg-slate-50 border border-slate-200 px-3 py-2 text-xs text-slate-500">
              <div class="flex items-start gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 mt-0.5 text-slate-400"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <p>{{ $t("Template messages are approved by WhatsApp. The flow continues to the next step after sending.") }}</p>
              </div>
            </div>

            <!-- Active Status -->
            <div class="flex items-center">
              <FormCheckbox
                v-model="editForm.is_active"
                :label='$t("Active")'
                class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
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
              class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
            > {{ $t("Save Changes") }} </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, inject } from 'vue'
import { Handle, Position, useVueFlow, useNode } from '@vue-flow/core'
import axios from 'axios'
import FormCheckbox from '../../../../../../../resources/js/Components/FormCheckbox.vue'

const props = defineProps({
  id: String,
  data: Object,
  selected: Boolean,
})

const { removeNodes } = useVueFlow()
const node = useNode()

const availableTemplates = inject('templates', [])
const fields = computed(() => props.data?.metadata?.fields || {})
const isActive = computed(() => props.data?.is_active !== false)

const flowVarExample = '{' + '{flow.variable}' + '}'

function paramLabel(num) {
  return '{' + '{' + num + '}' + '}'
}

const showEditModal = ref(false)
const loadingTemplate = ref(false)
const templateDetails = ref(null)

const displayName = computed(() => {
  if (fields.value.template_name) {
    return fields.value.template_name
  }
  return 'إرسال قالب'
})

const headerParamCount = computed(() => {
  return templateDetails.value?.headerParams || 0
})

const bodyParamCount = computed(() => {
  return templateDetails.value?.bodyParams || 0
})

const hasParameters = computed(() => {
  return headerParamCount.value > 0 || bodyParamCount.value > 0
})

const editForm = reactive({
  template_uuid: '',
  template_name: '',
  template_language: '',
  parameters: {
    header: [],
    body: [],
  },
  is_active: true,
})

const toggleEdit = () => {
  editForm.template_uuid = fields.value.template_uuid || ''
  editForm.template_name = fields.value.template_name || ''
  editForm.template_language = fields.value.template_language || ''
  editForm.parameters = {
    header: (fields.value.parameters?.header || []).map(p => ({ ...p })),
    body: (fields.value.parameters?.body || []).map(p => ({ ...p })),
  }
  editForm.is_active = isActive.value

  // Load template details if one is selected
  if (editForm.template_uuid) {
    loadTemplateDetails(editForm.template_uuid)
  } else {
    templateDetails.value = null
  }

  showEditModal.value = true
}

const closeEditModal = () => {
  showEditModal.value = false
}

const onTemplateSelected = () => {
  const selected = availableTemplates.value
    ? availableTemplates.value.find(t => t.uuid === editForm.template_uuid)
    : availableTemplates.find(t => t.uuid === editForm.template_uuid)

  if (selected) {
    editForm.template_name = selected.name
    editForm.template_language = selected.language
  }

  if (editForm.template_uuid) {
    loadTemplateDetails(editForm.template_uuid)
  } else {
    templateDetails.value = null
    editForm.parameters = { header: [], body: [] }
  }
}

const loadTemplateDetails = async (uuid) => {
  loadingTemplate.value = true
  try {
    const response = await axios.get(`/api/v1/templates/${uuid}`)
    const data = response.data?.data || response.data
    templateDetails.value = data

    // Parse components to get parameter counts
    const components = data?.components || {}
    let headerParams = 0
    let bodyParams = 0

    // Count header parameters
    if (components.header && components.header.format === 'TEXT' && components.header.text) {
      headerParams = (components.header.text.match(/\{\{\d+\}\}/g) || []).length
    } else if (components.header && components.header.parameters) {
      headerParams = components.header.parameters.length
    }

    // Count body parameters
    if (components.body && components.body.text) {
      bodyParams = (components.body.text.match(/\{\{\d+\}\}/g) || []).length
    } else if (components.body && components.body.parameters) {
      bodyParams = components.body.parameters.length
    }

    templateDetails.value.headerParams = headerParams
    templateDetails.value.bodyParams = bodyParams

    // Initialize parameter arrays with existing values or empty
    const existingHeader = editForm.parameters.header || []
    const existingBody = editForm.parameters.body || []

    editForm.parameters = {
      header: Array.from({ length: headerParams }, (_, i) => ({
        value: existingHeader[i]?.value || '',
        type: 'text',
      })),
      body: Array.from({ length: bodyParams }, (_, i) => ({
        value: existingBody[i]?.value || '',
        type: 'text',
      })),
    }
  } catch (error) {
    console.error('Failed to load template details:', error)
    templateDetails.value = null
  } finally {
    loadingTemplate.value = false
  }
}

const saveAction = () => {
  const nodeFields = {
    type: 'template',
    template_uuid: editForm.template_uuid,
    template_name: editForm.template_name,
    template_language: editForm.template_language,
    parameters: editForm.parameters,
  }

  node.node.data = {
    ...node.node.data,
    title: editForm.template_name || 'Send Template',
    metadata: {
      ...(node.node.data.metadata || {}),
      fields: nodeFields,
    },
    is_active: editForm.is_active,
  }

  closeEditModal()
}

const deleteNode = () => {
  if (confirm('هل أنت متأكد من حذف عقدة القالب؟')) {
    removeNodes([props.id])
  }
}

onMounted(() => {
  // Node data loaded
})
</script>

<style scoped>
.template-node {
  position: relative;
}

.template-node:hover {
  z-index: 10;
}
</style>
