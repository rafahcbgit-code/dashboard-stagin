<script setup>
import axios from "axios";
import FormInput from "@/Components/FormInput.vue";
import FormSelect from "@/Components/FormSelect.vue";
import WhatsappTemplate from "@/Components/WhatsappTemplate.vue";
import CarouselPreview from "@/Components/Template/CarouselPreview.vue";
import { ref, computed, onMounted } from "vue";
import { Link, useForm } from "@inertiajs/vue3";
import "vue3-toastify/dist/index.css";
import { trans } from "laravel-vue-i18n";
import { useI18n } from "vue-i18n";

const { t } = useI18n();

const props = defineProps({
  templates: Object,
  contactGroups: Object,
  settings: Array,
  contact: {
    type: [String, Object],
    default: null,
  },
  displayTitle: {
    type: Boolean,
    default: false,
  },
  displayCancelBtn: {
    type: Boolean,
    default: true,
  },
  isCampaignFlow: {
    type: Boolean,
    default: true,
  },
  sendText: {
    type: String,
    default: "Send",
  },
  contactFields: Array,
  resourceUsage: {
    type: Object,
    default: () => ({}),
  },
  totalContacts: {
    type: Number,
    default: 0,
  },
});

const isLoading = ref(false);
const isTemplateLoading = ref(false);
const templateLoadError = ref(null);
const loadedTemplateUuid = ref(null);
let templateLoadRequestId = 0;
const contactGroupOptions = ref([
  { value: "all", label: trans("all contacts") },
]);
const templateOptions = ref([]);
const config = ref(props.settings?.metadata);
const settings = ref(config.value ? JSON.parse(config.value) : null);

// ── CAROUSEL STATE ──────────────────────────────────────────────────
// Set to true when the currently selected template is a carousel.
// Controls which UI branch renders (standard variables vs carousel preview).
const isCarouselTemplate = ref(false);
// Holds the carousel_cards array from the loaded template's metadata,
// passed directly to CarouselPreview for rendering.
const carouselCards = ref([]);
// Holds the top-level carousel_message_body text for preview.
const carouselMessageBody = ref("");

const variableOptions = computed(() => [
  {
    value: "static",
    label: t("static"),
    description: t("A fixed value is used for every recipient."),
  },
  {
    value: "dynamic",
    label: t("Use contact information"),
    description: t("A value is filled from each recipient's contact details."),
  },
]);

const mediaHeaderOptions = computed(() => [
  {
    value: "default",
    label: t("Use template media"),
    description: t("Use the image, video, or document already attached to this template."),
  },
  {
    value: "upload",
    label: t("Upload a new file"),
    description: t("Choose a file from your device to use instead of the template media for this message."),
  },
]);

const baseDynamicOptions = computed(() => [
  { value: "first name", label: t("contact first name") },
  { value: "last name", label: t("contact last name") },
  { value: "name", label: t("contact full name") },
  { value: "phone", label: t("contact phone") },
  { value: "email", label: t("contact email") },
]);

const hasButtonVariables = computed(() => {
  return form.buttons.some(
    (button) => button.parameters && button.parameters.length > 0,
  );
});

// A campaign must never be submitted while its template is still being fetched.
// The campaign payload contains a snapshot of the template buttons, so submitting
// during that request would otherwise persist an empty buttons array.
const isTemplateReady = computed(() => {
  return Boolean(form.template)
    && !isTemplateLoading.value
    && !templateLoadError.value
    && String(loadedTemplateUuid.value) === String(form.template);
});

const isSubmitDisabled = computed(() => isLoading.value || !isTemplateReady.value);

const contactUuid = computed(() => {
  return typeof props.contact === "object" ? props.contact?.uuid : props.contact;
});

const resolveContactValue = (field) => {
  const contact = typeof props.contact === "object" ? props.contact : null;
  if (!contact) return null;

  const normalizedField = String(field || "").trim().toLowerCase();
  const standardFields = {
    "first name": contact.first_name,
    "last name": contact.last_name,
    name: contact.full_name || [contact.first_name, contact.last_name].filter(Boolean).join(" "),
    phone: contact.formatted_phone_number || contact.phone,
    email: contact.email,
  };

  return standardFields[normalizedField] ?? contact[normalizedField] ?? null;
};

const submitLabel = computed(() => {
  if (isTemplateLoading.value) return t("Loading template...");

  return props.sendText ? t(props.sendText) : t("Send");
});

const requiredCampaignMessages = computed(() => {
  if (!form.contacts) return 0;
  if (form.contacts === "all") return props.totalContacts || 0;

  const selectedGroup = props.contactGroups.find((group) => group.uuid === form.contacts);
  return Number(selectedGroup?.contacts_count || 0);
});

const limitText = (value) => {
  return Number(value) === -1 ? trans("Unlimited") : value;
};

const isHeaderParameterVisible = (parameter) => {
  if (!parameter) return false;

  if (parameter.type === "text") return true;

  return ["IMAGE", "DOCUMENT", "VIDEO"].includes(parameter.type);
};

const hasVisibleHeaderVariables = computed(() => {
  return form.header.parameters.some(isHeaderParameterVisible);
});

const previewParameters = computed(() => ({
  ...form,
  body: {
    ...form.body,
    text: (form.body.text || "").replace(/\{\{\s*(\d+)\s*\}\}/g, (match, number) => {
      const parameter = form.body.parameters[Number(number) - 1];

      if (!parameter) return match;

      if (parameter.selection === "dynamic") {
        return resolveContactValue(parameter.value) || parameter.value || match;
      }

      return parameter.value || match;
    }),
  },
}));

const dynamicOptions = computed(() => {
  let options = [...baseDynamicOptions.value];
  if (props.contactFields) {
    let fieldsArray = [];

    if (Array.isArray(props.contactFields)) {
      fieldsArray = props.contactFields;
    } else if (
      props.contactFields.data &&
      Array.isArray(props.contactFields.data)
    ) {
      fieldsArray = props.contactFields.data;
    } else if (typeof props.contactFields === "object") {
      fieldsArray = Object.values(props.contactFields);
    }

    if (fieldsArray.length > 0) {
      const customFields = fieldsArray
        .map((field) => {
          return {
            value:
              field.slug ||
              field.name ||
              field.label ||
              field.key ||
              field.field_name ||
              field.id,
            label:
              field.label ||
              field.name ||
              field.display_name ||
              field.slug ||
              field.field_name ||
              field.title,
          };
        })
        .filter((field) => field.value && field.label);

      options = [...options, ...customFields];
    }
  }
  return options;
});

// Get current datetime in the format required for datetime-local input (YYYY-MM-DDTHH:MM)
const minDateTime = computed(() => {
  const now = new Date();
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, "0");
  const day = String(now.getDate()).padStart(2, "0");
  const hours = String(now.getHours()).padStart(2, "0");
  const minutes = String(now.getMinutes()).padStart(2, "0");
  return `${year}-${month}-${day}T${hours}:${minutes}`;
});

const form = useForm({
  name: null,
  template: null,
  contacts: null,
  time: null,
  skip_schedule: false,
  header: {
    format: null,
    text: null,
    parameters: [],
  },
  body: {
    text: null,
    parameters: [],
  },
  footer: {
    text: null,
  },
  buttons: [],
});

const loadTemplate = async (selectedTemplate = null) => {
  const requestId = ++templateLoadRequestId;

  // Reset carousel state before loading new template
  isCarouselTemplate.value = false;
  carouselCards.value = [];
  carouselMessageBody.value = "";

  // Clear the old snapshot immediately. This prevents buttons from a previously
  // selected template from being sent while the new template is loading.
  form.header = { format: null, text: null, parameters: [] };
  form.body = { text: null, parameters: [] };
  form.footer = { text: null };
  form.buttons = [];
  loadedTemplateUuid.value = null;
  templateLoadError.value = null;
  form.clearErrors("template");

  const templateUuid = selectedTemplate || form.template;
  if (!templateUuid) {
    isTemplateLoading.value = false;
    return;
  }

  isTemplateLoading.value = true;

  try {
    const response = await axios.get("/templates/" + templateUuid);
    // Ignore a late response if the user selected another template in the meantime.
    if (requestId !== templateLoadRequestId) return;

    if (response) {
      const metadata = JSON.parse(response.data.metadata);

      // ── CAROUSEL DETECTION ──────────────────────────────────
      // Check template_type flag first (set by us at creation/sync).
      // Fallback: scan components for a carousel type in case the
      // flag is missing (e.g., template created outside this app).
      const isCarousel =
        metadata.template_type === "carousel" ||
        (Array.isArray(metadata.components) &&
          metadata.components.some(
            (c) => (c.type || "").toUpperCase() === "CAROUSEL",
          ));

      if (isCarousel) {
        // ── CAROUSEL BRANCH ─────────────────────────────────
        // No header/body/button variables to fill — just store
        // the card data for the preview and flip the flag.
        isCarouselTemplate.value = true;
        carouselCards.value = metadata.carousel_cards || [];
        carouselMessageBody.value = metadata.carousel_message_body || "";

        // Clear out any standard template fields so they don't
        // linger if user switches back to a standard template.
        form.header = { format: null, text: null, parameters: [] };
        form.body = { text: null, parameters: [] };
        form.footer = { text: null };
        form.buttons = [];

        loadedTemplateUuid.value = templateUuid;
        return; // Skip the standard extraction below
      }

      // ── STANDARD BRANCH (unchanged) ─────────────────────────
      form.header.format = extractComponent(metadata, "HEADER", "format");
      form.header.text = extractComponent(metadata, "HEADER", "text");
      const headerExamples = extractComponent(metadata, "HEADER", "example");
      if (headerExamples) {
        if (form.header.format === "TEXT") {
          form.header.parameters = headerExamples.header_text.map((item) => ({
            type: "text",
            selection: "static",
            value: item,
          }));
        }
      }

      if (["IMAGE", "DOCUMENT", "VIDEO"].includes(form.header.format)) {
        const defaultHeaderMedia = resolveDefaultHeaderMedia(metadata);
        const defaultSelection = form.header.format === "DOCUMENT" ? "upload" : "default";
        const headerHandles = Array.isArray(headerExamples?.header_handle)
          ? headerExamples.header_handle
          : [];
        const defaultUrls = headerHandles.length > 0
          ? headerHandles
          : defaultHeaderMedia?.url
            ? [defaultHeaderMedia.url]
            : [];

        form.header.parameters = defaultUrls.length > 0
          ? defaultUrls.map((item) => ({
              type: form.header.format,
              selection: defaultSelection,
              value: defaultSelection === "default" ? item : null,
              url: defaultSelection === "default" ? item : null,
              default_value: item,
              default_url: item,
            }))
          : [{
              type: form.header.format,
              selection: "upload",
              value: null,
              url: null,
              default_value: null,
              default_url: null,
            }];
      } else if (!headerExamples) {
        form.header.parameters = [];
      }

      form.body.text = extractComponent(metadata, "BODY", "text");
      const bodyExamples = extractComponent(metadata, "BODY", "example");
      if (bodyExamples) {
        form.body.parameters = bodyExamples.body_text[0].map((item) => ({
          type: "text",
          selection: "static",
          value: item,
        }));
      } else {
        form.body.parameters = [];
      }

      form.footer.text = extractComponent(metadata, "FOOTER", "text");

      const buttons = extractComponent(metadata, "BUTTONS", "buttons");
      if (buttons) {
        form.buttons = buttons.map((item) => {
          let parameters = [];
          if (item.type !== "QUICK_REPLY" && item.example) {
            parameters = item.example.map((param) => {
              let value = param;
              // If it's a URL button with a variable, try to strip the base URL from the example
              if (
                item.type === "URL" &&
                item.url &&
                item.url.includes("{{1}}")
              ) {
                const baseUrl = item.url.split("{{1}}")[0];
                if (value.startsWith(baseUrl)) {
                  value = value.substring(baseUrl.length);
                }
              }
              return {
                type: "dynamic",
                value: value,
              };
            });
          }

          return {
            type: item.type,
            text: item.text,
            value: item[item.type.toLowerCase()] ?? null,
            parameters: parameters,
            flow_id: item.flow_id ?? null,
            navigate_screen: item.navigate_screen ?? null,
          };
        });
      } else {
        form.buttons = [];
      }

      loadedTemplateUuid.value = templateUuid;
    }
  } catch (error) {
    if (requestId === templateLoadRequestId) {
      templateLoadError.value = trans("Unable to load the selected template. Please try again.");
    }
  } finally {
    if (requestId === templateLoadRequestId) {
      isTemplateLoading.value = false;
    }
  }
};

const handleHeaderMediaSelectionChange = (index, selection) => {
  const parameter = form.header.parameters[index];
  if (!parameter) return;

  parameter.selection = selection;
  form.clearErrors(`header.parameters.${index}.value`);

  if (selection === "default") {
    parameter.value = parameter.default_value || parameter.default_url || null;
    parameter.url = parameter.default_url || parameter.default_value || null;
  } else {
    parameter.value = null;
    parameter.url = null;
  }
};

const handleFileUpload = (event, index = 0) => {
  const parameter = form.header.parameters[index];
  if (!parameter) return;

  const fileSizeLimit = getFileSizeLimit(parameter.type);
  const file = event.target.files[0];

  if (!file) {
    return;
  }

  if (file && file.size > fileSizeLimit) {
    alert(
      trans("file size exceeds the limit. Max allowed size:") +
        " " +
        fileSizeLimit +
        "b",
    );
    event.target.value = null;
  } else {
    const reader = new FileReader();
    reader.onload = (e) => {
      parameter.url = e.target.result;
    };
    parameter.selection = "upload";
    parameter.value = file;
    form.clearErrors(`header.parameters.${index}.value`);
    reader.readAsDataURL(file);
  }
};

const resolveDefaultHeaderMedia = (metadata) => {
  const stored = metadata?._default_header_media || metadata?.default_header_media || null;

  if (!stored) {
    return null;
  }

  return {
    url: stored.url || stored.path || null,
    name: stored.name || stored.filename || null,
  };
};

const getFileAcceptAttribute = (fileType) => {
  switch (fileType) {
    case "IMAGE":
      return ".png, .jpg, .jpeg";
    case "DOCUMENT":
      return ".pdf, .txt, .ppt, .doc, .xls, .docx, .pptx, .xlsx";
    case "VIDEO":
      return ".mp4";
    default:
      return "";
  }
};

const getFileSizeLimit = (fileType) => {
  switch (fileType) {
    case "IMAGE":
      return 5 * 1024 * 1024;
    case "DOCUMENT":
      return 100 * 1024 * 1024;
    case "VIDEO":
      return 16 * 1024 * 1024;
    default:
      return Infinity;
  }
};

const extractComponent = (data, type, customProperty) => {
  const component = data.components.find((c) => c.type === type);
  return component ? component[customProperty] : null;
};

const resolveTierLimit = (tier) => {
  if (!tier) return 250;

  const normalizedTier = tier.toString().trim().toUpperCase();

  if (normalizedTier === 'TIER_UNLIMITED') return Number.MAX_SAFE_INTEGER;

  const matched = normalizedTier.match(/TIER_(\d+)(K?)/i);
  if (!matched) return 250;

  const value = Number(matched[1]);
  return matched[2].toUpperCase() === 'K' ? value * 1000 : value;
};

const validateGroupLimit = () => {
  if (!settings.value?.whatsapp || !form.contacts || form.contacts === 'all') return true;

  const messagingTier = settings.value?.whatsapp?.messaging_limit_tier;
  const maxContacts = resolveTierLimit(messagingTier);

  const selectedGroup = props.contactGroups.find((group) => group.uuid === form.contacts);
  if (!selectedGroup || selectedGroup.contacts_count === undefined) return true;

  if (selectedGroup.contacts_count > maxContacts) {
    form.setError(
      'contacts',
      trans(
        'The selected group has :count contacts, which exceeds the messaging limit of :limit for your current WhatsApp tier.',
        { count: selectedGroup.contacts_count, limit: maxContacts },
      ),
    );
    return false;
  }

  return true;
};

const transformOptions = (options) => {
  return options.map((option) => {
    const name = String(option.name || '')
      .replace(/[_-]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .replace(/\b\w/g, (letter) => letter.toLocaleUpperCase());
    const languageCode = String(option.language || '').split(/[-_]/)[0].toLowerCase();
    const language = languageCode === 'ar'
      ? trans('Arabic')
      : (languageCode === 'en' ? trans('English') : option.language);

    return {
      value: option.uuid,
      label: language ? `${name} (${language})` : name,
    };
  });
};

const submitForm = () => {
  if (!isTemplateReady.value) {
    form.setError(
      "template",
      templateLoadError.value || trans("Please wait for the template to finish loading."),
    );
    return;
  }

  isLoading.value = true;

  let hasValidationError = false;
  const allowedDynamicValues = new Set(
    (dynamicOptions.value || []).map((option) => option.value),
  );

  // Validate body dynamic parameters (must exist in contact fields)
  if (Array.isArray(form.body?.parameters)) {
    form.body.parameters.forEach((param, index) => {
      if (param?.selection === "dynamic") {
        const value =
          typeof param.value === "string" ? param.value.trim() : param.value;
        if (!value || !allowedDynamicValues.has(value)) {
          form.setError(
            `body.parameters.${index}.value`,
            trans("Invalid or missing field for body variable."),
          );
          hasValidationError = true;
        }
      }
    });
  }

  // Validate button dynamic parameters (must exist in contact fields)
  if (Array.isArray(form.buttons)) {
    form.buttons.forEach((button, buttonIndex) => {
      if (Array.isArray(button?.parameters)) {
        button.parameters.forEach((param, paramIndex) => {
          if (param?.type === "dynamic") {
            const value =
              typeof param.value === "string"
                ? param.value.trim()
                : param.value;
            if (!value || !allowedDynamicValues.has(value)) {
              form.setError(
                `buttons.${buttonIndex}.parameters.${paramIndex}.value`,
                trans("Invalid or missing field for button variable."),
              );
              hasValidationError = true;
            }
          }
        });
      }
    });
  }

  if (hasValidationError) {
    isLoading.value = false;
    return;
  }

  if (Array.isArray(form.header?.parameters)) {
    form.header.parameters.forEach((param, index) => {
      if (
        ["IMAGE", "DOCUMENT", "VIDEO"].includes(param?.type) &&
        param?.selection === "upload" &&
        !(param?.value instanceof File)
      ) {
        form.setError(
          `header.parameters.${index}.value`,
          trans("Please choose a file to upload."),
        );
        hasValidationError = true;
      }
    });
  }

  if (hasValidationError) {
    isLoading.value = false;
    return;
  }

  // 客户端：تحقق من حد رسائل Whatsapp قبل الإرسال
  if (!validateGroupLimit()) {
    isLoading.value = false;
    return;
  }

  // Normalize URL button parameters (static values only)
  form.buttons = form.buttons.map((button) => {
    if (button.type !== "URL" || !Array.isArray(button.parameters)) {
      return button;
    }

    const normalizedParams = button.parameters.map((param) => {
      if (param?.type !== "static") {
        return param;
      }

      const value =
        typeof param.value === "string" ? param.value.trim() : param.value;
      if (!value) {
        return param;
      }

      return {
        ...param,
        value: value,
      };
    });

    return {
      ...button,
      parameters: normalizedParams,
    };
  });

  if (isCarouselTemplate.value && !props.isCampaignFlow) {
    // ── CAROUSEL SEND (chat context only for now) ───────────────
    // Hits the dedicated carousel endpoint. Only needs the template UUID.
    axios
      .post("/chat/" + contactUuid.value + "/send/carousel-template", {
        template: form.template,
      })
      .then((response) => {
        isLoading.value = false;
        if (response.data && response.data.success === false) {
          // Error handled by Laravel flash via redirect
        }
        emit("viewTemplate", false);
      })
      .catch(() => {
        isLoading.value = false;
      });
    return;
  }

  // ── STANDARD SEND (unchanged) ───────────────────────────────────
  form.post(
    props.isCampaignFlow
      ? "/campaigns"
      : "/chat/" + contactUuid.value + "/send/template",
    {
      onFinish: () => {
        isLoading.value = false;
        if (!props.isCampaignFlow) {
          emit("viewTemplate", false);
        }
      },
    },
  );
};

const emit = defineEmits(["viewTemplate"]);

const viewTemplate = () => {
  emit("viewTemplate", false);
};

onMounted(() => {
  templateOptions.value = transformOptions(props.templates);
  contactGroupOptions.value = [
    ...contactGroupOptions.value,
    ...transformOptions(props.contactGroups),
  ];
});
</script>

<template>
  <div :class="isCampaignFlow ? 'md:flex md:h-screen' : 'flex h-full min-h-0 md:flex-row'">
    <div
      v-if="!settings?.whatsapp"
      class="md:w-[50%] p-4 md:p-8 overflow-y-auto h-[90vh]"
    >
      <div class="bg-slate-50 border border-primary shadow rounded-md p-4 py-8">
        <div class="flex justify-center mb-4">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            width="72"
            height="72"
            viewBox="0 0 48 48"
          >
            <path
              fill="black"
              d="M43.634 4.366a1.25 1.25 0 0 1 0 1.768l-4.913 4.913a9.253 9.253 0 0 1-.744 12.244l-3.343 3.343a1.25 1.25 0 0 1-1.768 0l-11.5-11.5a1.25 1.25 0 0 1 0-1.768l3.343-3.343a9.25 9.25 0 0 1 12.244-.743l4.913-4.914a1.25 1.25 0 0 1 1.768 0m-7.611 7.425a6.75 6.75 0 0 0-9.546 0l-2.46 2.459l9.733 9.732l2.46-2.459a6.75 6.75 0 0 0 0-9.546zM9.28 36.953l-4.914 4.913a1.25 1.25 0 0 0 1.768 1.768l4.913-4.913a9.253 9.253 0 0 0 12.244-.744l3.343-3.343a1.25 1.25 0 0 0 0-1.768L25.268 31.5l3.366-3.366a1.25 1.25 0 0 0-1.768-1.768L23.5 29.732L18.268 24.5l3.366-3.366a1.25 1.25 0 0 0-1.768-1.768L16.5 22.732l-1.366-1.366a1.25 1.25 0 0 0-1.768 0l-3.343 3.343a9.25 9.25 0 0 0-.743 12.244m2.51-10.476l2.46-2.46l9.732 9.733l-2.459 2.46a6.75 6.75 0 0 1-9.546 0l-.186-.187a6.75 6.75 0 0 1 0-9.546"
            />
          </svg>
        </div>
        <h3 class="text-center text-lg font-medium mb-4">
          {{ $t("Connect your whatsapp account") }}
        </h3>
        <h4 class="text-center mb-4">
          {{
            $t(
              "You need to connect your WhatsApp account first before you can send out campaigns.",
            )
          }}
        </h4>
        <div class="flex justify-center">
          <Link
            href="/settings/whatsapp"
            class="rounded-md px-3 py-2 text-sm hover:shadow-md text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 bg-primary"
            :disabled="isLoading"
          >
            <span v-if="!isLoading">{{ $t("Connect Whatsapp account") }}</span>
          </Link>
        </div>
      </div>
    </div>

    <form
      v-else
      @submit.prevent="submitForm()"
      class="min-h-0 min-w-0 overflow-y-auto overscroll-contain"
      :class="isCampaignFlow ? 'md:w-[50%] p-4 md:p-8' : 'w-full flex-1 md:w-[50%]'"
    >
      <div
        v-if="displayTitle"
        class="m-1 rounded px-3 pt-3 pb-3 bg-slate-100 flex items-center justify-between mb-4"
      >
        <h3 class="text-[15px]">{{ $t("Send Template Message") }}</h3>
        <button
          type="button"
          @click="viewTemplate()"
          class="text-sm md:inline-flex hidden justify-center rounded-md border border-transparent bg-red-800 px-4 py-1 text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
        >
          {{ $t("Cancel") }}
        </button>
      </div>

      <!-- ── TEMPLATE SELECTOR (shared by both standard and carousel) ── -->
      <div
        class="grid gap-x-6 gap-y-4 mb-8"
        :class="isCampaignFlow ? 'sm:grid-cols-6' : 'p-3 md:p-3'"
      >
        <FormInput
          v-if="isCampaignFlow"
          v-model="form.name"
          :name="$t('Campaign name')"
          :type="'text'"
          :error="form.errors.name"
          :required="true"
          :class="'sm:col-span-6'"
        />
        <div class="sm:col-span-3 min-w-0">
          <FormSelect
            v-model="form.template"
            @update:modelValue="loadTemplate"
            :options="templateOptions"
            :required="true"
            :error="form.errors.template"
            :name="$t('Template')"
            :placeholder="$t('Select template')"
            :native="isCampaignFlow"
          />
          <!-- Reserve a small status area so loading feedback never moves Send to. -->
          <div
            class="mt-1 flex min-h-[1.25rem] items-center gap-2 text-xs"
            :class="templateLoadError ? 'text-red-600' : 'text-slate-500'"
          >
            <span v-if="isTemplateLoading" class="truncate">{{ $t("Loading template...") }}</span>
            <span v-else-if="templateLoadError" class="truncate">{{ templateLoadError }}</span>
            <button
              v-if="templateLoadError && form.template"
              type="button"
              class="shrink-0 underline"
              @click="loadTemplate(form.template)"
            >
              {{ $t("Try again") }}
            </button>
          </div>
        </div>
        <FormSelect
          v-if="isCampaignFlow"
          v-model="form.contacts"
          :options="contactGroupOptions"
          :name="$t('Send to')"
          :required="true"
          :class="'sm:col-span-3'"
          :placeholder="$t('Select contacts')"
          :error="form.errors.contacts"
          :native="true"
        />
        <div
          v-if="isCampaignFlow"
          class="sm:col-span-6 grid grid-cols-1 gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm md:grid-cols-2"
        >
          <div>
            <div class="text-slate-500">{{ $t("Remaining template messages") }}</div>
            <div class="font-medium">{{ limitText(props.resourceUsage?.template_messages?.remaining ?? -1) }}</div>
          </div>
          <div>
            <div class="text-slate-500">{{ $t("Campaign Recipients") }}</div>
            <div class="font-medium">{{ requiredCampaignMessages }}</div>
          </div>
        </div>
        <FormInput
          v-if="isCampaignFlow && !form.skip_schedule"
          v-model="form.time"
          :name="$t('Scheduled time')"
          :type="'datetime-local'"
          :min="minDateTime"
          :error="form.errors.time"
          :required="true"
          :class="'sm:col-span-6'"
        />
        <div v-if="isCampaignFlow" class="relative flex gap-x-3 sm:col-span-6">
          <div class="flex h-6 items-center">
            <input
              v-model="form.skip_schedule"
              id="skip-schedule"
              name="skip-schedule"
              type="checkbox"
              class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
            />
          </div>
          <div class="text-sm leading-6">
            <label for="skip-schedule" class="font-medium text-gray-900">{{
              $t("Skip scheduling & send immediately")
            }}</label>
          </div>
        </div>
      </div>

      <div :class="isCampaignFlow ? '' : 'px-3 md:px-3'">
        <!-- ── CAROUSEL: info banner + send button (no variables to fill) ── -->
        <div v-if="isCarouselTemplate">
          <div
            class="bg-indigo-50 border border-indigo-200 rounded-lg p-3 mb-4"
          >
            <div class="flex items-start gap-x-2">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="18"
                height="18"
                viewBox="0 0 24 24"
                class="text-indigo-600 flex-shrink-0 mt-0.5"
              >
                <path
                  fill="none"
                  stroke="currentColor"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"
                />
              </svg>
              <p class="text-sm text-indigo-800">
                {{
                  $t(
                    "This is a carousel template. All card content is defined in the template — no variables to fill before sending.",
                  )
                }}
              </p>
            </div>
          </div>

          <!-- Submit button for carousel -->
          <div class="flex items-center justify-end gap-3 mt-3">
            <div v-if="displayCancelBtn">
              <Link
                href="/campaigns"
                class="block rounded-md px-3 py-2 text-sm text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 bg-slate-200"
              >
                {{ $t("Cancel") }}
              </Link>
            </div>
            <button
              type="submit"
              class="rounded-md px-3 py-2 text-sm text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 bg-primary"
              :disabled="isSubmitDisabled"
            >
              <span v-if="!isLoading">{{
                submitLabel
              }}</span>
              <svg
                v-else
                xmlns="http://www.w3.org/2000/svg"
                width="24"
                height="24"
                viewBox="0 0 24 24"
              >
                <path
                  fill="currentColor"
                  d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z"
                  opacity=".5"
                />
                <path
                  fill="currentColor"
                  d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"
                >
                  <animateTransform
                    attributeName="transform"
                    dur="1s"
                    from="0 12 12"
                    repeatCount="indefinite"
                    to="360 12 12"
                    type="rotate"
                  />
                </path>
              </svg>
            </button>
          </div>
        </div>

        <!-- ── STANDARD: header / body / button variables (unchanged) ────── -->
        <div v-if="!isCarouselTemplate">
          <div
            v-if="hasVisibleHeaderVariables"
            class="bg-slate-100 p-3 mt-4 text-sm"
          >
            <h2
              class="flex items-center justify-between gap-2 pb-2 border-b"
            >
              <div class="flex items-center gap-2">
                <span>{{ $t("Header variables") }}</span>
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  width="16"
                  height="16"
                  viewBox="0 0 1024 1024"
                >
                  <path
                    fill="currentColor"
                    d="M512 64a448 448 0 1 1 0 896.064A448 448 0 0 1 512 64zm67.2 275.072c33.28 0 60.288-23.104 60.288-57.344s-27.072-57.344-60.288-57.344c-33.28 0-60.16 23.104-60.16 57.344s26.88 57.344 60.16 57.344zM590.912 699.2c0-6.848 2.368-24.64 1.024-34.752l-52.608 60.544c-10.88 11.456-24.512 19.392-30.912 17.28a12.992 12.992 0 0 1-8.256-14.72l87.68-276.992c7.168-35.136-12.544-67.2-54.336-71.296c-44.096 0-108.992 44.736-148.48 101.504c0 6.784-1.28 23.68.064 33.792l52.544-60.608c10.88-11.328 23.552-19.328 29.952-17.152a12.8 12.8 0 0 1 7.808 16.128L388.48 728.576c-10.048 32.256 8.96 63.872 55.04 71.04c67.84 0 107.904-43.648 147.456-100.416z"
                  />
                </svg>
              </div>
              <span>
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  width="16"
                  height="16"
                  viewBox="0 0 24 24"
                >
                  <path
                    fill="currentColor"
                    d="M6.102 16.98c-1.074 0-1.648-1.264-.94-2.073l5.521-6.31a1.75 1.75 0 0 1 2.634 0l5.522 6.31c.707.809.133 2.073-.94 2.073H6.101Z"
                  />
                </svg>
              </span>
            </h2>
            <div
              v-for="(item, index) in form.header.parameters"
              :key="index"
              v-show="isHeaderParameterVisible(item)"
              class="mt-2 flex items-center gap-4"
            >
              <div
                v-if="form.header.parameters[index].type === 'text'"
                class="w-full"
              >
                <FormSelect
                  v-model="form.header.parameters[index].selection"
                  :name="$t('Content type')"
                  :options="variableOptions"
                  :class="'sm:col-span-6'"
                />
              </div>
              <div
                v-if="form.header.parameters[index].type === 'text'"
                class="w-full"
              >
                <FormInput
                  v-if="form.header.parameters[index].selection === 'static'"
                  :name="$t('Value')"
                  :required="true"
                  :error="form.errors['header.parameters.0.value']"
                  :placeholder="$t('Type a value')"
                  v-model="form.header.parameters[index].value"
                  :type="'text'"
                  :class="'sm:col-span-6'"
                />
                <FormSelect
                  v-if="form.header.parameters[index].selection === 'dynamic'"
                  :name="$t('Value')"
                  :required="true"
                  :error="form.errors['header.parameters.0.value']"
                  v-model="form.header.parameters[index].value"
                  :options="dynamicOptions"
                  :placeholder="$t('Choose contact information')"
                  :native="true"
                  :class="'sm:col-span-6'"
                />
              </div>
              <div
                v-if="
                  ['IMAGE', 'DOCUMENT', 'VIDEO'].includes(
                    form.header.parameters[index].type,
                  )
                "
                class="w-full mt-3 space-y-3"
              >
                <FormSelect
                  v-model="form.header.parameters[index].selection"
                  :name="$t('Header media')"
                  :options="mediaHeaderOptions"
                  :class="'sm:col-span-6'"
                  @update:modelValue="(value) => handleHeaderMediaSelectionChange(index, value)"
                />
                <div v-if="form.header.parameters[index].selection === 'default'">
                  <div class="flex items-center gap-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                    <span class="font-medium">{{ $t("Template media") }}</span>
                    <span class="max-w-[24rem] truncate">
                      {{ form.header.parameters[index].url || form.header.parameters[index].value }}
                    </span>
                  </div>
                </div>
                <div v-else>
                  <div class="flex items-center gap-4">
                    <label
                      class="cursor-pointer flex justify-center px-2 py-2 w-[30%] bg-slate-200 shadow-sm rounded-lg border"
                      :class="
                        form.errors['header.parameters.0.value']
                          ? 'border border-red-700'
                          : ''
                      "
                      for="file-upload"
                    >
                      {{ $t("Upload") }}
                    </label>
                    <input
                      type="file"
                      class="sr-only"
                      :accept="
                        getFileAcceptAttribute(
                          form.header.parameters[index].type,
                        )
                      "
                      ref="fileInput"
                      id="file-upload"
                      @change="handleFileUpload($event, index)"
                    />
                    <div
                      v-if="form.header.parameters[index].value"
                      class="w-[20em] truncate"
                    >
                      {{
                        form.header.parameters[index].selection === "default"
                          ? form.header.parameters[index].value
                          : form.header.parameters[index].value.name
                      }}
                    </div>
                    <span v-else>{{ $t("No file chosen") }}</span>
                  </div>
                  <p
                    v-if="form.header.parameters[index].type === 'IMAGE'"
                    class="text-start text-xs mt-2"
                  >
                    {{ $t("Max file upload size is") }} <b>5MB</b> <br />
                    {{ $t("Supported file extensions") }}: .png, jpg
                  </p>
                  <p
                    v-if="form.header.parameters[index].type === 'DOCUMENT'"
                    class="text-start text-xs mt-2"
                  >
                    {{ $t("Max file upload size is") }} <b>30MB</b> <br />
                    {{ $t("Supported file extensions") }}: .pdf, .txt, .ppt,
                    .doc, .xls, .docx, .pptx, .xlsx
                  </p>
                  <p
                    v-if="form.header.parameters[index].type === 'VIDEO'"
                    class="text-start text-xs mt-2"
                  >
                    {{ $t("Max file upload size is") }} <b>16MB</b> <br />
                    {{ $t("Supported file extensions") }}: .mp4
                  </p>
                </div>
                <div
                  v-if="form.errors['header.parameters.0.value']"
                  class="form-error text-[#b91c1c] text-xs"
                >
                  {{ form.errors["header.parameters.0.value"] }}
                </div>
              </div>
            </div>
          </div>

          <div
            v-if="form.body.parameters.length > 0"
            class="bg-slate-100 p-3 mt-1 text-sm"
          >
            <h2
              class="flex items-center justify-between gap-2 pb-2 border-b"
            >
              <div class="flex items-center gap-2">
                <span>{{ $t("Body variables") }}</span>
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  width="16"
                  height="16"
                  viewBox="0 0 1024 1024"
                >
                  <path
                    fill="currentColor"
                    d="M512 64a448 448 0 1 1 0 896.064A448 448 0 0 1 512 64zm67.2 275.072c33.28 0 60.288-23.104 60.288-57.344s-27.072-57.344-60.288-57.344c-33.28 0-60.16 23.104-60.16 57.344s26.88 57.344 60.16 57.344zM590.912 699.2c0-6.848 2.368-24.64 1.024-34.752l-52.608 60.544c-10.88 11.456-24.512 19.392-30.912 17.28a12.992 12.992 0 0 1-8.256-14.72l87.68-276.992c7.168-35.136-12.544-67.2-54.336-71.296c-44.096 0-108.992 44.736-148.48 101.504c0 6.784-1.28 23.68.064 33.792l52.544-60.608c10.88-11.328 23.552-19.328 29.952-17.152a12.8 12.8 0 0 1 7.808 16.128L388.48 728.576c-10.048 32.256 8.96 63.872 55.04 71.04c67.84 0 107.904-43.648 147.456-100.416z"
                  />
                </svg>
              </div>
              <span>
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  width="16"
                  height="16"
                  viewBox="0 0 24 24"
                >
                  <path
                    fill="currentColor"
                    d="M6.102 16.98c-1.074 0-1.648-1.264-.94-2.073l5.521-6.31a1.75 1.75 0 0 1 2.634 0l5.522 6.31c.707.809.133 2.073-.94 2.073H6.101Z"
                  />
                </svg>
              </span>
            </h2>
            <p class="mt-2 text-xs leading-5 text-slate-600">{{ $t('For each message value, choose whether to type it yourself or use information saved for the contact.') }}</p>
            <div
              v-for="(item, index) in form.body.parameters"
              :key="index"
              class="mt-3 grid min-w-0 grid-cols-1 items-end gap-3 rounded-lg border border-slate-200 bg-white p-3 lg:grid-cols-[4rem_minmax(0,1fr)_minmax(0,1fr)]"
            >
              <div class="flex items-center">
                <span class="rounded-md bg-slate-100 px-2.5 py-2 text-sm font-semibold text-slate-700" v-text="'{{' + (index + 1) + '}}'"></span>
              </div>
              <div class="min-w-0">
                <FormSelect
                  v-model="form.body.parameters[index].selection"
                  :name="$t('Value source')"
                  :options="variableOptions"
                  :className="'w-full'"
                />
              </div>
              <div class="min-w-0">
                <FormInput
                  v-if="form.body.parameters[index].selection === 'static'"
                  :name="$t('Value')"
                  v-model="form.body.parameters[index].value"
                  :required="true"
                  :error="form.errors['body.parameters.' + index + '.value']"
                  :placeholder="$t('Type a value')"
                  :type="'text'"
                  :class="'w-full'"
                />
                <FormSelect
                  v-if="form.body.parameters[index].selection === 'dynamic'"
                  :name="$t('Value')"
                  v-model="form.body.parameters[index].value"
                  :required="true"
                  :error="form.errors['body.parameters.' + index + '.value']"
                  :options="dynamicOptions"
                  :placeholder="$t('Choose contact information')"
                  :native="true"
                  :className="'w-full'"
                />
              </div>
            </div>
          </div>

          <div v-if="hasButtonVariables" class="bg-slate-100 p-3 mt-1 text-sm">
            <h2
              class="flex items-center justify-between gap-2 pb-2 border-b"
            >
              <div class="flex items-center gap-2">
                <span>{{ $t("Button variables") }}</span>
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  width="16"
                  height="16"
                  viewBox="0 0 1024 1024"
                >
                  <path
                    fill="currentColor"
                    d="M512 64a448 448 0 1 1 0 896.064A448 448 0 0 1 512 64zm67.2 275.072c33.28 0 60.288-23.104 60.288-57.344s-27.072-57.344-60.288-57.344c-33.28 0-60.16 23.104-60.16 57.344s26.88 57.344 60.16 57.344zM590.912 699.2c0-6.848 2.368-24.64 1.024-34.752l-52.608 60.544c-10.88 11.456-24.512 19.392-30.912 17.28a12.992 12.992 0 0 1-8.256-14.72l87.68-276.992c7.168-35.136-12.544-67.2-54.336-71.296c-44.096 0-108.992 44.736-148.48 101.504c0 6.784-1.28 23.68.064 33.792l52.544-60.608c10.88-11.328 23.552-19.328 29.952-17.152a12.8 12.8 0 0 1 7.808 16.128L388.48 728.576c-10.048 32.256 8.96 63.872 55.04 71.04c67.84 0 107.904-43.648 147.456-100.416z"
                  />
                </svg>
              </div>
              <span>
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  width="16"
                  height="16"
                  viewBox="0 0 24 24"
                >
                  <path
                    fill="currentColor"
                    d="M6.102 16.98c-1.074 0-1.648-1.264-.94-2.073l5.521-6.31a1.75 1.75 0 0 1 2.634 0l5.522 6.31c.707.809.133 2.073-.94 2.073H6.101Z"
                  />
                </svg>
              </span>
            </h2>
            <p class="mt-2 text-xs leading-5 text-slate-600">{{ $t('For each button value, type a value yourself or choose information saved for the contact.') }}</p>
            <div v-for="(item, index) in form.buttons" :key="index">
              <div
                v-if="item.parameters.length > 0"
                class="mt-4 bg-slate-50 p-3"
              >
                <div class="w-[100%] mb-1">
                  <span>{{ $t("Button text") }}: {{ item.text }}</span>
                </div>
                <div
                  v-for="(value, key) in item.parameters"
                  :key="key"
                  class="grid min-w-0 grid-cols-1 gap-3 lg:grid-cols-2"
                >
                  <div class="min-w-0">
                    <FormSelect
                      v-model="value.type"
                      :name="$t('Value source')"
                      :options="variableOptions"
                      :className="'w-full'"
                    />
                  </div>
                  <div class="min-w-0">
                    <FormInput
                      v-if="value.type === 'static'"
                      v-model="value.value"
                      :name="$t('Value')"
                      :required="true"
                      :error="
                        form.errors['buttons.' + index + '.parameters.0.value']
                      "
                      :placeholder="$t('Type the value to use on every message.')"
                      :type="'text'"
                      :class="'w-full'"
                    />
                    <FormSelect
                      v-if="value.type === 'dynamic'"
                      v-model="value.value"
                      :name="$t('Value')"
                      :required="true"
                      :error="
                        form.errors['buttons.' + index + '.parameters.0.value']
                      "
                      :options="dynamicOptions"
                      :placeholder="$t('Choose contact information')"
                      :native="true"
                      :className="'w-full'"
                    />
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Standard submit button -->
          <div class="flex items-center justify-end gap-3 mt-3">
            <div v-if="displayCancelBtn">
              <Link
                href="/campaigns"
                class="block rounded-md px-3 py-2 text-sm text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 bg-slate-200"
              >
                {{ $t("Cancel") }}
              </Link>
            </div>
            <div>
              <button
                type="submit"
                class="rounded-md px-3 py-2 text-sm text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 bg-primary"
                :disabled="isSubmitDisabled"
              >
                <span v-if="!isLoading">{{
                  submitLabel
                }}</span>
                <svg
                  v-else
                  xmlns="http://www.w3.org/2000/svg"
                  width="24"
                  height="24"
                  viewBox="0 0 24 24"
                >
                  <path
                    fill="currentColor"
                    d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z"
                    opacity=".5"
                  />
                  <path
                    fill="currentColor"
                    d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"
                  >
                    <animateTransform
                      attributeName="transform"
                      dur="1s"
                      from="0 12 12"
                      repeatCount="indefinite"
                      to="360 12 12"
                      type="rotate"
                    />
                  </path>
                </svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    </form>

    <!-- ── RIGHT PANEL: Preview ─────────────────────────────────────────── -->
    <div class="hidden min-h-0 md:block md:w-[50%] py-10 px-10 overflow-y-auto overscroll-contain chat-bg">
      <div class="flex justify-center items-start min-h-full">
        <!-- Carousel preview -->
        <div v-if="isCarouselTemplate && form.template" class="w-full max-w-md">
          <!-- Top-level message body bubble -->
          <div
            class="ms-auto rounded-lg rounded-ss-none my-1 p-1 text-sm bg-white flex flex-col relative speech-bubble-left w-[25em] mb-2"
          >
            <p class="px-2 normal-case whitespace-pre-wrap">
              {{ carouselMessageBody }}
            </p>
            <div class="text-[#8c8c8c] mt-1 px-2 text-end">
              <span class="text-xs leading-none">9:15</span>
            </div>
          </div>
          <!-- Scrollable card row -->
          <CarouselPreview :cards="carouselCards" />
        </div>

        <!-- Standard template preview (unchanged) -->
        <div v-else>
          <WhatsappTemplate
            :parameters="previewParameters"
            :placeholder="false"
            :visible="form.template ? true : false"
          />
        </div>
      </div>
    </div>
  </div>
</template>
