<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Handle, Position, useVueFlow, useNode } from '@vue-flow/core'
import { MoreHorizontalIcon } from 'lucide-vue-next'
import { Menubar, MenubarMenu, MenubarTrigger, MenubarContent, MenubarItem } from '@modules/FlowBuilder/Pages/User/Components/ui/menubar'
import FormInput from '@/Components/FormInput.vue'
import FormTextArea from '@/Components/FormTextArea.vue'
import FormSelect from '@/Components/FormSelect.vue'
import type { NodeProps } from '@vue-flow/core'

const props = defineProps<NodeProps>()

const node = useNode()
const { removeNodes, nodes, addNodes, removeEdges, edges } = useVueFlow()

const isEditTitle = ref(false)
const title = ref(props.data.title || 'Question')

const maxQuestionTextLength = 1024
const maxVariableNameLength = 50
const maxRetryMessageLength = 1024
const minRetries = 1
const maxRetries = 10

const fields = ref({
  type: 'question',
  question_text: props.data.metadata?.fields?.question_text || '',
  variable_name: props.data.metadata?.fields?.variable_name || '',
  input_type: props.data.metadata?.fields?.input_type || 'text',
  max_retries: props.data.metadata?.fields?.max_retries ?? 3,
  retry_message: props.data.metadata?.fields?.retry_message || '',
  validation_pattern: props.data.metadata?.fields?.validation_pattern || '',
})

function getCharsCount(input: string): number {
  return input.length
}

const inputTypeOptions = ref([
  { label: 'Text', value: 'text' },
  { label: 'Email', value: 'email' },
  { label: 'Phone', value: 'phone' },
  { label: 'Number', value: 'number' },
  { label: 'Date', value: 'date' },
  { label: 'Regex', value: 'regex' },
])

watch(fields, (newFields) => {
  node.node.data.metadata.fields = { ...newFields }
}, { deep: true })

const handleConnectable: HandleConnectableFunc = (node, connectedEdges) => {
  return connectedEdges.length <= 100
}

function handleClickDeleteBtn() {
  edges.value
    .filter(edge => edge.source === node.id || edge.target === node.id)
    .forEach(edge => removeEdges(edge.id))
  removeNodes(node.id)
}

function handleClickDuplicateBtn() {
  const { type, position, data } = node.node
  const newNode = {
    id: (nodes.value.length + 1).toString(),
    type,
    position: {
      x: position.x + 100,
      y: position.y + 100,
    },
    label: title.value,
    data: {
      ...data,
      metadata: {
        fields: { ...fields.value },
      },
    },
  }
  addNodes(newNode)
}

const shouldShowWarning = computed(() => {
  return !fields.value.question_text || !fields.value.variable_name
})

const shouldShowPattern = computed(() => {
  return fields.value.input_type === 'regex'
})

const inputTypeLabel = computed(() => {
  const opt = inputTypeOptions.value.find(o => o.value === fields.value.input_type)
  return opt ? opt.label : 'Text'
})

const variableHint = computed(() => {
  return 'Use in later nodes as ' + String.fromCharCode(123,123) + 'flow.' + fields.value.variable_name + String.fromCharCode(125,125)
})

function removeEdgesForHandle(handleId: string) {
  const edgesToRemove = edges.value.filter(edge => edge.sourceHandle === handleId)
  edgesToRemove.forEach(edge => removeEdges(edge.id))
}

// Clean up fail edge when input type doesn't need validation
watch(() => fields.value.input_type, (newType) => {
  if (newType === 'text') {
    removeEdgesForHandle(`fail|${node.id}`)
  }
})
</script>

<template>
  <div class="rounded-sm border border-gray-200 bg-white p-3 shadow-md min-w-[280px]">
    <Handle type="target" :position="Position.Left" :connectable="handleConnectable"/>

    <div class="flex flex-col gap-y-2">
      <div class="flex justify-between items-center">
        <div class="flex gap-x-2">
          <img src="~@/assets/images/icon_LLM.png" class="mt-1 h-4 w-4" alt="Question icon" />
          <div class="flex flex-col gap-y-1">
            <FormInput v-if="isEditTitle" v-model="title" :name="''" :type="'text'" :class="'col-span-4'" @blur="() => (isEditTitle = false)"/>
            <h3 class="text-base font-medium text-gray-900" v-else>{{ title }}</h3>
          </div>
        </div>

        <Menubar class="border-none">
          <menubar-menu>
            <menubar-trigger>
              <more-horizontal-icon />
            </menubar-trigger>
            <menubar-content>
              <menubar-item @click="handleClickDuplicateBtn"> Duplicate </menubar-item>
              <menubar-item @click="handleClickDeleteBtn"> Delete </menubar-item>
              <menubar-item @click="isEditTitle = true"> Rename </menubar-item>
            </menubar-content>
          </menubar-menu>
        </Menubar>
      </div>

      <div v-if="shouldShowWarning" class="flex items-center gap-x-2 bg-red-500 text-white rounded-md px-2 py-2">
        <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24"><g fill="none"><path d="m12.593 23.258l-.011.002l-.071.035l-.02.004l-.014-.004l-.071-.035q-.016-.005-.024.005l-.004.01l-.017.428l.005.02l.01.013l.104.074l.015.004l.012-.004l.104-.074l.012-.016l.004-.017l-.017-.427q-.004-.016-.017-.018m.265-.113l-.013.002l-.185.093l-.01.01l-.003.011l.018.43l.005.012l.008.007l.201.093q.019.005.029-.008l.004-.014l-.034-.614q-.005-.018-.02-.022m-.715.002a.02.02 0 0 0-.027.006l-.006.014l-.034.614q.001.018.017.024l.015-.002l.201-.093l.01-.008l.004-.011l.017-.43l-.003-.012l-.01-.01z"/><path fill="currentColor" d="m13.299 3.148l8.634 14.954a1.5 1.5 0 0 1-1.299 2.25H3.366a1.5 1.5 0 0 1-1.299-2.25l8.634-14.954c.577-1 2.02-1 2.598 0M12 15a1 1 0 1 0 0 2a1 1 0 0 0 0-2m0-7a1 1 0 0 0-.993.883L11 9v4a1 1 0 0 0 1.993.117L13 13V9a1 1 0 0 0-1-1"/></g></svg>
        <span class="text-sm">Fill required fields</span>
      </div>

      <span class="text-sm text-gray-500">
        Ask a question and store the answer in a variable.
      </span>

      <div class="space-y-3">
        <div>
          <label class="text-sm font-medium text-gray-700 mb-1 block">
            <span class="text-red-500">*</span> Question text
          </label>
          <FormTextArea v-model="fields.question_text" :placeholder="'e.g. What is your email address?'" :name="'question_text'" :type="'text'" :maxLength="maxQuestionTextLength" :class="'col-span-4'"/>
          <span class="text-xs text-gray-500">{{ getCharsCount(fields.question_text) }}/{{ maxQuestionTextLength }}</span>
        </div>

        <div>
          <label class="text-sm font-medium text-gray-700 mb-1 block">
            <span class="text-red-500">*</span> Save answer to variable
          </label>
          <FormInput v-model="fields.variable_name" :name="'variable_name'" :placeholder="'e.g. email'" :type="'text'" :maxLength="maxVariableNameLength" :class="'col-span-4'"/>
          <span class="text-xs text-gray-500">{{ getCharsCount(fields.variable_name) }}/{{ maxVariableNameLength }}</span>
          <span class="text-xs text-gray-400">{{ variableHint }}</span>
        </div>

        <div>
          <label class="text-sm font-medium text-gray-700 mb-1 block">Input type</label>
          <FormSelect v-model="fields.input_type" :name="'input_type'" :options="inputTypeOptions" :class="'col-span-4'"/>
        </div>

        <div v-if="shouldShowPattern">
          <label class="text-sm font-medium text-gray-700 mb-1 block">Validation pattern (regex)</label>
          <FormInput v-model="fields.validation_pattern" :name="'validation_pattern'" placeholder="e.g. ^[A-Z]{2}\d{6}$" :type="'text'" :class="'col-span-4'"/>
        </div>

        <div>
          <label class="text-sm font-medium text-gray-700 mb-1 block">Max retries</label>
          <FormInput v-model="fields.max_retries" :name="'max_retries'" :type="'number'" :min="minRetries" :max="maxRetries" :class="'col-span-4'"/>
          <span class="text-xs text-gray-400">How many times the user can retry before routing to the fail path</span>
        </div>

        <div>
          <label class="text-sm font-medium text-gray-700 mb-1 block">Retry message</label>
          <FormTextArea v-model="fields.retry_message" placeholder="e.g. That doesn&#39;t look like a valid email. Please try again." :name="'retry_message'" :type="'text'" :maxLength="maxRetryMessageLength" :class="'col-span-4'"/>
          <span class="text-xs text-gray-500">{{ getCharsCount(fields.retry_message) }}/{{ maxRetryMessageLength }}</span>
          <span class="text-xs text-gray-400">Shown when validation fails</span>
        </div>
      </div>

      <div class="flex items-center gap-2 mt-1 pt-2 border-t border-gray-100">
        <span class="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded">Question</span>
        <span class="text-xs text-gray-400">{{ inputTypeLabel }}</span>
      </div>

      <div class="relative border-t border-gray-100 pt-2 mt-2">
        <div class="flex items-center justify-between">
          <span class="text-xs text-green-600">Valid answer</span>
        </div>
        <Handle :id="`success|${node.id}`" type="source" :position="Position.Right" :connectable="handleConnectable"
          style="right: -17px; background-color: #22c55e;"
        />
      </div>

      <div class="relative border-t border-gray-100 pt-2">
        <div class="flex items-center justify-between">
          <span class="text-xs text-red-500">Max retries exceeded</span>
        </div>
        <Handle :id="`fail|${node.id}`" type="source" :position="Position.Right" :connectable="handleConnectable"
          style="right: -17px; background-color: #ef4444;"
        />
      </div>
    </div>
  </div>
</template>