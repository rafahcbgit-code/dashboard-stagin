<template>
  <div class="relative">
    <button v-if="showTrigger" type="button" @click="openModal" :aria-label="$t('Send template message to a specific number')"
      class="group relative inline-flex items-center justify-center gap-2 rounded-full bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-green-500/10 transition hover:bg-green-500 focus:outline-none focus:ring-2 focus:ring-green-400">
      <span class="text-base">+</span>
      <span
        class="pointer-events-none absolute right-0 top-full z-50 mt-2 w-max max-w-64 rounded-md bg-slate-900 px-3 py-2 text-center text-xs font-medium leading-5 text-white opacity-0 shadow-lg transition-opacity duration-150 group-hover:opacity-100 group-focus-visible:opacity-100"
        role="tooltip">
        <span class="block">{{ $t('Send template message to a specific number') }}</span>
      </span>
     </button>

    <transition name="fade">
      <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4" aria-modal="true" role="dialog" @click.self="closeModal">
        <div class="absolute inset-0 bg-black/40" @click="closeModal"></div>

        <div class="relative z-10 flex max-h-[calc(100vh-2rem)] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
          <div class="flex shrink-0 items-start justify-between gap-4 border-b border-slate-100 px-5 py-4">
            <div>
              <h3 class="text-sm font-semibold text-slate-800">{{ $t('Send template to phone') }}</h3>
              <p class="mt-1 text-xs text-slate-500">{{ $t('Quickly send a verified WhatsApp template message to any phone number.') }}</p>
            </div>
            <button type="button" @click="closeModal" :aria-label="$t('Close')"
              class="text-slate-400 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24">
                <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12"/>
              </svg>
            </button>
          </div>

          <div class="overflow-y-auto p-5">
            <div class="grid gap-4 md:grid-cols-2">
              <div>
                <label class="mb-1.5 block text-sm leading-6 text-gray-900">{{ $t('Phone number') }}</label>
                <div class="relative">
                  <input v-model="quickSendPhone" type="tel" inputmode="tel" autocomplete="tel"
                    @blur="validatePhone"
                    :class="['block w-full rounded-md border-0 px-4 py-2 text-gray-900 shadow-sm outline-none ring-1 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6', phoneError ? 'ring-[#b91c1c]' : 'ring-gray-300 focus:ring-2 focus:ring-primary']"
                    placeholder="+966501234567" maxlength="20" />
                </div>
                <p class="mt-2 text-xs leading-5 text-slate-500">{{ $t('Enter a phone number with country code (e.g., +966, +1, +44). Minimum 8 digits required.') }}</p>
                <p v-if="phoneError" class="mt-1 text-xs text-[#b91c1c]">{{ phoneError }}</p>
              </div>

              <div>
                <label class="mb-1.5 block text-sm leading-6 text-gray-900">{{ $t('Choose template') }}</label>
                <div ref="dropdownRef" class="space-y-2">
                  <button type="button" @click="toggleDropdown"
                    @keydown.down.prevent="onDropdownNavigation('down')"
                    @keydown.up.prevent="onDropdownNavigation('up')"
                    @keydown.enter.prevent="confirmHighlightedTemplate"
                    :aria-expanded="dropdownOpen"
                    :class="['w-full rounded-md border-0 px-4 py-2 text-left text-sm text-gray-900 shadow-sm outline-none ring-1 ring-inset transition', dropdownOpen ? 'bg-white ring-2 ring-primary' : 'bg-white ring-gray-300', templates.length === 0 ? 'cursor-not-allowed opacity-70' : '']"
                    class="flex items-center justify-between gap-2">
                    <span class="truncate">{{ selectedTemplateLabel || $t('-- Choose a template --') }}</span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                      <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                    </svg>
                  </button>

                  <div v-if="dropdownOpen" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-3 py-3">
                      <label class="block text-xs font-medium text-slate-600">{{ $t('Search templates') }}</label>
                      <input v-model="templateSearch"
                        @keydown.down.prevent="onDropdownNavigation('down')"
                        @keydown.up.prevent="onDropdownNavigation('up')"
                        @keydown.enter.prevent="confirmHighlightedTemplate"
                        class="mt-2 block w-full rounded-md border-0 px-3 py-2 text-sm text-gray-900 shadow-sm outline-none ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-primary"
                        type="search"
                        :placeholder="t('Search by template name...')" />
                    </div>
                    <ul class="max-h-56 overflow-y-auto p-2 text-sm">
                      <li v-if="filteredTemplates.length === 0" class="rounded-md px-3 py-2 text-slate-500">
                        {{ $t('No templates match your search.') }}
                      </li>
                      <li v-for="(template, index) in filteredTemplates" :key="template.uuid || template.id"
                        @click="selectTemplate(template)"
                        @mouseenter="highlightedIndex = index"
                        :class="['cursor-pointer rounded-md px-3 py-2 transition-colors', isTemplateSelected(template) ? 'bg-indigo-50 text-primary' : highlightedIndex === index ? 'bg-slate-50 text-slate-900' : 'text-slate-700 hover:bg-slate-50']">
                        <div class="flex items-start justify-between gap-3">
                          <span class="min-w-0">
                            <span class="block truncate font-semibold">{{ formatTemplateName(template) }}</span>
                            <span class="mt-1 line-clamp-2 block text-xs leading-5 text-slate-500">
                              {{ getTemplatePreviewText(template, true) }}
                            </span>
                          </span>
                          <span class="shrink-0 rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium uppercase text-slate-600">
                            {{ getTemplateType(template) }}</span>
                        </div>
                      </li>
                    </ul>
                  </div>
                </div>
                <p class="mt-2 text-xs leading-5 text-slate-500">{{ templates.length === 0 ? $t('No approved templates found.') : $t('Search and choose the best template for this phone number.') }}</p>
              </div>
            </div>

            <div class="mt-5 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs leading-5 text-slate-600">
              {{ $t('Only approved templates can be sent. Configure each template variable as a static value or a dynamic contact field.') }}
            </div>

            <div v-if="quickSendVariables.length" class="mt-5 rounded-md border border-slate-200 bg-white px-3 py-3">
              <div class="mb-3">
                <h4 class="text-sm font-semibold text-slate-800">{{ $t('Template variables') }}</h4>
                <p class="mt-1 text-xs leading-5 text-slate-500">
                  {{ $t('Static values are sent as entered. Dynamic values are resolved from the contact matching the phone number.') }}
                </p>
              </div>

              <div class="space-y-3">
                <div v-for="variable in quickSendVariables" :key="variable.key"
                  class="grid gap-3 rounded-md border border-slate-100 bg-slate-50 p-3 md:grid-cols-[minmax(0,1fr)_10rem_minmax(0,1.5fr)] md:items-end">
                  <div>
                    <span class="block text-sm font-medium text-slate-700">{{ variable.label }}</span>
                    <span class="mt-1 block text-xs text-slate-500">{{ variable.placeholder }}</span>
                  </div>

                  <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ $t('Variable type') }}</label>
                    <select v-model="variable.selection" @change="resetVariableValue(variable)"
                      class="block w-full rounded-md border-0 px-3 py-2 text-sm text-gray-900 shadow-sm outline-none ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-primary">
                      <option value="static">{{ $t('Static') }}</option>
                      <option value="dynamic">{{ $t('Dynamic') }}</option>
                    </select>
                  </div>

                  <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ $t('Value') }}</label>
                    <input v-if="variable.selection === 'static'" v-model="variable.value" type="text"
                      :placeholder="$t('Enter a static value')"
                      class="block w-full rounded-md border-0 px-3 py-2 text-sm text-gray-900 shadow-sm outline-none ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-primary" />
                    <select v-else v-model="variable.value"
                      class="block w-full rounded-md border-0 px-3 py-2 text-sm text-gray-900 shadow-sm outline-none ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-primary">
                      <option value="">{{ $t('-- Choose a contact field --') }}</option>
                      <option v-for="option in dynamicOptions" :key="option.value" :value="option.value">
                        {{ option.label }}
                      </option>
                    </select>
                    <p v-if="!String(variable.value || '').trim()" class="mt-1 text-xs text-[#b91c1c]">
                      {{ $t('A value is required for this variable.') }}
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="selectedTemplateHeaderMediaType" class="mt-5 rounded-md border border-slate-200 bg-white px-3 py-3">
              <label class="mb-2 block text-sm leading-6 text-gray-900">{{ $t('Header media') }}</label>
              <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <label for="quick-template-header-media"
                  class="inline-flex cursor-pointer justify-center rounded-md bg-slate-100 px-3 py-2 text-sm text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-200">
                  {{ $t('Upload new file') }}
                </label>
                <input id="quick-template-header-media" type="file" class="sr-only"
                  :accept="getFileAcceptAttribute(selectedTemplateHeaderMediaType)"
                  @change="handleQuickHeaderFileUpload" />
                <span class="truncate text-sm text-slate-600">
                  {{ quickSendHeaderFile ? quickSendHeaderFile.name : $t('Using template media') }}
                </span>
              </div>
              <p class="mt-2 text-xs leading-5 text-slate-500">
                {{ $t('Leave empty to use the media saved with the template.') }}
              </p>
              <p v-if="quickSendHeaderFileError" class="mt-1 text-xs text-[#b91c1c]">{{ quickSendHeaderFileError }}</p>
            </div>

            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <button type="button" @click="cancelModal"
                class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                {{ $t('Cancel') }}
              </button>
              <button type="button" @click="sendTemplateToNumber"
                :disabled="isSending || !canSend"
                class="inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                <span v-if="!isSending">{{ $t('Send template') }}</span>
                <span v-else>{{ $t('Sending...') }}</span>
              </button>
            </div>

            <div v-if="quickSendStatus" class="mt-4 rounded-md px-3 py-2 text-sm" :class="quickSendStatus.type === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'">
              {{ quickSendStatus.message }}
            </div>
          </div>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
  templates: {
    type: Array,
    default: () => []
  },
  showTrigger: {
    type: Boolean,
    default: true
  },
  openOnMount: {
    type: Boolean,
    default: false
  },
  contactFields: {
    type: [Array, Object],
    default: () => []
  }
});

const emit = defineEmits(['sent', 'closed']);

const showModal = ref(false);
const dropdownOpen = ref(false);
const templateSearch = ref('');
const quickSendPhone = ref('');
const quickSendTemplate = ref('');
const quickSendStatus = ref(null);
const quickSendHeaderFile = ref(null);
const quickSendHeaderFileError = ref('');
const phoneError = ref('');
const isSending = ref(false);
const highlightedIndex = ref(0);
const dropdownRef = ref(null);
const quickSendVariables = ref([]);

const normalizedPhone = computed(() => quickSendPhone.value.replace(/[^0-9]/g, ''));
const variablesAreValid = computed(() => quickSendVariables.value.every(
  (variable) => String(variable.value ?? '').trim() !== ''
));
const canSend = computed(() => normalizedPhone.value.length >= 8
  && selectedTemplate.value !== null
  && variablesAreValid.value);

const baseDynamicOptions = [
  { value: 'first name', label: t('Contact first name') },
  { value: 'last name', label: t('Contact last name') },
  { value: 'name', label: t('Contact full name') },
  { value: 'phone', label: t('Contact phone') },
  { value: 'email', label: t('Contact email') },
];

const dynamicOptions = computed(() => {
  let fields = props.contactFields;
  if (!Array.isArray(fields)) {
    fields = Array.isArray(fields?.data) ? fields.data : Object.values(fields || {});
  }

  const customOptions = fields.map((field) => ({
    value: field?.slug || field?.name || field?.label || field?.key || field?.field_name || field?.id,
    label: field?.label || field?.name || field?.display_name || field?.slug || field?.field_name || field?.title,
  })).filter((field) => field.value && field.label);

  return [...new Map([...baseDynamicOptions, ...customOptions].map((option) => [String(option.value), option])).values()];
});

const formattedTemplates = computed(() => props.templates.map((template) => ({
  ...template,
  displayLabel: formatTemplateName(template)
})));

const filteredTemplates = computed(() => {
  const query = templateSearch.value.trim().toLowerCase();
  if (!query) {
    return formattedTemplates.value;
  }
  return formattedTemplates.value.filter((template) =>
    formatTemplateName(template).toLowerCase().includes(query)
  );
});

const selectedTemplate = computed(() => {
  return formattedTemplates.value.find((template) => String(template.uuid || template.id) === quickSendTemplate.value) || null;
});

const selectedTemplateLabel = computed(() => {
  return selectedTemplate.value ? formatTemplateName(selectedTemplate.value) : '';
});

const selectedTemplateHeaderMediaType = computed(() => {
  const metadata = parseTemplateMetadata(selectedTemplate.value);
  const format = metadata?.header?.format
    || metadata?.components?.find((component) => component.type?.toUpperCase() === 'HEADER')?.format
    || '';
  const normalized = String(format).toUpperCase();

  return ['IMAGE', 'DOCUMENT', 'VIDEO'].includes(normalized) ? normalized : '';
});

const isTemplateSelected = (template) => {
  return String(template.uuid || template.id) === quickSendTemplate.value;
};

const focusDropdownSearch = async () => {
  await nextTick();
  const searchInput = dropdownRef.value?.querySelector('input[type="search"]');
  if (searchInput) {
    searchInput.focus();
  }
};

const openModal = () => {
  showModal.value = true;
  quickSendStatus.value = null;
  highlightedIndex.value = 0;
};

const closeModal = () => {
  const wasOpen = showModal.value;
  showModal.value = false;
  dropdownOpen.value = false;
  phoneError.value = '';
  if (wasOpen) {
    emit('closed');
  }
};

const cancelModal = () => {
  closeModal();
  quickSendPhone.value = '';
  quickSendTemplate.value = '';
  quickSendHeaderFile.value = null;
  quickSendHeaderFileError.value = '';
  quickSendVariables.value = [];
  templateSearch.value = '';
  quickSendStatus.value = null;
};

const toggleDropdown = async () => {
  if (props.templates.length === 0) {
    return;
  }
  dropdownOpen.value = !dropdownOpen.value;
  if (dropdownOpen.value) {
    templateSearch.value = '';
    highlightedIndex.value = 0;
    await focusDropdownSearch();
  }
};

const closeDropdown = () => {
  dropdownOpen.value = false;
  highlightedIndex.value = 0;
};

const selectTemplate = (template) => {
  quickSendTemplate.value = String(template.uuid || template.id);
  quickSendVariables.value = extractTemplateVariables(template);
  quickSendHeaderFile.value = null;
  quickSendHeaderFileError.value = '';
  dropdownOpen.value = false;
  templateSearch.value = '';
  highlightedIndex.value = 0;
};

const validatePhone = () => {
  const phone = quickSendPhone.value.trim();
  const digits = phone.replace(/[^0-9]/g, '');

  if (!phone) {
    phoneError.value = t('Phone number is required.');
    return false;
  }
  if (digits.length < 8) {
    phoneError.value = t('Phone number must have at least 8 digits.');
    return false;
  }
  if (digits.length > 15) {
    phoneError.value = t('Phone number is too long (max 15 digits).');
    return false;
  }
  phoneError.value = '';
  return true;
};

const onDropdownNavigation = (direction) => {
  if (!dropdownOpen.value) {
    toggleDropdown();
    return;
  }

  const length = filteredTemplates.value.length;
  if (length === 0) {
    return;
  }

  if (direction === 'down') {
    highlightedIndex.value = (highlightedIndex.value + 1) % length;
  } else if (direction === 'up') {
    highlightedIndex.value = (highlightedIndex.value - 1 + length) % length;
  }
};

const confirmHighlightedTemplate = () => {
  if (filteredTemplates.value.length > 0) {
    selectTemplate(filteredTemplates.value[highlightedIndex.value]);
  }
};

const handleQuickHeaderFileUpload = (event) => {
  const file = event.target.files[0];
  quickSendHeaderFileError.value = '';
  quickSendHeaderFile.value = null;

  if (!file) {
    return;
  }

  const limit = getFileSizeLimit(selectedTemplateHeaderMediaType.value);
  if (file.size > limit) {
    quickSendHeaderFileError.value = t('File size exceeds the allowed limit.');
    event.target.value = null;
    return;
  }

  quickSendHeaderFile.value = file;
};

const onWindowClick = (event) => {
  if (dropdownOpen.value && dropdownRef.value && !dropdownRef.value.contains(event.target)) {
    closeDropdown();
  }
};

const formatTemplateName = (template) => {
  const rawLabel = template.display_name || template.title || template.name || template.uuid || '';
  const cleaned = rawLabel
    .replace(/[_-]+/g, ' ')
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/\s{2,}/g, ' ')
    .trim();
  return cleaned
    ? cleaned.replace(/\b\w/g, (char) => char.toUpperCase())
    : rawLabel;
};

const parseTemplateMetadata = (template) => {
  if (!template) {
    return null;
  }

  let metadata = template.metadata;
  if (typeof metadata === 'string') {
    try {
      metadata = JSON.parse(metadata);
    } catch {
      metadata = null;
    }
  }

  return metadata;
};

const normalizeExampleValues = (examples) => {
  if (!Array.isArray(examples)) {
    return examples === null || examples === undefined || examples === '' ? [] : [examples];
  }

  const values = Array.isArray(examples[0]) ? examples[0] : examples;
  return values.map((value) => Array.isArray(value) ? value[0] : value);
};

const countPlaceholders = (text) => {
  return String(text || '').match(/{{\s*\d+\s*}}/g)?.length || 0;
};

const createTextVariables = ({ component, label, text, examples = [], parameters = [], buttonIndex = null }) => {
  const count = Math.max(countPlaceholders(text), examples.length, parameters.length);

  return Array.from({ length: count }, (_, index) => {
    const parameter = parameters[index] || {};
    const selection = parameter.selection || parameter.type || 'static';
    const defaultValue = parameter.value ?? examples[index] ?? '';

    return {
      key: `${component}-${buttonIndex ?? 'main'}-${index}`,
      component,
      buttonIndex,
      parameterIndex: index,
      label: `${label} ${index + 1}`,
      placeholder: `{{${index + 1}}}`,
      selection: selection === 'dynamic' ? 'dynamic' : 'static',
      value: String(defaultValue ?? ''),
      defaultValue: String(defaultValue ?? ''),
    };
  });
};

const extractTemplateVariables = (template) => {
  const metadata = parseTemplateMetadata(template);
  if (!metadata) {
    return [];
  }

  const components = Array.isArray(metadata.components) ? metadata.components : [];
  const header = components.find((component) => component.type?.toUpperCase() === 'HEADER') || metadata.header || null;
  const body = components.find((component) => component.type?.toUpperCase() === 'BODY') || metadata.body || null;
  const buttonComponent = components.find((component) => component.type?.toUpperCase() === 'BUTTONS');
  const buttons = buttonComponent?.buttons || metadata.buttons || [];
  const variables = [];

  if (header && String(header.format || '').toUpperCase() === 'TEXT') {
    variables.push(...createTextVariables({
      component: 'header',
      label: t('Header variable'),
      text: header.text,
      examples: normalizeExampleValues(header.example?.header_text || header.example),
      parameters: header.parameters || [],
    }));
  }

  if (body) {
    variables.push(...createTextVariables({
      component: 'body',
      label: t('Body variable'),
      text: body.text,
      examples: normalizeExampleValues(body.example?.body_text || body.example),
      parameters: body.parameters || [],
    }));
  }

  buttons.forEach((button, buttonIndex) => {
    const type = String(button.type || '').toUpperCase();
    if (!['URL', 'COPY_CODE'].includes(type)) {
      return;
    }

    let examples = normalizeExampleValues(button.example);
    if (type === 'URL' && String(button.url || '').includes('{{')) {
      const baseUrl = String(button.url).split('{{')[0];
      examples = examples.map((example) => {
        const value = String(example ?? '');
        return baseUrl && value.startsWith(baseUrl) ? value.slice(baseUrl.length) : value;
      });
    }
    const parameterCount = Math.max(
      type === 'URL' ? countPlaceholders(button.url) : 0,
      examples.length,
      button.parameters?.length || 0,
    );

    if (parameterCount === 0) {
      return;
    }

    variables.push(...createTextVariables({
      component: 'button',
      label: `${t('Button variable')} (${button.text || buttonIndex + 1})`,
      text: Array.from({ length: parameterCount }, (_, index) => `{{${index + 1}}}`).join(' '),
      examples,
      parameters: button.parameters || [],
      buttonIndex,
    }));
  });

  return variables;
};

const resetVariableValue = (variable) => {
  variable.value = variable.selection === 'static' ? variable.defaultValue : '';
};

const buildVariablesPayload = () => {
  const payload = { header: [], body: [], buttons: {} };

  quickSendVariables.value.forEach((variable) => {
    const parameter = {
      selection: variable.selection,
      value: String(variable.value ?? '').trim(),
    };

    if (variable.component === 'button') {
      payload.buttons[variable.buttonIndex] ||= [];
      payload.buttons[variable.buttonIndex][variable.parameterIndex] = parameter;
    } else {
      payload[variable.component][variable.parameterIndex] = parameter;
    }
  });

  return payload;
};

const appendFormDataValue = (formData, key, value) => {
  if (Array.isArray(value)) {
    value.forEach((item, index) => appendFormDataValue(formData, `${key}[${index}]`, item));
    return;
  }

  if (value && typeof value === 'object') {
    Object.entries(value).forEach(([childKey, childValue]) => {
      appendFormDataValue(formData, `${key}[${childKey}]`, childValue);
    });
    return;
  }

  formData.append(key, value ?? '');
};

const getFileAcceptAttribute = (fileType) => {
  switch (fileType) {
    case 'IMAGE':
      return '.png,.jpg,.jpeg';
    case 'DOCUMENT':
      return '.pdf,.txt,.ppt,.doc,.xls,.docx,.pptx,.xlsx';
    case 'VIDEO':
      return '.mp4';
    default:
      return '';
  }
};

const getFileSizeLimit = (fileType) => {
  switch (fileType) {
    case 'IMAGE':
      return 5 * 1024 * 1024;
    case 'DOCUMENT':
      return 100 * 1024 * 1024;
    case 'VIDEO':
      return 16 * 1024 * 1024;
    default:
      return Infinity;
  }
};

const getTemplateType = (template) => {
  if (!template) {
    return '';
  }
  const metadata = parseTemplateMetadata(template);

  const typeValue = metadata?.template_type || metadata?.header?.format || metadata?.type || 'Text';
  const normalized = String(typeValue).toLowerCase();

  if (normalized.includes('image')) return t('Image');
  if (normalized.includes('document')) return t('Document');
  if (normalized.includes('video')) return t('Video');
  if (normalized.includes('carousel')) return t('Carousel');
  if (normalized.includes('text')) return t('Text');
  return String(typeValue).replace(/^./, (char) => char.toUpperCase());
};

const getTemplatePreviewText = (template, short = false) => {
  if (!template) {
    return '';
  }
  const metadata = parseTemplateMetadata(template);

  if (!metadata) {
    return t('No preview available.');
  }

  // Try direct body.text format (flat structure)
  if (metadata.body?.text) {
    const text = metadata.body.text;
    return short ? text.slice(0, 60) : text;
  }

  // Try components array format (used in Edit template)
  if (Array.isArray(metadata.components)) {
    const bodyComponent = metadata.components.find(
      (c) => c.type?.toUpperCase() === 'BODY'
    );
    if (bodyComponent?.text) {
      const text = bodyComponent.text;
      return short ? text.slice(0, 60) : text;
    }
  }

  // Try body.parameters format (parameter-based)
  if (Array.isArray(metadata.body?.parameters) && metadata.body.parameters.length > 0) {
    const text = metadata.body.parameters.map((parameter) => parameter.value || '').join(' ');
    if (text.trim()) {
      return short ? text.slice(0, 60) : text;
    }
  }

  // Fallback: Check for media-only templates
  if (metadata.header?.format) {
    return t('This template contains rich media content.');
  }

  // Final fallback: Check for carousel templates
  if (metadata.template_type === 'carousel' || Array.isArray(metadata.carousel_cards)) {
    return t('This is a carousel template with multiple cards.');
  }

  return t('No preview available.');
};

const sendTemplateToNumber = async () => {
  if (!validatePhone() || !quickSendTemplate.value) {
    if (!quickSendTemplate.value) {
      quickSendStatus.value = { type: 'error', message: t('Please select a template before sending.') };
    }
    return;
  }

  if (quickSendHeaderFileError.value) {
    return;
  }

  if (!variablesAreValid.value) {
    quickSendStatus.value = { type: 'error', message: t('Please provide a value for every template variable.') };
    return;
  }

  isSending.value = true;
  quickSendStatus.value = null;

  try {
    // Extract digits and send the full phone number as provided
    const phoneToSend = quickSendPhone.value.replace(/[^0-9+]/g, '');
    const payload = quickSendHeaderFile.value ? new FormData() : null;
    const variables = buildVariablesPayload();
    let response;

    if (payload) {
      payload.append('phone', phoneToSend);
      payload.append('template', quickSendTemplate.value);
      payload.append('header_media', quickSendHeaderFile.value);
      appendFormDataValue(payload, 'variables', variables);
      response = await axios.post('/chats/send-template-to-number', payload);
    } else {
      response = await axios.post('/chats/send-template-to-number', {
        phone: phoneToSend,
        template: quickSendTemplate.value,
        variables,
      });
    }

    quickSendStatus.value = {
      type: 'success',
      message: response.data.message || t('Template sent successfully.'),
    };

    emit('sent', response.data);
    cancelModal();
  } catch (error) {
    quickSendStatus.value = {
      type: 'error',
      message: error?.response?.data?.message || t('Failed to send the template. Please try again.'),
    };
    console.error('Send template to number error:', error);
  } finally {
    isSending.value = false;
  }
};

onMounted(() => {
  window.addEventListener('click', onWindowClick);
  if (props.openOnMount) {
    openModal();
  }
});

onBeforeUnmount(() => {
  window.removeEventListener('click', onWindowClick);
});

watch(showModal, (value) => {
  if (!value) {
    closeDropdown();
  }
});
</script>
