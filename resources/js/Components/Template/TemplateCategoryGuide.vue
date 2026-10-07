<template>
    <section class="mb-6 rounded-xl border border-slate-200 bg-white p-4" aria-labelledby="template-category-guide">
        <div class="flex items-start gap-3">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-sky-50 text-sky-700" aria-hidden="true">i</span>
            <div class="min-w-0">
                <h2 id="template-category-guide" class="text-sm font-bold text-slate-900">{{ title }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-600">{{ description }}</p>
                <p class="mt-2 text-xs leading-5" :class="category === 'MARKETING' ? 'text-amber-800' : 'text-slate-500'">{{ effect }}</p>
            </div>
        </div>
    </section>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
const props = defineProps({ category: { type: String, required: true } });
const { t } = useI18n();
const content = computed(() => ({
    MARKETING: { title: t('Marketing category'), description: t('Use it for offers, announcements, and messages that encourage a purchase or action.'), effect: t('Meta may price marketing messages differently based on the recipient country and its current pricing.') },
    UTILITY: { title: t('Utility category'), description: t('Use it for a clear update about an order, booking, account, or action the customer already made.'), effect: t('The message should stay connected to the customer action and avoid promotional content.') },
    AUTHENTICATION: { title: t('Authentication category'), description: t('Use it only to send a verification or login code.'), effect: t('WhatsApp controls the message structure. You choose security, code expiry, and delivery options.') },
}[props.category] || { title: t('Choose a template category'), description: t('Select the purpose of the message so Meta can review it correctly.'), effect: '' }));
const title = computed(() => content.value.title);
const description = computed(() => content.value.description);
const effect = computed(() => content.value.effect);
</script>
