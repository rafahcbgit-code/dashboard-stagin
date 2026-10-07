<template>
    <section v-if="category === 'MARKETING'" class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4" aria-labelledby="template-unsubscribe-title">
        <div class="flex items-start gap-3">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-white text-emerald-700 shadow-sm" aria-hidden="true">✓</span>
            <div class="min-w-0 flex-1">
                <h2 id="template-unsubscribe-title" class="text-sm font-bold text-emerald-950">{{ $t('Optional unsubscribe button') }}</h2>
                <p class="mt-1 text-sm leading-6 text-emerald-900">{{ $t('Give recipients a quick way to stop marketing messages. The button starts with your campaign keyword; you can edit its text or remove it in the buttons list.') }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <span class="text-xs text-emerald-800">{{ $t(hasButton ? 'Current button keyword' : 'Default campaign keyword') }}:</span>
                    <bdi class="rounded-full border border-emerald-200 bg-white px-3 py-1 text-sm font-bold text-emerald-900" dir="auto">{{ keyword || rules.unsubscribe_keyword }}</bdi>
                    <Link :href="rules.unsubscribe_settings_url || '/settings/campaign'" class="text-xs font-semibold text-emerald-800 underline underline-offset-2">{{ $t('Manage unsubscribe words') }}</Link>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <p class="text-xs leading-5 text-emerald-800">{{ $t('This button uses one of the three quick reply slots.') }}</p>
                    <button v-if="!hasButton" type="button" @click="$emit('add')" class="rounded-lg border border-emerald-300 bg-white px-3 py-1.5 text-xs font-semibold text-emerald-900 hover:bg-emerald-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700">{{ $t('Add unsubscribe button') }}</button>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
defineProps({ category: { type: String, required: true }, rules: { type: Object, default: () => ({ unsubscribe_keyword: 'stop', unsubscribe_settings_url: '/settings/campaign' }) }, keyword: { type: String, default: '' }, hasButton: { type: Boolean, default: true } });
defineEmits(['add']);
</script>
