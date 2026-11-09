<template>
  <div class="webhook-node" :dir="locale === 'ar' ? 'rtl' : 'ltr'">
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
            <h3 class="font-medium text-gray-900 text-sm">{{ $t(actionName) }}</h3>
          </div>
        </div>
        <div class="flex items-center gap-1 ms-auto">
          <button
            @click="toggleEdit"
            class="p-1 text-gray-400 hover:text-gray-600 rounded"
            title="تعديل الإجراء"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
              <path d="m18.5 2.5 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
          </button>
          <button
            @click="deleteNode"
            class="p-1 text-red-400 hover:text-red-600 rounded"
            title="حذف الإجراء"
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
          <span class="font-medium">ويب هوك:</span> <bdi dir="ltr">{{ config.url }}</bdi>
        </div>
        <div v-if="config.response_mapping && config.response_mapping.length > 0" class="mt-1">
          <span class="text-gray-500">عدد روابط حقول الاستجابة: {{ config.response_mapping.length }}</span>
        </div>
      </div>

      <div class="flex items-center justify-between text-xs">
        <span class="text-gray-500">إجراء</span>
        <div class="flex items-center gap-1">
          <span
            :class="isActive ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'"
            class="px-2 py-1 rounded-full text-xs"
          >
            {{ isActive ? 'نشط' : 'غير نشط' }}
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
          <h3 class="text-lg font-semibold text-gray-900">تعديل إجراء ويب هوك</h3>
        </div>

        <form @submit.prevent="saveAction" class="p-6">
          <div class="space-y-4">
            <!-- URL -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">عنوان الرابط</label>
              <FormInput
                v-model="editForm.config.url"
                type="url"
                dir="ltr"
                placeholder="https://api.example.com/webhook"
                required
              />
              <span class="text-xs text-gray-400">استخدم الصيغة <bdi dir="ltr">flow.variable_name</bdi> لإدراج متغيرات التدفق.</span>
            </div>

            <!-- Method -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">طريقة الطلب</label>
              <FormSelect
                v-model="editForm.config.method"
                name="طريقة الطلب"
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
                <label class="block text-sm font-medium text-gray-700">ترويسات الطلب</label>
                <button type="button" @click="addHeader" class="text-xs text-teal-600 hover:text-teal-700">+ إضافة ترويسة</button>
              </div>
              <div v-for="(header, index) in editForm.config.headers" :key="index" class="flex gap-2 mb-2">
                <FormInput v-model="header.key" dir="ltr" placeholder="اسم الترويسة" class="flex-1" />
                <FormInput v-model="header.value" dir="ltr" placeholder="القيمة" class="flex-1" />
                <button type="button" @click="editForm.config.headers.splice(index, 1)" class="text-red-400 hover:text-red-600 px-1">&times;</button>
              </div>
              <p v-if="!editForm.config.headers || editForm.config.headers.length === 0" class="text-xs text-gray-400">لا توجد ترويسات مخصصة</p>
            </div>

            <!-- Body -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">محتوى الطلب المخصص (JSON، اختياري)</label>
              <textarea
                v-model="editForm.config.body"
                class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono"
                rows="4"
                dir="ltr"
                placeholder='{"key": "value"}'
              ></textarea>
              <span class="text-xs text-gray-400">يُدمج مع بيانات جهة الاتصال. استخدم <bdi dir="ltr">flow.*</bdi> و<bdi dir="ltr">contact.*</bdi> لإدراج المتغيرات.</span>
            </div>

            <!-- Response Mapping -->
            <div>
              <div class="flex items-center justify-between mb-2">
                <label class="block text-sm font-medium text-gray-700">ربط حقول الاستجابة</label>
                <button type="button" @click="addMapping" class="text-xs text-teal-600 hover:text-teal-700">+ إضافة ربط</button>
              </div>
              <div v-for="(map, index) in editForm.config.response_mapping" :key="index" class="flex gap-2 mb-2">
                <div class="flex-1">
                  <FormInput v-model="map.response_field" dir="ltr" placeholder="حقل الاستجابة (مثل status)" />
                </div>
                <div class="flex items-center text-gray-400">←</div>
                <div class="flex-1">
                  <FormInput v-model="map.save_to_variable" dir="ltr" placeholder="حفظ في متغير (مثل order_status)" />
                </div>
                <button type="button" @click="editForm.config.response_mapping.splice(index, 1)" class="text-red-400 hover:text-red-600 px-1">&times;</button>
              </div>
              <p v-if="!editForm.config.response_mapping || editForm.config.response_mapping.length === 0" class="text-xs text-gray-400">اربط حقول استجابة ويب هوك بمتغيرات التدفق.</p>
            </div>

            <!-- Info box -->
            <div class="bg-gray-50 border border-blue-200 rounded-lg p-3">
              <p class="text-xs text-gray-600 mb-1">
                <strong>ما الذي سيحدث:</strong> سيُرسل طلب ويب هوك إلى الرابط المحدد، متضمنًا بيانات جهة الاتصال ومتغيرات التدفق.
              </p>
              <p class="text-xs text-gray-500">
                يمكنك ربط حقول الاستجابة بمتغيرات التدفق لاستخدامها في العقد التالية.
              </p>
            </div>

            <!-- Active Status -->
            <div class="flex items-center">
              <FormCheckbox
                v-model="editForm.is_active"
                label="نشط"
                class="h-4 w-4 text-teal-600 focus:ring-teal-500 border-gray-300 rounded"
              />
            </div>
          </div>

          <div class="flex justify-end gap-3 mt-6">
            <button
              type="button"
              @click="closeEditModal"
              class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
            >
              إلغاء
            </button>
            <button
              type="submit"
              class="px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-colors"
            >
              حفظ التغييرات
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
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
const { locale } = useI18n()

const config = computed(() => props.data?.config || {})
const isActive = computed(() => props.data?.is_active !== false)

const showEditModal = ref(false)

const actionName = computed(() => {
  return 'ويب هوك'
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
  if (confirm('هل أنت متأكد من حذف هذا الإجراء؟')) {
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
  text-align: start;
}

.webhook-node:hover {
  z-index: 10;
}
</style>
