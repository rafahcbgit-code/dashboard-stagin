<template>
  <SettingLayout :modules="modules">
    <form @submit.prevent="submitForm" class="mx-auto w-full max-w-4xl">
      <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6" aria-labelledby="unsubscribe-settings-title">
        <header class="border-b border-slate-100 pb-5">
          <div class="flex items-start gap-3">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700" aria-hidden="true">
              <svg xmlns="http://www.w3.org/2000/svg" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8m-8 4h5m-8 6 1.5-3H18a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v12a3 3 0 0 1-3 3Z" />
              </svg>
            </span>
            <div class="min-w-0">
              <h2 id="unsubscribe-settings-title" class="text-base font-semibold text-slate-900 sm:text-lg">{{ $t('Unsubscribe settings') }}</h2>
              <p class="mt-1 text-sm leading-6 text-slate-600">{{ $t('Unsubscribe settings introduction') }}</p>
            </div>
          </div>
        </header>

        <div class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(16rem,0.8fr)] lg:items-start">
          <div class="min-w-0">
            <label for="unsubscribe-trigger" class="block text-sm font-medium text-slate-800">{{ $t('Unsubscribe keyword') }}</label>
            <p id="unsubscribe-trigger-help" class="mt-1 text-sm leading-6 text-slate-600">{{ $t('Unsubscribe keyword instructions') }}</p>
            <input
              id="unsubscribe-trigger"
              v-model="form.unsubscribe_trigger"
              type="text"
              dir="auto"
              autocomplete="off"
              :placeholder="$t('Unsubscribe keyword placeholder')"
              :aria-describedby="'unsubscribe-trigger-help unsubscribe-keyword-examples'"
              :aria-invalid="Boolean(form.errors.unsubscribe_trigger)"
              class="mt-3 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-start text-sm text-slate-900 shadow-sm outline-none placeholder:text-slate-400 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-600/20"
            />
            <p v-if="form.errors.unsubscribe_trigger" class="mt-1 text-sm text-red-700" role="alert">{{ form.errors.unsubscribe_trigger }}</p>

            <div id="unsubscribe-keyword-examples" class="mt-4">
              <p class="text-xs font-medium text-slate-600">{{ $t('Suggested unsubscribe keywords') }}</p>
              <div class="mt-2 flex flex-wrap gap-2">
                <button
                  v-for="keyword in exampleKeywords"
                  :key="keyword"
                  type="button"
                  class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm text-slate-700 transition hover:border-emerald-300 hover:bg-emerald-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
                  @click="addExampleKeyword(keyword)"
                >{{ keyword }}</button>
              </div>
              <p class="mt-2 text-xs leading-5 text-slate-500">{{ $t('Unsubscribe keyword examples note') }}</p>
            </div>
          </div>

          <aside class="rounded-lg border border-sky-100 bg-sky-50 p-4" aria-labelledby="unsubscribe-how-title">
            <h3 id="unsubscribe-how-title" class="text-sm font-semibold text-sky-950">{{ $t('How unsubscribe works') }}</h3>
            <ol class="mt-3 space-y-3 text-sm leading-6 text-sky-950">
              <li class="flex gap-2"><span class="font-semibold">1.</span><span>{{ $t('Unsubscribe step receive') }}</span></li>
              <li class="flex gap-2"><span class="font-semibold">2.</span><span>{{ $t('Unsubscribe step status') }}</span></li>
              <li class="flex gap-2"><span class="font-semibold">3.</span><span>{{ $t('Unsubscribe step campaigns') }}</span></li>
            </ol>
            <p class="mt-4 border-t border-sky-200 pt-3 text-sm leading-6 text-sky-950">{{ $t('Find unsubscribed contacts') }}</p>
          </aside>
        </div>

        <footer class="mt-6 flex flex-col-reverse gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
          <p class="text-xs leading-5 text-slate-500">{{ $t('Unsubscribe keyword change note') }}</p>
          <button
            type="submit"
            :disabled="form.processing"
            class="inline-flex min-h-10 items-center justify-center rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white shadow-sm transition hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-60"
          >{{ form.processing ? $t('Saving') : $t('Save unsubscribe settings') }}</button>
        </footer>
      </section>
    </form>
  </SettingLayout>
</template>

<script setup>
import SettingLayout from './Layout.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps(['settings', 'modules']);
const exampleKeywords = ['STOP', 'UNSUBSCRIBE', 'إلغاء', 'إيقاف الرسائل', 'غير مهتم', 'Not interested'];
const organizationSettings = props.settings?.metadata ? JSON.parse(props.settings.metadata) : {};
const form = useForm({
  unsubscribe_trigger: organizationSettings?.campaign_settings?.unsubscribe_trigger ?? 'stop',
});

const addExampleKeyword = (keyword) => {
  const keywords = String(form.unsubscribe_trigger ?? '')
    .split(',')
    .map((value) => value.trim())
    .filter(Boolean);

  if (!keywords.some((value) => value.toLocaleLowerCase() === keyword.toLocaleLowerCase())) {
    keywords.push(keyword);
  }

  form.unsubscribe_trigger = keywords.join(', ');
};

const submitForm = () => {
  form.post('/settings/campaign', { preserveScroll: true });
};
</script>
