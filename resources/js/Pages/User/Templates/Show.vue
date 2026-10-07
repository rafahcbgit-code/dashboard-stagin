<template>
    <AppLayout>
        <div class="flex min-h-full flex-col bg-white text-[#000] md:border-s">
            <div class="flex items-center justify-between border-b px-4 py-4 md:px-8">
                <div>
                    <h2 class="text-xl">{{ $t('View') }} {{ $t('Template') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ template.name }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <Link
                        v-if="canEdit"
                        :href="`/templates/${template.uuid}`"
                        class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500"
                    >{{ $t('edit') }}</Link>
                    <Link href="/templates" class="rounded-md bg-black px-3 py-2 text-sm text-white shadow-sm hover:bg-slate-700">
                        {{ $t('Back') }}
                    </Link>
                </div>
            </div>

            <div class="grid flex-1 gap-6 p-4 md:grid-cols-[minmax(260px,0.7fr)_minmax(340px,1.3fr)] md:p-8">
                <section class="h-fit rounded-lg border bg-white p-5 shadow-sm">
                    <h3 class="mb-4 text-base font-semibold">{{ $t('Template') }}</h3>
                    <dl class="space-y-4 text-sm">
                        <div v-for="item in details" :key="item.label" class="border-b pb-3 last:border-0 last:pb-0">
                            <dt class="mb-1 text-xs text-slate-500">{{ $t(item.label) }}</dt>
                            <dd class="break-words normal-case text-slate-900">{{ item.value || '-' }}</dd>
                        </div>
                    </dl>
                </section>

                <section
                    class="min-w-0 overflow-hidden rounded-lg border p-4 shadow-sm md:p-8"
                    style="background-image: url('/images/whatsapp-bg-02.png');"
                >
                    <div v-if="isCarousel">
                        <div v-if="carouselMessageBody" class="mb-2 w-full max-w-[25em] rounded-lg rounded-ss-none bg-white p-3 text-sm shadow-sm">
                            <p class="normal-case whitespace-pre-wrap" dir="auto">{{ carouselMessageBody }}</p>
                            <p class="mt-1 text-end text-xs text-[#8c8c8c]">9:15</p>
                        </div>
                        <CarouselPreview :cards="carouselCards" />
                    </div>

                    <div v-else>
                        <div class="w-full max-w-[25em] rounded-lg rounded-ss-none bg-white p-1 text-sm shadow-sm">
                            <div v-if="headerFormat && headerFormat !== 'TEXT'" class="mb-3 overflow-hidden rounded bg-[#ccd0d5]">
                                <img
                                    v-if="headerFormat === 'IMAGE' && headerMediaUrl"
                                    :src="headerMediaUrl"
                                    class="block max-h-80 w-full object-cover"
                                    alt=""
                                >
                                <video v-else-if="headerFormat === 'VIDEO' && headerMediaUrl" :src="headerMediaUrl" controls class="block max-h-80 w-full"></video>
                                <div v-else class="flex justify-center py-8">
                                    <img :src="headerPlaceholder" alt="" class="max-h-28">
                                </div>
                            </div>
                            <h3 v-else-if="headerText" class="px-2 pt-1 text-base font-semibold normal-case" dir="auto">{{ headerText }}</h3>
                            <p class="px-2 py-1 normal-case whitespace-pre-wrap" dir="auto">{{ bodyText || $t('Content not available') }}</p>
                            <div class="px-2 pb-1 text-[#8c8c8c]">
                                <span class="text-xs normal-case" dir="auto">{{ footerText }}</span>
                                <span class="float-end text-xs">9:15</span>
                            </div>
                        </div>

                        <div v-if="buttons.length" class="mt-1 flex w-full max-w-[25em] flex-col text-sm text-[#00a5f4]">
                            <div v-for="(button, index) in buttons" :key="index" class="my-[0.1em] flex h-10 items-center justify-center rounded-lg bg-white px-3 shadow-sm">
                                <span class="normal-case" dir="auto">{{ buttonLabel(button) }}</span>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Link } from '@inertiajs/vue3';
import AppLayout from './../Layout/App.vue';
import CarouselPreview from '@/Components/Template/CarouselPreview.vue';

const props = defineProps({
    template: {
        type: Object,
        required: true,
    },
});
const { t, locale } = useI18n();

const metadata = computed(() => {
    if (!props.template.metadata) return {};
    if (typeof props.template.metadata === 'object') return props.template.metadata;

    try {
        return JSON.parse(props.template.metadata);
    } catch {
        return {};
    }
});

const components = computed(() => Array.isArray(metadata.value.components) ? metadata.value.components : []);
const component = (type) => components.value.find((item) => (item.type || '').toUpperCase() === type);

const isCarousel = computed(() => metadata.value.template_type === 'carousel' || Boolean(component('CAROUSEL')));
const carouselComponent = computed(() => component('CAROUSEL'));
const carouselCards = computed(() => metadata.value.carousel_cards || carouselComponent.value?.cards || []);
const carouselMessageBody = computed(() => metadata.value.carousel_message_body || component('BODY')?.text || '');

const header = computed(() => component('HEADER') || {});
const headerFormat = computed(() => (header.value.format || '').toUpperCase());
const headerText = computed(() => header.value.text || '');
const bodyText = computed(() => component('BODY')?.text || '');
const footerText = computed(() => component('FOOTER')?.text || '');
const buttons = computed(() => component('BUTTONS')?.buttons || []);
const headerMediaUrl = computed(() => metadata.value._default_header_media?.url || metadata.value.default_header_media?.url || '');
const headerPlaceholder = computed(() => {
    if (headerFormat.value === 'VIDEO') return '/images/video-placeholder.png';
    if (headerFormat.value === 'DOCUMENT') return '/images/document-placeholder.png';
    return '/images/image-placeholder.png';
});

const canEdit = computed(() => ['APPROVED', 'REJECTED', 'PAUSED'].includes(props.template.status));
const categoryLabel = (value) => t({ MARKETING: 'Marketing category', UTILITY: 'Utility category', AUTHENTICATION: 'Authentication category' }[value] || value || 'Unknown status');
const statusLabel = (value) => t({ PENDING: 'Under Meta review', APPROVED: 'Ready to use', REJECTED: 'Rejected by Meta', PAUSED: 'Temporarily paused', DISABLED: 'Disabled by Meta', IN_APPEAL: 'Appeal under review' }[value] || 'Unknown status');
const buttonLabel = (button) => button.text || button.example || t({ QUICK_REPLY: 'Custom', URL: 'Website', PHONE_NUMBER: 'Phone', COPY_CODE: 'Copy code' }[button.type] || 'Button');
const languageLabel = (value) => {
    const [language, region] = String(value || '').split(/[-_]/);
    if (!language) return t('Unknown language');
    try {
        const displayLocale = locale.value === 'ar' ? 'ar' : 'en';
        const languageName = new Intl.DisplayNames([displayLocale], { type: 'language' }).of(language);
        const regionName = region ? new Intl.DisplayNames([displayLocale], { type: 'region' }).of(region) : '';
        return regionName ? `${languageName} (${regionName})` : languageName;
    } catch {
        return String(value).replace('_', '-');
    }
};
const formatDate = (value) => {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
};
const details = computed(() => [
    { label: 'Name', value: props.template.name },
    { label: 'Category', value: categoryLabel(props.template.category) },
    { label: 'Language', value: languageLabel(props.template.language) },
    { label: 'Status', value: statusLabel(props.template.status) },
    { label: 'Last updated', value: formatDate(props.template.updated_at) },
]);
</script>
