<template>
    <AppLayout>
        <div class="px-4 py-8 md:px-8">
            <div class="mb-6">
                <h1 class="text-2xl font-semibold text-slate-900">{{ $t('Web Widget') }}</h1>
                <p class="mt-1 text-sm text-slate-600">
                    {{ $t('Configure your organization WhatsApp widget and embed it on any website.') }}
                </p>
            </div>

            <div class="grid grid-cols-1 gap-6">
                <form class="space-y-4 max-w-4xl" @submit.prevent="submit">
                    <section class="rounded-lg border border-slate-200 bg-white p-4">
                        <h2 class="text-base font-medium text-slate-900 mb-4">{{ $t('Widget Settings') }}</h2>

                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">
                                    {{ $t('Logo') }}
                                </label>
                                <div class="flex items-center gap-4">
                                    <div class="h-16 w-16 overflow-hidden rounded-full border border-slate-200 bg-slate-50">
                                        <img v-if="logoPreview" :src="logoPreview" alt="Widget logo preview"
                                            class="h-full w-full object-cover" />
                                        <div v-else class="h-full w-full flex items-center justify-center text-slate-400">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22"
                                                viewBox="0 0 24 24">
                                                <path fill="currentColor"
                                                    d="M4 5a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v14a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3zm8 1a1 1 0 0 0-1 1v3H8a1 1 0 1 0 0 2h3v3a1 1 0 1 0 2 0v-3h3a1 1 0 1 0 0-2h-3V7a1 1 0 0 0-1-1" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div>
                                        <input type="file" accept=".jpg,.jpeg,.png,.webp"
                                            class="block w-full text-sm text-slate-600 file:mr-4 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200"
                                            @change="onLogoChange" />
                                        <p class="mt-1 text-xs text-slate-500">{{ $t('Accepted: JPG, PNG, WEBP. Max 2MB.') }}</p>
                                    </div>
                                </div>
                                <p v-if="form.errors.logo" class="mt-1 text-xs text-red-700">{{ form.errors.logo }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">
                                    {{ $t('WhatsApp Phone') }}
                                </label>
                                <input v-model="form.whatsapp_phone" type="text" placeholder="+15551234567"
                                    class="w-full rounded-md border px-3 py-2 text-sm outline-none"
                                    :class="form.errors.whatsapp_phone ? 'border-red-500' : 'border-slate-300'" />
                                <p v-if="form.errors.whatsapp_phone" class="mt-1 text-xs text-red-700">
                                    {{ form.errors.whatsapp_phone }}
                                </p>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">
                                        {{ $t('Header Text') }}
                                    </label>
                                    <input v-model="form.header_text" type="text"
                                        class="w-full rounded-md border px-3 py-2 text-sm outline-none"
                                        :class="form.errors.header_text ? 'border-red-500' : 'border-slate-300'" />
                                    <p v-if="form.errors.header_text" class="mt-1 text-xs text-red-700">
                                        {{ form.errors.header_text }}
                                    </p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">
                                        {{ $t('Header Subtext') }}
                                    </label>
                                    <input v-model="form.header_subtext" type="text"
                                        class="w-full rounded-md border px-3 py-2 text-sm outline-none"
                                        :class="form.errors.header_subtext ? 'border-red-500' : 'border-slate-300'" />
                                    <p v-if="form.errors.header_subtext" class="mt-1 text-xs text-red-700">
                                        {{ form.errors.header_subtext }}
                                    </p>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">
                                    {{ $t('Widget Text') }}
                                </label>
                                <textarea v-model="form.widget_text" rows="3"
                                    class="w-full rounded-md border px-3 py-2 text-sm outline-none"
                                    :class="form.errors.widget_text ? 'border-red-500' : 'border-slate-300'" />
                                <p v-if="form.errors.widget_text" class="mt-1 text-xs text-red-700">
                                    {{ form.errors.widget_text }}
                                </p>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">
                                        {{ $t('Button Text') }}
                                    </label>
                                    <input v-model="form.button_text" type="text"
                                        class="w-full rounded-md border px-3 py-2 text-sm outline-none"
                                        :class="form.errors.button_text ? 'border-red-500' : 'border-slate-300'" />
                                    <p v-if="form.errors.button_text" class="mt-1 text-xs text-red-700">
                                        {{ form.errors.button_text }}
                                    </p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">
                                        {{ $t('Widget Type') }}
                                    </label>
                                    <select v-model="form.widget_type"
                                        class="w-full rounded-md border px-3 py-2 text-sm outline-none"
                                        :class="form.errors.widget_type ? 'border-red-500' : 'border-slate-300'">
                                        <option value="start_button">{{ $t('With start chat button') }}</option>
                                        <option value="input_field">{{ $t('With input field') }}</option>
                                    </select>
                                    <p v-if="form.errors.widget_type" class="mt-1 text-xs text-red-700">
                                        {{ form.errors.widget_type }}
                                    </p>
                                </div>
                            </div>

                            <div v-if="form.widget_type === 'input_field'">
                                <label class="block text-sm font-medium text-slate-700 mb-1">
                                    {{ $t('Input Placeholder') }}
                                </label>
                                <input v-model="form.input_placeholder" type="text"
                                    class="w-full rounded-md border px-3 py-2 text-sm outline-none"
                                    :class="form.errors.input_placeholder ? 'border-red-500' : 'border-slate-300'" />
                                <p v-if="form.errors.input_placeholder" class="mt-1 text-xs text-red-700">
                                    {{ form.errors.input_placeholder }}
                                </p>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">
                                        {{ $t('Button Color') }}
                                    </label>
                                    <div class="flex items-center gap-2">
                                        <input v-model="form.button_color" type="color" class="h-10 w-14 rounded border border-slate-300 p-1" />
                                        <input v-model="form.button_color" type="text"
                                            class="w-full rounded-md border px-3 py-2 text-sm outline-none"
                                            :class="form.errors.button_color ? 'border-red-500' : 'border-slate-300'" />
                                    </div>
                                    <p v-if="form.errors.button_color" class="mt-1 text-xs text-red-700">
                                        {{ form.errors.button_color }}
                                    </p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">
                                        {{ $t('Header Color') }}
                                    </label>
                                    <div class="flex items-center gap-2">
                                        <input v-model="form.header_color" type="color" class="h-10 w-14 rounded border border-slate-300 p-1" />
                                        <input v-model="form.header_color" type="text"
                                            class="w-full rounded-md border px-3 py-2 text-sm outline-none"
                                            :class="form.errors.header_color ? 'border-red-500' : 'border-slate-300'" />
                                    </div>
                                    <p v-if="form.errors.header_color" class="mt-1 text-xs text-red-700">
                                        {{ form.errors.header_color }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-4">
                        <div class="flex items-center justify-between">
                            <h2 class="text-base font-medium text-slate-900">{{ $t('Embed Script') }}</h2>
                            <button type="button" @click="copySnippet"
                                class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                {{ copied ? $t('Copied') : $t('Copy Embed Script') }}
                            </button>
                        </div>
                        <p class="mt-2 text-xs text-slate-500">{{ $t('Paste this script on your website.') }}</p>
                        <pre class="mt-3 overflow-x-auto rounded-md bg-slate-900 p-3 text-xs text-slate-100"><code>{{ embedSnippet }}</code></pre>
                        <p class="mt-2 text-xs text-slate-500">
                            {{ $t('Configuration endpoint:') }} {{ embed_config_url }}
                        </p>
                    </section>

                    <div class="flex justify-end">
                        <button type="submit"
                            class="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90 disabled:opacity-60"
                            :disabled="form.processing">
                            <svg v-if="form.processing" xmlns="http://www.w3.org/2000/svg" width="18" height="18"
                                viewBox="0 0 24 24" class="mr-2">
                                <path fill="currentColor"
                                    d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z"
                                    opacity=".5" />
                                <path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z">
                                    <animateTransform attributeName="transform" dur="1s" from="0 12 12"
                                        repeatCount="indefinite" to="360 12 12" type="rotate" />
                                </path>
                            </svg>
                            <span>{{ form.processing ? $t('Saving...') : $t('Save Widget Settings') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="fixed bottom-5 right-5 z-[60]">
            <button
                ref="chatButtonRef"
                type="button"
                @click="toggleChat"
                class="h-14 w-14 rounded-full shadow-lg grid place-items-center transition-transform duration-200 hover:scale-[1.03] active:scale-[0.98]"
                :style="{ backgroundColor: '#25D366' }"
                :aria-expanded="isChatOpen ? 'true' : 'false'"
                aria-label="Toggle WhatsApp preview"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" class="w-7 h-7 fill-white">
                    <path d="M16.027 3C9.38 3 4 8.375 4 15.016c0 2.566.77 4.953 2.105 6.938L4 29l7.27-2.07A12.02 12.02 0 0 0 16.027 27C22.67 27 28 21.625 28 14.984 28 8.344 22.67 3 16.027 3zm5.918 17.402c-.25.703-1.46 1.36-2.02 1.453-.541.098-1.23.14-1.984-.123-.457-.156-1.043-.34-1.793-.668-3.155-1.37-5.207-4.57-5.36-4.785-.152-.215-1.28-1.705-1.28-3.253 0-1.55.807-2.316 1.094-2.63.285-.312.623-.39.832-.39.207 0 .416.003.598.01.193.008.448-.073.701.535.25.609.85 2.094.926 2.246.076.152.127.33.025.543-.102.215-.152.33-.305.512-.152.18-.32.4-.457.535-.152.152-.31.317-.133.623.18.312.797 1.312 1.715 2.125 1.18 1.047 2.176 1.375 2.49 1.527.316.152.5.127.684-.076.18-.203.785-.91.996-1.223.213-.312.406-.254.684-.152.278.102 1.758.828 2.06.977.304.152.508.228.582.355.07.13.07.75-.18 1.45z"/>
                </svg>
            </button>

            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0 translate-y-2 scale-[0.98]"
                enter-to-class="opacity-100 translate-y-0 scale-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100 translate-y-0 scale-100"
                leave-to-class="opacity-0 translate-y-2 scale-[0.98]"
            >
                <div
                    v-if="isChatOpen"
                    ref="chatPopupRef"
                    class="absolute bottom-16 right-0 w-[340px] max-w-[calc(100vw-28px)] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl"
                >
                    <div class="flex items-center gap-3 p-3 text-white" :style="{ backgroundColor: form.header_color || '#128C7E' }">
                        <img v-if="logoPreview" :src="logoPreview" alt="Logo" class="h-10 w-10 rounded-full object-cover bg-white" />
                        <div v-else class="h-10 w-10 rounded-full bg-white/90"></div>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold leading-tight truncate">{{ form.header_text || $t('Chat with us') }}</p>
                            <p class="text-xs opacity-95 leading-tight truncate">{{ form.header_subtext || $t('We usually reply in a few minutes') }}</p>
                        </div>
                    </div>

                    <div class="p-3">
                        <p v-if="form.widget_text" class="mb-3 text-sm text-slate-700 whitespace-pre-line">
                            {{ form.widget_text }}
                        </p>

                        <div v-if="form.widget_type === 'input_field'" class="flex gap-2">
                            <input
                                v-model="chatMessage"
                                type="text"
                                :placeholder="form.input_placeholder || $t('Type your message...')"
                                class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-400"
                            />
                            <button
                                type="button"
                                class="rounded-md px-4 py-2 text-sm font-medium text-white disabled:opacity-60 disabled:cursor-not-allowed"
                                :style="{ backgroundColor: form.button_color || '#25D366' }"
                                :disabled="!phoneIsUsable"
                                @click="sendFromPopup"
                            >
                                {{ form.button_text || $t('Send') }}
                            </button>
                        </div>

                        <button
                            v-else
                            type="button"
                            class="w-full rounded-md px-4 py-2 text-sm font-medium text-white disabled:opacity-60 disabled:cursor-not-allowed"
                            :style="{ backgroundColor: form.button_color || '#25D366' }"
                            :disabled="!phoneIsUsable"
                            @click="startFromPopup"
                        >
                            {{ form.button_text || $t('Start Chat') }}
                        </button>

                        <p v-if="!phoneIsUsable" class="mt-2 text-xs text-slate-500">
                            {{ $t('Enter a valid WhatsApp phone number (with country code) to test.') }}
                        </p>
                    </div>
                </div>
            </Transition>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../Layout/App.vue';

const props = defineProps({
    widget: {
        type: Object,
        required: true,
    },
    organization_ref: {
        type: String,
        required: true,
    },
    embed_script_url: {
        type: String,
        required: true,
    },
    embed_config_url: {
        type: String,
        required: true,
    },
});

const copied = ref(false);
const isChatOpen = ref(false);
const chatMessage = ref('');
const logoPreview = ref(props.widget.logo_url || null);
let objectUrl = null;
const chatButtonRef = ref(null);
const chatPopupRef = ref(null);

const form = useForm({
    logo: null,
    whatsapp_phone: props.widget.whatsapp_phone || '',
    header_text: props.widget.header_text || '',
    header_subtext: props.widget.header_subtext || '',
    widget_text: props.widget.widget_text || '',
    button_text: props.widget.button_text || '',
    widget_type: props.widget.widget_type || 'start_button',
    input_placeholder: props.widget.input_placeholder || '',
    button_color: props.widget.button_color || '#25D366',
    header_color: props.widget.header_color || '#128C7E',
});

const embedSnippet = computed(() => {
    return `<script src="${props.embed_script_url}" data-org="${props.organization_ref}"><\/script>`;
});

const phoneDigits = computed(() => String(form.whatsapp_phone || '').replace(/\D+/g, ''));
const phoneIsUsable = computed(() => {
    const digits = phoneDigits.value;
    return digits.length >= 8 && digits.length <= 15 && String(form.whatsapp_phone || '').trim().startsWith('+');
});

const onLogoChange = (event) => {
    const file = event.target.files?.[0] || null;
    form.logo = file;

    if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
        objectUrl = null;
    }

    if (file) {
        objectUrl = URL.createObjectURL(file);
        logoPreview.value = objectUrl;
    } else {
        logoPreview.value = props.widget.logo_url || null;
    }
};

const submit = () => {
    form.post('/web-widget', {
        forceFormData: true,
        preserveScroll: true,
    });
};

const toggleChat = async () => {
    isChatOpen.value = !isChatOpen.value;

    if (isChatOpen.value) {
        await nextTick();
        // Keep the popup message input ready without stealing focus from other inputs.
        if (form.widget_type === 'input_field') {
            chatMessage.value = chatMessage.value || '';
        }
    }
};

const closeChat = () => {
    isChatOpen.value = false;
};

const openWhatsapp = (message) => {
    if (!phoneIsUsable.value) return;

    const text = String(message || '').trim();
    const url = `https://wa.me/${encodeURIComponent(phoneDigits.value)}?text=${encodeURIComponent(text)}`;
    window.open(url, '_blank', 'noopener,noreferrer');
};

const sendFromPopup = () => {
    openWhatsapp(chatMessage.value || form.widget_text || '');
};

const startFromPopup = () => {
    openWhatsapp(form.widget_text || '');
};

const copySnippet = async () => {
    try {
        await navigator.clipboard.writeText(embedSnippet.value);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1500);
    } catch (error) {
        copied.value = false;
    }
};

const handleDocumentPointerDown = (event) => {
    if (!isChatOpen.value) return;

    const target = event.target;
    const buttonEl = chatButtonRef.value;
    const popupEl = chatPopupRef.value;

    if (buttonEl && buttonEl.contains(target)) return;
    if (popupEl && popupEl.contains(target)) return;

    closeChat();
};

const handleEscape = (event) => {
    if (event.key === 'Escape') closeChat();
};

onMounted(() => {
    document.addEventListener('pointerdown', handleDocumentPointerDown, true);
    document.addEventListener('keydown', handleEscape);
});

onBeforeUnmount(() => {
    if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
    }

    document.removeEventListener('pointerdown', handleDocumentPointerDown, true);
    document.removeEventListener('keydown', handleEscape);
});
</script>
