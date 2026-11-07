<script setup>
import axios from "axios";
import FormInput from "@/Components/FormInput.vue";
import FormSelect from "@/Components/FormSelect.vue";
import CampaignAudienceSelect from "@/Components/CampaignAudienceSelect.vue";
import WhatsappTemplate from "@/Components/WhatsappTemplate.vue";
import CarouselPreview from "@/Components/Template/CarouselPreview.vue";
import { ref, computed, onMounted, nextTick, watch } from "vue";
import { Link, router, useForm } from "@inertiajs/vue3";
import "vue3-toastify/dist/index.css";
import { trans } from "laravel-vue-i18n";
import { useI18n } from "vue-i18n";

const { t, locale } = useI18n();

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
  campaignDraft: {
    type: Object,
    default: null,
  },
});

const isLoading = ref(false);
const campaignFormElement = ref(null);
const preflight = ref(null);
const isPreflightLoading = ref(false);
const preflightError = ref(null);
const showCampaignReview = ref(false);
const campaignStep = ref(1);
const largeAudienceConfirmed = ref(false);
const campaignReviewDialog = ref(null);
const campaignReviewHeading = ref(null);
let preflightController = null;
let preflightRequestId = 0;
let preflightTimer = null;
const isTemplateLoading = ref(false);
const templateLoadError = ref(null);
const loadedTemplateUuid = ref(null);
let templateLoadRequestId = 0;
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
  { value: "static", label: t("static"), description: t("A fixed value is used for every recipient.") },
  { value: "dynamic", label: t("Use contact information"), description: t("A value is filled from each recipient's contact details.") },
]);

const buttonVariableOptions = computed(() => [
  { value: "static", label: t("static"), description: t("A fixed value is used for every recipient.") },
  { value: "dynamic", label: t("Use contact information"), description: t("A value is filled from each recipient's contact details.") },
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
const hasCampaignSetupErrors = computed(() => ["name", "template", "contacts", "time"].some((field) => Boolean(form.errors[field])));

const contactUuid = computed(() => {
  return typeof props.contact === "object" ? props.contact?.uuid : props.contact;
});

const resolveContactValue = (field) => {
  const contact = typeof props.contact === "object" ? props.contact : null;
  const previewValues = preflight.value?.audience?.preview_values || {};
  if (!contact) return previewValues[field] ?? null;

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
  if (props.isCampaignFlow) return t("Continue to final review");

  return props.sendText ? t(props.sendText) : t("Send");
});

const requiredCampaignMessages = computed(() => {
  if (preflight.value?.audience) return Number(preflight.value.audience.recipient_count || 0);
  if (!form.contacts) return 0;
  if (form.contacts === "all") return props.totalContacts || 0;

  const selectedGroup = props.contactGroups.find((group) => group.uuid === form.contacts);
  return Number(selectedGroup?.contacts_count || 0);
});

const audienceOptions = computed(() => [
  { value: "all", label: trans("all contacts"), description: trans("All eligible contacts in your organization.") },
  ...(Array.isArray(props.contactGroups) ? props.contactGroups : []).map((group) => ({
    value: group.uuid,
    label: group.name,
    description: trans(":count contacts in this group", { count: Number(group.contacts_count || 0).toLocaleString() }),
  })),
]);

const selectedAudienceLabel = computed(() =>
  audienceOptions.value.find((option) => String(option.value) === String(form.contacts))?.label || t("Audience not selected"),
);

const formattedCampaignSendTime = computed(() => {
  if (form.skip_schedule) return t("Immediately after confirmation");
  if (!form.time) return "—";

  const match = String(form.time).match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
  if (!match) return form.time;

  const [, year, month, day, hour, minute] = match;
  const wallClockDate = new Date(Date.UTC(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute)));
  return new Intl.DateTimeFormat(locale.value || "ar", {
    dateStyle: "long",
    timeStyle: "short",
    timeZone: "UTC",
  }).format(wallClockDate);
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
        return resolveContactValue(parameter.value) || (props.isCampaignFlow ? t("No value in sample contact") : parameter.value) || match;
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

const dynamicFieldsForPreview = computed(() => {
  const values = [];
  if (form.header?.format === "TEXT") {
    (form.header.parameters || []).forEach((parameter) => {
      if (parameter.selection === "dynamic" && parameter.value) values.push(parameter.value);
    });
  }
  (form.body.parameters || []).forEach((parameter) => {
    if (parameter.selection === "dynamic" && parameter.value) values.push(parameter.value);
  });
  return [...new Set(values)];
});

const campaignVariablesReady = computed(() => {
  if (!props.isCampaignFlow) return true;
  const textVariables = [
    ...(form.header?.format === "TEXT" ? form.header.parameters || [] : []),
    ...(form.body.parameters || []),
  ];
  return textVariables.every((parameter) =>
    typeof parameter.value === "string"
      && parameter.value.trim() !== ""
      && (parameter.selection === "static"
        || (parameter.selection === "dynamic"
          && dynamicOptions.value.some((option) => option.value === parameter.value)))
  );
});

const sampleContactName = computed(() => preflight.value?.audience?.preview?.[0]?.name || t("No eligible sample contact"));
const missingSampleDynamicFields = computed(() => dynamicFieldsForPreview.value.filter((field) => {
  const value = preflight.value?.audience?.preview_values?.[field];
  return value === null || value === undefined || String(value).trim() === "";
}));

const campaignReviewChecks = computed(() => (preflight.value?.checks || []).map((check) =>
  check.key === "variables"
    ? { ...check, status: campaignVariablesReady.value ? "ready" : "blocked", detail: campaignVariablesReady.value ? t("Every message field has a fixed value or is connected to contact information.") : t("Enter a value or choose contact information for every message field.") }
    : check
));
const campaignSetupChecks = computed(() => campaignReviewChecks.value.filter((check) => check.key !== "variables"));
const campaignWarningCount = computed(() => campaignSetupChecks.value.filter((check) => check.status === "warning").length);

const campaignReadinessLabel = computed(() => {
  if (isPreflightLoading.value) return t("Checking");
  if (preflightError.value) return t("Unable to check campaign readiness.");
  if (!preflight.value) return t("Waiting for campaign details");
  if (!preflight.value.ready) return t("Needs attention");
  return campaignWarningCount.value ? t("Ready with notices") : t("Ready to continue");
});

const campaignCheckStatusLabel = (status) => {
  if (status === "ready") return t("Complete");
  if (status === "warning") return t("Notice");
  return t("Needs attention");
};

const campaignCanBeConfirmed = computed(() =>
  Boolean(preflight.value?.ready)
    && campaignVariablesReady.value
    && Boolean(form.name?.trim())
    && (form.skip_schedule || Boolean(form.time))
    && !isPreflightLoading.value
);

const campaignSteps = computed(() => [
  { number: 1, title: t("Campaign details") },
  { number: 2, title: t("Message content") },
  { number: 3, title: t("Final review") },
]);

const scheduleAfterInterval = (time) => {
  if (!time) return;
  form.skip_schedule = false;
  form.time = time;
  showCampaignReview.value = false;
  campaignStep.value = 1;
  scrollCampaignToTop();
};

const scrollCampaignToTop = async () => {
  await nextTick();
  requestAnimationFrame(() => {
    let scrollContainer = campaignFormElement.value?.parentElement;
    while (scrollContainer && scrollContainer !== document.body) {
      const overflowY = window.getComputedStyle(scrollContainer).overflowY;
      if (["auto", "scroll"].includes(overflowY) && scrollContainer.scrollHeight > scrollContainer.clientHeight) {
        scrollContainer.scrollTo({ top: 0, behavior: "smooth" });
        break;
      }
      scrollContainer = scrollContainer.parentElement;
    }
    campaignFormElement.value?.scrollTo({ top: 0, behavior: "smooth" });
    window.scrollTo({ top: 0, behavior: "smooth" });
  });
};

const focusFirstCampaignSetupError = async () => {
  await nextTick();
  const error = campaignFormElement.value?.querySelector(".form-error");
  const field = error?.parentElement;
  const control = field?.querySelector("select") || field?.querySelector("input, button");
  if (control) {
    control.focus();
    control.scrollIntoView({ behavior: "smooth", block: "center" });
  }
};

const continueToMessage = async () => {
  form.clearErrors("name", "template", "contacts", "time");
  let valid = true;
  [
    ["name", form.name],
    ["template", form.template],
    ["contacts", form.contacts],
    ...(!form.skip_schedule ? [["time", form.time]] : []),
  ].forEach(([field, value]) => {
    if (!String(value || "").trim()) {
      form.setError(field, t("This field is required."));
      valid = false;
    }
  });

  if (!valid) {
    await focusFirstCampaignSetupError();
    return;
  }
  if (!isTemplateReady.value) {
    form.setError(
      "template",
      templateLoadError.value || t("Please wait for the template to finish loading."),
    );
    await focusFirstCampaignSetupError();
    return;
  }

  if (!props.isCampaignFlow && !contactUuid.value) {
    form.setError("template", t("Contact not found."));
    return;
  }
  campaignStep.value = 2;
  scrollCampaignToTop();
};

const returnToCampaignSetup = () => {
  showCampaignReview.value = false;
  campaignStep.value = 1;
  scrollCampaignToTop();
};

const returnToMessage = () => {
  showCampaignReview.value = false;
  campaignStep.value = 2;
  scrollCampaignToTop();
  nextTick(() => document.querySelector("[data-campaign-review-trigger]")?.focus());
};

const handleCampaignReviewKeydown = (event) => {
  if (event.key === "Escape") {
    event.preventDefault();
    returnToMessage();
    return;
  }

  if (event.key !== "Tab") return;

  const focusable = [...(campaignReviewDialog.value?.querySelectorAll(
    'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])',
  ) || [])].filter((element) => element.offsetParent !== null);
  if (!focusable.length) {
    event.preventDefault();
    campaignReviewHeading.value?.focus();
    return;
  }

  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (event.shiftKey && (document.activeElement === first || document.activeElement === campaignReviewHeading.value)) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
};

watch(showCampaignReview, async (isOpen) => {
  if (!isOpen) return;
  await nextTick();
  campaignReviewHeading.value?.focus();
});

const loadCampaignPreflight = async () => {
  if (preflightTimer) {
    clearTimeout(preflightTimer);
    preflightTimer = null;
  }
  if (!props.isCampaignFlow) return;
  const requestId = ++preflightRequestId;
  preflightController?.abort();
  preflight.value = null;
  preflightError.value = null;

  if (!form.template || !form.contacts || (!form.skip_schedule && !form.time) || isTemplateLoading.value || !isTemplateReady.value) {
    isPreflightLoading.value = false;
    return;
  }

  preflightController = new AbortController();
  isPreflightLoading.value = true;
  try {
    const response = await fetch("/campaigns/preflight", {
      method: "POST",
      signal: preflightController.signal,
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "",
      },
      body: JSON.stringify({
        template: form.template,
        contacts: form.contacts,
        time: form.skip_schedule ? null : form.time,
        skip_schedule: form.skip_schedule,
        dynamic_fields: dynamicFieldsForPreview.value,
      }),
    });
    const data = await response.json();
    if (requestId !== preflightRequestId) return;
    if (!response.ok) throw new Error(data.message || t("Unable to check campaign readiness."));
    preflight.value = data;
  } catch (error) {
    if (error.name !== "AbortError" && requestId === preflightRequestId) {
      preflightError.value = error.message || t("Unable to check campaign readiness.");
    }
  } finally {
    if (requestId === preflightRequestId) isPreflightLoading.value = false;
  }
};

const scheduleCampaignPreflight = () => {
  if (preflightTimer) clearTimeout(preflightTimer);
  preflightController?.abort();
  preflightRequestId += 1;
  preflight.value = null;
  preflightError.value = null;

  if (!form.template || !form.contacts || (!form.skip_schedule && !form.time) || isTemplateLoading.value || !isTemplateReady.value) {
    isPreflightLoading.value = false;
    return;
  }

  isPreflightLoading.value = true;
  preflightTimer = setTimeout(loadCampaignPreflight, 250);
};

watch(
  () => [form.template, form.contacts, form.time, form.skip_schedule, dynamicFieldsForPreview.value.join("|")],
  scheduleCampaignPreflight,
);

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
            selection: props.isCampaignFlow ? "dynamic" : "static",
            value: props.isCampaignFlow ? null : item,
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
          selection: props.isCampaignFlow ? "dynamic" : "static",
          value: props.isCampaignFlow ? null : item,
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
                type: "static",
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
      if (props.isCampaignFlow) loadCampaignPreflight();
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

const submitForm = async () => {
  if (props.isCampaignFlow && campaignStep.value === 1) {
    continueToMessage();
    return;
  }

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

  if (props.isCampaignFlow) {
    // Fetch a fresh server-side readiness and audience snapshot before opening
    // the final review. This also absorbs any debounced request still pending.
    await loadCampaignPreflight();
    isLoading.value = false;
    largeAudienceConfirmed.value = false;
    if (!campaignCanBeConfirmed.value) {
      if (!preflightError.value) {
        preflightError.value = t("Complete the campaign details and readiness checks before the final review.");
      }
      return;
    }
    campaignStep.value = 3;
    showCampaignReview.value = true;
    return;
  }

  if (isCarouselTemplate.value && !props.isCampaignFlow) {
    router.post("/chat/" + encodeURIComponent(contactUuid.value) + "/send/carousel-template", {
      template: form.template,
    }, {
      preserveScroll: true,
      onSuccess: (page) => {
        if (page.props.flash?.status?.type === "success") emit("viewTemplate", false);
      },
      onFinish: () => { isLoading.value = false; },
    });
    return;
  }

  form.post(
    props.isCampaignFlow
      ? "/campaigns"
      : "/chat/" + encodeURIComponent(contactUuid.value) + "/send/template",
    {
      preserveScroll: true,
      onSuccess: (page) => {
        if (!props.isCampaignFlow && page.props.flash?.status?.type === "success") {
          emit("viewTemplate", false);
        }
      },
      onFinish: () => { isLoading.value = false; },
    },
  );
};

const confirmCampaign = () => {
  if (!campaignCanBeConfirmed.value || (requiredCampaignMessages.value >= 1000 && !largeAudienceConfirmed.value)) return;
  showCampaignReview.value = false;
  isLoading.value = true;
  form.post("/campaigns", {
    onError: (errors) => {
      const errorKeys = Object.keys(errors || {});
      const setupFields = ["name", "template", "contacts", "time"];
      campaignStep.value = errorKeys.some((key) => setupFields.includes(key)) ? 1 : 2;
    },
    onFinish: () => { isLoading.value = false; },
  });
};

const emit = defineEmits(["viewTemplate"]);

const viewTemplate = () => {
  emit("viewTemplate", false);
};

onMounted(() => {
  templateOptions.value = transformOptions(props.templates);
  if (props.campaignDraft) {
    form.name = props.campaignDraft.name || null;
    form.contacts = props.campaignDraft.contacts || null;
    form.template = props.campaignDraft.template || null;
    if (form.template) loadTemplate(form.template);
  }
});
</script>

<template>
  <div :class="isCampaignFlow ? 'md:flex md:h-full md:min-h-0 md:overflow-hidden' : 'flex h-full min-h-0 md:flex-row'">
    <div
      v-if="!settings?.whatsapp"
      class="min-h-0 p-4 md:h-full md:w-1/2 md:overflow-y-auto md:p-8"
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
      ref="campaignFormElement"
      @submit.prevent="submitForm()"
      class="min-h-0 min-w-0 overflow-y-auto overscroll-contain md:h-full md:shrink-0"
      :class="isCampaignFlow ? 'md:w-[50%] p-4 md:p-8' : 'w-full flex-1 md:w-[50%]'"
    >
      <div
        v-if="displayTitle"
        class="m-1 mb-4 flex items-center justify-between rounded-lg border border-slate-200 bg-white px-3 py-3 shadow-sm"
      >
        <div>
          <h3 class="text-[15px] font-semibold text-slate-900">{{ $t("Send Template Message") }}</h3>
          <p class="mt-0.5 text-xs text-slate-500">{{ $t("Choose a template and review the exact message before sending.") }}</p>
        </div>
        <button
          type="button"
          @click="viewTemplate()"
          class="text-sm md:inline-flex hidden justify-center rounded-md border border-transparent bg-red-800 px-4 py-1 text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
        >
          {{ $t("Cancel") }}
        </button>
      </div>

      <nav v-if="isCampaignFlow" class="mb-6" :aria-label="$t('Campaign creation steps')">
        <ol class="flex items-center gap-2 sm:gap-3">
          <li v-for="step in campaignSteps" :key="step.number" class="relative min-w-0 flex-1">
            <div
              class="flex min-h-12 min-w-0 flex-1 items-center gap-2 rounded-xl border px-2 py-2 shadow-sm transition-colors sm:min-h-14 sm:gap-2.5 sm:px-3"
              :class="campaignStep === step.number ? 'border-emerald-700 bg-emerald-700 text-white shadow-emerald-900/10' : (campaignStep > step.number ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-white text-slate-500')"
              :aria-current="campaignStep === step.number ? 'step' : undefined"
            >
              <span
                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border text-xs font-bold"
                :class="campaignStep === step.number ? 'border-white/50 bg-white text-emerald-800' : (campaignStep > step.number ? 'border-emerald-300 bg-emerald-100 text-emerald-800' : 'border-slate-300 bg-slate-50 text-slate-500')"
                aria-hidden="true"
              >{{ campaignStep > step.number ? '✓' : step.number }}</span>
              <span class="min-w-0 truncate text-[11px] font-semibold leading-tight sm:text-sm">{{ step.title }}</span>
            </div>
            <span v-if="step.number < campaignSteps.length" class="absolute -end-2 top-1/2 z-10 h-0.5 w-2 -translate-y-1/2 rounded-full sm:-end-3 sm:w-3" :class="campaignStep > step.number ? 'bg-emerald-300' : 'bg-slate-200'" aria-hidden="true"></span>
          </li>
        </ol>
      </nav>

      <p v-if="isCampaignFlow && campaignStep === 1 && hasCampaignSetupErrors" class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950" role="alert">
        {{ $t("Complete the highlighted campaign details before continuing.") }}
      </p>

      <!-- ── TEMPLATE SELECTOR (shared by both standard and carousel) ── -->
      <div
        v-if="!isCampaignFlow || campaignStep === 1"
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
        <CampaignAudienceSelect
          v-if="isCampaignFlow"
          v-model="form.contacts"
          :options="audienceOptions"
          :name="$t('Send to')"
          :required="true"
          :placeholder="$t('Select contacts')"
          :search-placeholder="$t('Search contact groups')"
          :no-results="$t('No matching contact groups')"
          :search-error="$t('Unable to search contact groups. Try again.')"
          :results-hint="$t('Showing the first 50 results. Type to narrow your search.')"
          :searching-label="$t('Searching contact groups...')"
          :search-url="'/campaigns/contact-groups/search'"
          :error="form.errors.contacts"
          class="sm:col-span-3 min-w-0"
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
        <section
          v-if="isCampaignFlow && form.template && form.contacts"
          class="sm:col-span-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
          aria-live="polite"
        >
          <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-100 px-4 py-3">
            <div>
              <h3 class="font-semibold text-slate-900">{{ $t("Campaign readiness") }}</h3>
              <p class="mt-1 text-xs text-slate-600">{{ $t("Review each item before you continue to the final confirmation.") }}</p>
            </div>
            <span
              class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold"
              :class="isPreflightLoading || !preflight ? 'bg-slate-200 text-slate-700' : (preflight.ready ? (campaignWarningCount ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-900') : 'bg-red-100 text-red-900')"
              role="status"
            >
              <span v-if="isPreflightLoading" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-slate-400 border-t-primary" aria-hidden="true"></span>
              <span v-else aria-hidden="true">{{ !preflight ? '…' : (preflight.ready ? (campaignWarningCount ? '!' : '✓') : '×') }}</span>
              {{ campaignReadinessLabel }}
            </span>
          </div>
          <p v-if="preflightError" class="m-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">{{ preflightError }}</p>
          <div v-else-if="isPreflightLoading && !preflight" class="flex items-center gap-3 p-4 text-sm text-slate-600" role="status">
            <span class="h-4 w-4 animate-spin rounded-full border-2 border-slate-300 border-t-primary" aria-hidden="true"></span>
            {{ $t("Checking campaign details and recipients...") }}
          </div>
          <ul v-else-if="campaignSetupChecks.length" class="divide-y divide-slate-100 px-4">
            <li v-for="check in campaignSetupChecks" :key="check.key" class="flex flex-col gap-3 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
              <div class="flex min-w-0 items-start gap-2.5">
                <span
                  class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                  :class="check.status === 'ready' ? 'bg-emerald-100 text-emerald-800' : (check.status === 'warning' ? 'bg-amber-100 text-amber-900' : 'bg-red-100 text-red-800')"
                  aria-hidden="true"
                >{{ check.status === 'ready' ? '✓' : (check.status === 'warning' ? '!' : '×') }}</span>
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-slate-900">{{ $t(check.label) }}</span>
                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="check.status === 'ready' ? 'bg-emerald-100 text-emerald-800' : (check.status === 'warning' ? 'bg-amber-100 text-amber-900' : 'bg-red-100 text-red-800')">{{ campaignCheckStatusLabel(check.status) }}</span>
                  </div>
                  <p class="mt-1 break-words text-xs leading-5 text-slate-600">{{ check.detail }}</p>
                </div>
              </div>
              <div class="flex shrink-0 flex-wrap items-center gap-2 sm:ms-8">
                <Link v-if="['blocked', 'warning'].includes(check.status) && check.action?.path" :href="check.action.path" class="inline-flex min-h-9 items-center justify-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-800 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ $t(check.action.label) }}</Link>
                <button v-if="check.key === 'marketing_interval' && check.schedule_after" type="button" class="inline-flex min-h-9 items-center justify-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-800 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="scheduleAfterInterval(check.schedule_after)">{{ $t("Schedule after allowed time") }}</button>
              </div>
            </li>
          </ul>
          <div v-if="preflight?.audience" class="mx-4 mb-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <div>
                <p class="font-semibold text-slate-900">{{ $t("Recipients who can receive this campaign") }}</p>
                <p class="mt-0.5 text-xs text-slate-600">{{ selectedAudienceLabel }}</p>
              </div>
              <p class="text-lg font-bold text-slate-900">{{ preflight.audience.recipient_count.toLocaleString() }}</p>
            </div>
            <p class="mt-1 text-xs leading-5 text-slate-600">{{ $t("Excluded") }}: {{ preflight.audience.excluded_unsubscribed.toLocaleString() }} {{ $t("unsubscribed") }}, {{ preflight.audience.excluded_blocked.toLocaleString() }} {{ $t("blocked") }}, {{ preflight.audience.excluded_without_phone.toLocaleString() }} {{ $t("without a phone number") }}</p>
            <p v-if="preflight.audience.preview.length" class="mt-2 text-xs leading-5 text-slate-700"><span class="font-medium">{{ $t("First recipients") }}:</span> {{ preflight.audience.preview.map((contact) => contact.name).join('، ') }}</p>
            <p class="mt-2 text-[11px] leading-5 text-slate-500">{{ $t("Phone number validity is checked again when sending; an invalid number may still fail.") }}</p>
          </div>
          <div class="border-t border-sky-200 bg-sky-50 px-4 py-3 text-xs leading-5 text-sky-900" role="note">{{ $t("Blocked items must be fixed before campaign creation. Notices are informational and do not stop you from continuing.") }}</div>
        </section>
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
        <p v-if="isCampaignFlow && !form.skip_schedule" class="sm:col-span-6 -mt-3 text-xs text-slate-600">
          {{ $t("Time zone") }}: {{ settings?.timezone || 'UTC' }}. {{ $t("The scheduled time uses your organization time zone.") }}
        </p>
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
        <p v-if="isCampaignFlow && form.skip_schedule" class="sm:col-span-6 -mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
          {{ $t("This campaign will start sending as soon as it is confirmed. Messages already sent cannot be recalled.") }}
        </p>
      </div>

      <div v-if="isCampaignFlow && campaignStep === 1" class="sticky bottom-0 z-10 -mx-4 flex flex-col-reverse gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 shadow-[0_-6px_20px_rgba(15,23,42,0.06)] backdrop-blur sm:flex-row sm:justify-between md:-mx-8 md:px-8">
        <Link v-if="displayCancelBtn" href="/campaigns" class="inline-flex justify-center rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
          {{ $t("Cancel") }}
        </Link>
        <button type="button" class="inline-flex justify-center rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" :disabled="isTemplateLoading" @click="continueToMessage">
          {{ $t("Continue to message") }}
        </button>
      </div>

      <div v-if="!isCampaignFlow || campaignStep === 2" :class="isCampaignFlow ? '' : 'px-3 md:px-3'">
        <div v-if="isCampaignFlow && (preflightError || (preflight && !preflight.ready))" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950" role="alert">
          <p class="font-semibold">{{ preflightError || $t("Some campaign checks need attention before you can continue.") }}</p>
          <ul v-if="preflight?.checks?.some((check) => check.status === 'blocked')" class="mt-2 space-y-2">
            <li v-for="check in preflight.checks.filter((item) => item.status === 'blocked')" :key="check.key" class="flex flex-wrap items-start justify-between gap-2">
              <span><strong>{{ $t(check.label) }}:</strong> {{ check.detail }}</span>
              <Link v-if="check.action?.path" :href="check.action.path" class="shrink-0 font-medium underline underline-offset-2">{{ $t(check.action.label) }}</Link>
            </li>
          </ul>
        </div>
        <details v-if="isCampaignFlow" class="mb-4 overflow-hidden rounded-xl border border-slate-200 bg-white md:hidden">
          <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
            <span>{{ $t("Preview message") }}</span>
            <span class="text-xs font-normal text-slate-500">{{ $t("Based on a sample contact") }}</span>
          </summary>
          <div class="border-t border-slate-100 p-3">
            <div v-if="dynamicFieldsForPreview.length" class="mb-3 rounded-lg border p-3 text-xs leading-5" :class="missingSampleDynamicFields.length ? 'border-amber-200 bg-amber-50 text-amber-950' : 'border-emerald-200 bg-emerald-50 text-emerald-950'">
              <p>{{ $t("Preview sample contact") }}: <strong>{{ sampleContactName }}</strong></p>
              <p v-if="missingSampleDynamicFields.length" class="mt-1">{{ $t("The sample contact has no value for these fields") }}: {{ missingSampleDynamicFields.join('، ') }}. {{ $t("Other recipients may have different values; this preview does not check every contact.") }}</p>
              <p v-else class="mt-1">{{ $t("This preview uses one eligible contact. Other recipients may see different values.") }}</p>
            </div>
            <div class="template-preview-bg max-h-[min(60vh,520px)] overflow-auto rounded-lg p-2">
              <div v-if="isCarouselTemplate" class="mx-auto w-full max-w-md">
                <div class="ms-auto mb-2 flex w-full max-w-[25em] flex-col rounded-lg bg-white p-2 text-sm shadow-sm">
                  <p class="whitespace-pre-wrap">{{ carouselMessageBody }}</p>
                  <span class="mt-1 text-end text-xs text-slate-400">9:15</span>
                </div>
                <CarouselPreview :cards="carouselCards" />
              </div>
              <WhatsappTemplate v-else :parameters="previewParameters" :placeholder="false" :visible="Boolean(form.template)" />
            </div>
          </div>
        </details>
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
          <div :class="isCampaignFlow ? 'sticky bottom-0 z-10 -mx-3 mt-4 flex flex-col-reverse items-stretch justify-end gap-3 border-t border-slate-200 bg-white/95 px-3 py-3 shadow-[0_-6px_20px_rgba(15,23,42,0.06)] backdrop-blur sm:flex-row sm:items-center md:static md:mx-0 md:border-0 md:bg-transparent md:p-0 md:shadow-none' : 'mt-4 flex items-center justify-end gap-3'">
            <button v-if="isCampaignFlow" type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="returnToCampaignSetup">
              {{ $t("Back to campaign details") }}
            </button>
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
              :data-campaign-review-trigger="isCampaignFlow ? '' : null"
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
          <div v-if="isCampaignFlow && dynamicFieldsForPreview.length" class="mb-4 rounded-xl border p-3 text-xs leading-5" :class="missingSampleDynamicFields.length ? 'border-amber-200 bg-amber-50 text-amber-950' : 'border-sky-200 bg-sky-50 text-sky-950'" role="status">
            <p class="font-semibold">{{ $t("Preview sample contact") }}: {{ sampleContactName }}</p>
            <p v-if="missingSampleDynamicFields.length" class="mt-1">{{ $t("The sample contact has no value for these fields") }}: {{ missingSampleDynamicFields.join('، ') }}. {{ $t("Other recipients may have different values; this preview does not check every contact.") }}</p>
            <p v-else class="mt-1">{{ $t("This preview uses one eligible contact. Other recipients may see different values.") }}</p>
          </div>
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
            <p v-if="isCampaignFlow" class="mt-2 text-xs leading-5 text-slate-600">{{ $t('For each variable, enter one value to use for everyone or connect a contact field to personalize it. The preview uses the first eligible contact.') }}</p>
            <p v-else class="mt-2 text-xs leading-5 text-slate-600">{{ $t('For each message value, choose whether to type it yourself or use information saved for the contact.') }}</p>
            <div
              v-for="(item, index) in form.body.parameters"
              :key="index"
              class="mt-3 min-w-0 rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
            >
              <div class="mb-3 flex items-center justify-between gap-3 border-b border-slate-100 pb-3">
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-slate-900">{{ $t('Value') }} {{ index + 1 }}</p>
                  <p class="mt-0.5 text-xs text-slate-500">{{ $t('Choose how this value will be filled before sending.') }}</p>
                </div>
                <span class="shrink-0 rounded-lg bg-emerald-50 px-2.5 py-1.5 font-mono text-sm font-semibold text-emerald-700" dir="ltr" v-text="'{{' + (index + 1) + '}}'"></span>
              </div>
              <div class="grid min-w-0 grid-cols-1 gap-3">
                <FormSelect
                  v-model="form.body.parameters[index].selection"
                  :name="$t('Value source')"
                  :options="variableOptions"
                  :className="'w-full min-w-0'"
                />
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
                  :className="'w-full min-w-0'"
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
                      :options="buttonVariableOptions"
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
          <div :class="isCampaignFlow ? 'sticky bottom-0 z-10 -mx-3 mt-4 flex flex-col-reverse items-stretch justify-end gap-3 border-t border-slate-200 bg-white/95 px-3 py-3 shadow-[0_-6px_20px_rgba(15,23,42,0.06)] backdrop-blur sm:flex-row sm:items-center md:static md:mx-0 md:border-0 md:bg-transparent md:p-0 md:shadow-none' : 'mt-4 flex items-center justify-end gap-3'">
            <button v-if="isCampaignFlow" type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="returnToCampaignSetup">
              {{ $t("Back to campaign details") }}
            </button>
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
                :data-campaign-review-trigger="isCampaignFlow ? '' : null"
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

    <div v-if="showCampaignReview" class="fixed inset-0 z-[70] flex items-center justify-center overflow-y-auto bg-slate-950/55 p-3 sm:p-6" role="dialog" aria-modal="true" aria-labelledby="campaign-review-title" @keydown="handleCampaignReviewKeydown">
      <section ref="campaignReviewDialog" class="my-auto flex max-h-[94vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div class="flex items-start justify-between gap-4 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:px-7">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-primary">{{ $t("Step 3 of 3") }}</p>
            <h2 id="campaign-review-title" ref="campaignReviewHeading" tabindex="-1" class="mt-1 text-xl font-bold text-slate-900 focus:outline-none">{{ $t("Final review") }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ $t("Check these details before creating the campaign.") }}</p>
          </div>
          <span class="inline-flex shrink-0 items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold" :class="preflight?.ready ? (campaignWarningCount ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-900') : 'bg-red-100 text-red-900'" role="status">
            <span aria-hidden="true">{{ preflight?.ready ? (campaignWarningCount ? '!' : '✓') : '×' }}</span>
            {{ campaignReadinessLabel }}
          </span>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-7">
          <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3.5">
              <dt class="text-xs font-medium text-emerald-800">{{ $t("Sender") }}</dt>
              <dd class="mt-1 break-words font-semibold text-slate-900">{{ settings?.whatsapp?.display_phone_number || settings?.whatsapp?.phone_number || $t("Connected WhatsApp number") }}</dd>
            </div>
            <div class="rounded-xl border border-sky-200 bg-sky-50 p-3.5">
              <dt class="text-xs font-medium text-sky-800">{{ $t("Campaign name") }}</dt>
              <dd class="mt-1 break-words font-semibold text-slate-900">{{ form.name || '—' }}</dd>
            </div>
            <div class="rounded-xl border border-violet-200 bg-violet-50 p-3.5">
              <dt class="text-xs font-medium text-violet-800">{{ $t("Template") }}</dt>
              <dd class="mt-1 break-words font-semibold text-slate-900">{{ preflight?.template?.name || '—' }}</dd>
              <dd v-if="preflight?.template?.category" class="mt-1 text-xs text-slate-600">{{ $t(preflight.template.category) }}</dd>
            </div>
            <div class="rounded-xl border p-3.5" :class="form.skip_schedule ? 'border-amber-300 bg-amber-50' : 'border-indigo-200 bg-indigo-50'">
              <dt class="text-xs font-medium" :class="form.skip_schedule ? 'text-amber-900' : 'text-indigo-800'">{{ $t("Send time") }}</dt>
              <dd class="mt-1 font-semibold text-slate-900">{{ formattedCampaignSendTime }}</dd>
              <dd v-if="!form.skip_schedule" class="mt-1 text-xs text-slate-600">{{ $t("Time zone") }}: {{ settings?.timezone || 'UTC' }}</dd>
              <dd v-else class="mt-1 text-xs leading-5 text-amber-900">{{ $t("This campaign will start sending as soon as it is confirmed. Messages already sent cannot be recalled.") }}</dd>
            </div>
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-3.5 sm:col-span-2">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <dt class="text-xs font-medium text-blue-800">{{ $t("Audience") }}</dt>
                  <dd class="mt-1 break-words font-semibold text-slate-900">{{ selectedAudienceLabel }}</dd>
                </div>
                <div class="rounded-lg bg-white px-3 py-2 text-end shadow-sm">
                  <dd class="text-xl font-bold text-blue-900">{{ preflight?.audience?.recipient_count?.toLocaleString?.() || 0 }}</dd>
                  <dt class="text-xs text-slate-600">{{ $t("eligible recipients") }}</dt>
                </div>
              </div>
              <div class="mt-3 flex flex-wrap gap-2 border-t border-blue-200 pt-3 text-xs">
                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">{{ $t("Excluded") }}: {{ ((preflight?.audience?.excluded_unsubscribed || 0) + (preflight?.audience?.excluded_blocked || 0) + (preflight?.audience?.excluded_without_phone || 0)).toLocaleString() }}</span>
                <span class="rounded-full bg-amber-100 px-2.5 py-1 text-amber-900">{{ (preflight?.audience?.excluded_unsubscribed?.toLocaleString?.() || 0) }} {{ $t("unsubscribed") }}</span>
                <span class="rounded-full bg-rose-100 px-2.5 py-1 text-rose-900">{{ (preflight?.audience?.excluded_blocked?.toLocaleString?.() || 0) }} {{ $t("blocked") }}</span>
                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">{{ (preflight?.audience?.excluded_without_phone?.toLocaleString?.() || 0) }} {{ $t("without a phone number") }}</span>
              </div>
              <p v-if="preflight?.audience?.preview?.length" class="mt-2 text-xs leading-5 text-slate-700"><span class="font-medium">{{ $t("First recipients") }}:</span> {{ preflight.audience.preview.map((contact) => contact.name).join('، ') }}</p>
            </div>
          </dl>

          <section class="mt-5" aria-labelledby="campaign-final-checks-title">
            <div class="flex items-end justify-between gap-3">
              <div>
                <h3 id="campaign-final-checks-title" class="font-semibold text-slate-900">{{ $t("Final checks") }}</h3>
                <p class="mt-1 text-xs text-slate-600">{{ $t("Green checks are complete. Notices are useful guidance and do not stop sending.") }}</p>
              </div>
            </div>
            <ul class="mt-3 space-y-2">
              <li v-for="check in campaignReviewChecks" :key="check.key" class="flex items-start gap-3 rounded-xl border p-3 text-sm" :class="check.status === 'ready' ? 'border-emerald-200 bg-emerald-50/60' : (check.status === 'warning' ? 'border-amber-200 bg-amber-50/70' : 'border-red-200 bg-red-50/70')">
                <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold" :class="check.status === 'ready' ? 'bg-emerald-100 text-emerald-800' : (check.status === 'warning' ? 'bg-amber-100 text-amber-900' : 'bg-red-100 text-red-800')" aria-hidden="true">{{ check.status === 'ready' ? '✓' : (check.status === 'warning' ? '!' : '×') }}</span>
                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-center gap-2">
                    <p class="font-semibold text-slate-900">{{ $t(check.label) }}</p>
                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="check.status === 'ready' ? 'bg-emerald-100 text-emerald-900' : (check.status === 'warning' ? 'bg-amber-100 text-amber-900' : 'bg-red-100 text-red-900')">{{ campaignCheckStatusLabel(check.status) }}</span>
                  </div>
                  <p class="mt-1 leading-5 text-slate-700">{{ check.detail }}</p>
                  <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2">
                    <Link v-if="['blocked', 'warning'].includes(check.status) && check.action?.path" :href="check.action.path" class="inline-flex font-medium text-primary underline underline-offset-2">{{ $t(check.action.label) }}</Link>
                    <button v-if="check.key === 'marketing_interval' && check.schedule_after" type="button" class="inline-flex font-medium text-primary underline underline-offset-2" @click="scheduleAfterInterval(check.schedule_after)">{{ $t("Schedule after allowed time") }}</button>
                  </div>
                </div>
              </li>
            </ul>
          </section>

          <label v-if="(preflight?.audience?.recipient_count || 0) >= 1000" class="mt-4 flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-3.5 text-sm text-amber-950">
            <input v-model="largeAudienceConfirmed" type="checkbox" class="mt-1 h-4 w-4 rounded border-amber-400 text-primary focus:ring-primary" />
            <span><strong class="block">{{ $t("Large audience confirmation") }}</strong>{{ $t("Large audience confirmation explanation", { count: preflight.audience.recipient_count.toLocaleString() }) }}</span>
          </label>

          <p v-if="preflightError" class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">{{ preflightError }}</p>
        </div>

        <div class="sticky bottom-0 flex flex-col-reverse gap-2 border-t border-slate-200 bg-white/95 px-5 py-4 backdrop-blur sm:flex-row sm:justify-between sm:px-7">
          <button type="button" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" @click="returnToMessage">{{ $t("Back to edit") }}</button>
          <button type="button" class="rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:brightness-95 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50" :disabled="!campaignCanBeConfirmed || ((preflight?.audience?.recipient_count || 0) >= 1000 && !largeAudienceConfirmed) || isLoading" @click="confirmCampaign">
            {{ isLoading ? $t("Creating campaign...") : $t("Confirm and create campaign") }}
          </button>
        </div>
      </section>
    </div>

    <!-- ── RIGHT PANEL: Preview ─────────────────────────────────────────── -->
    <div class="template-preview-bg hidden min-h-0 overscroll-contain md:flex md:h-full md:w-1/2 md:flex-col md:overflow-y-auto md:px-8 md:py-6 lg:px-10">
      <div class="mb-4 w-full rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
        <h3 class="text-sm font-semibold text-slate-900">{{ $t("Message preview") }}</h3>
        <p class="mt-1 text-xs leading-5 text-slate-500">{{ $t("This is the message that will be sent to the customer after you confirm.") }}</p>
      </div>
      <div class="flex h-fit min-h-0 w-full justify-center items-start rounded-xl border border-slate-200 bg-transparent p-4">
        <!-- Carousel preview -->
        <div v-if="isCarouselTemplate && form.template" class="w-full max-w-md">
          <!-- Top-level message body bubble -->
          <div
            class="ms-auto mb-2 flex w-full max-w-[25em] flex-col rounded-2xl rounded-ss-none border border-slate-200 bg-white p-3 text-sm shadow-sm"
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
