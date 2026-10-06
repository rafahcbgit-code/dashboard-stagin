<script setup>
import { computed, ref } from 'vue';
import { trans } from 'laravel-vue-i18n';
import axios from 'axios';
import SettingLayout from './Layout.vue';
import FormSelect from '@/Components/FormSelect.vue';

const props = defineProps({
    emailSettings: { type: Object, default: () => ({}) },
    hasSavedEmailPassword: { type: Boolean, default: false },
    modules: { type: Array, default: () => [] },
});

const initialSettings = {
    active: [true, 1, '1', 'true'].includes(props.emailSettings.active),
    host: props.emailSettings.host ?? '',
    port: props.emailSettings.port ?? 587,
    username: props.emailSettings.username ?? '',
    password: '',
    encryption: props.emailSettings.encryption ?? 'tls',
    from_name: props.emailSettings.from_name ?? '',
    from_address: props.emailSettings.from_address ?? '',
};

const emailSettings = ref({ ...initialSettings });
const savedSettings = ref({ ...initialSettings });
const hasSavedPassword = ref(props.hasSavedEmailPassword);
const isLoading = ref(false);
const testSending = ref(false);
const testResult = ref(null);
const testRecipientEmail = ref('');
const encryptionOptions = computed(() => [
    { value: 'tls', label: 'TLS' },
    { value: 'ssl', label: 'SSL' },
    { value: 'none', label: trans('No encryption') },
]);

const toggleActive = () => {
    emailSettings.value.active = !emailSettings.value.active;
};

const save = async () => {
    isLoading.value = true;
    testResult.value = null;

    try {
        await axios.post('/settings/notifications/email', emailSettings.value);
        hasSavedPassword.value = hasSavedPassword.value || Boolean(emailSettings.value.password);
        emailSettings.value.password = '';
        savedSettings.value = { ...emailSettings.value };
        testResult.value = { type: 'success', message: trans('Email settings saved successfully.') };
    } catch {
        testResult.value = { type: 'error', message: trans('Failed to save email settings. Check the fields and try again.') };
    } finally {
        isLoading.value = false;
    }
};

const resetChanges = () => {
    emailSettings.value = { ...savedSettings.value, password: '' };
    testRecipientEmail.value = '';
    testResult.value = null;
};

const sendTestEmail = async () => {
    testSending.value = true;
    testResult.value = null;

    try {
        const response = await axios.post('/settings/notifications/email/test', {
            email: testRecipientEmail.value,
            ...emailSettings.value,
        });

        testResult.value = response.data.success
            ? { type: 'success', message: trans('Test email sent successfully.') }
            : { type: 'error', message: trans('Test email could not be sent. Check your SMTP settings and try again.') };
    } catch {
        testResult.value = { type: 'error', message: trans('Test email could not be sent. Check your SMTP settings and try again.') };
    } finally {
        testSending.value = false;
    }
};
</script>

<template>
    <SettingLayout :modules="props.modules">
        <main class="mx-auto w-full max-w-4xl px-1 pb-16 sm:px-2">
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
            <header class="mb-5">
                <h1 class="text-xl font-semibold text-slate-900">{{ $t('Email Notifications') }}</h1>
                <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{{ $t('Email notifications page description') }}</p>
            </header>

            <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="email-notifications-toggle-title">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 id="email-notifications-toggle-title" class="font-semibold text-slate-900">{{ $t('Enable email notifications') }}</h2>
                            <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="emailSettings.active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'">
                                {{ $t(emailSettings.active ? 'Enabled' : 'Disabled') }}
                            </span>
                        </div>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ $t('Email assignment notifications explanation') }}</p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="emailSettings.active"
                        :aria-label="$t('Enable email notifications')"
                        class="relative mt-1 inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
                        :class="emailSettings.active ? 'bg-emerald-700' : 'bg-slate-300'"
                        @click="toggleActive"
                    >
                        <span class="sr-only">{{ $t('Enable email notifications') }}</span>
                        <span class="absolute start-1 size-4 rounded-full bg-white shadow transition-transform" :class="emailSettings.active ? 'ltr:translate-x-5 rtl:translate-x-[-20px]' : ''" aria-hidden="true"></span>
                    </button>
                </div>
            </section>

            <template v-if="emailSettings.active">
                <section class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="smtp-configuration-title">
                    <div class="mb-5">
                        <h2 id="smtp-configuration-title" class="font-semibold text-slate-900">{{ $t('SMTP Configuration') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ $t('SMTP configuration explanation') }}</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="smtp-host" class="mb-1 block text-sm font-medium text-slate-800">{{ $t('SMTP Host') }}</label>
                            <input id="smtp-host" v-model="emailSettings.host" type="text" autocomplete="off" dir="ltr" placeholder="smtp.example.com" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-start text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20" />
                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ $t('SMTP host help') }}</p>
                        </div>
                        <div>
                            <label for="smtp-port" class="mb-1 block text-sm font-medium text-slate-800">{{ $t('Port') }}</label>
                            <input id="smtp-port" v-model.number="emailSettings.port" type="number" min="1" max="65535" inputmode="numeric" dir="ltr" placeholder="587" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-start text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20" />
                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ $t('SMTP port help') }}</p>
                        </div>
                        <div>
                            <label for="smtp-username" class="mb-1 block text-sm font-medium text-slate-800">{{ $t('Username') }}</label>
                            <input id="smtp-username" v-model="emailSettings.username" type="text" autocomplete="username" dir="ltr" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-start text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20" />
                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ $t('SMTP username help') }}</p>
                        </div>
                        <div>
                            <label for="smtp-password" class="mb-1 block text-sm font-medium text-slate-800">{{ $t('Password') }}</label>
                            <input id="smtp-password" v-model="emailSettings.password" type="password" autocomplete="new-password" dir="ltr" :placeholder="$t(hasSavedPassword ? 'SMTP password saved placeholder' : 'SMTP password placeholder')" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-start text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20" />
                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ $t(hasSavedPassword ? 'SMTP password saved help' : 'SMTP password help') }}</p>
                        </div>
                        <div class="sm:max-w-xs">
                            <FormSelect v-model="emailSettings.encryption" :name="$t('Encryption')" :options="encryptionOptions" />
                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ $t('SMTP encryption help') }}</p>
                        </div>
                    </div>
                </section>

                <section class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="sender-information-title">
                    <div class="mb-5">
                        <h2 id="sender-information-title" class="font-semibold text-slate-900">{{ $t('Sender Information') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ $t('Sender information explanation') }}</p>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="sender-name" class="mb-1 block text-sm font-medium text-slate-800">{{ $t('From Name') }}</label>
                            <input id="sender-name" v-model="emailSettings.from_name" type="text" autocomplete="organization" :placeholder="$t('Sender name placeholder')" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-start text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20" />
                        </div>
                        <div>
                            <label for="sender-address" class="mb-1 block text-sm font-medium text-slate-800">{{ $t('From Address') }}</label>
                            <input id="sender-address" v-model="emailSettings.from_address" type="email" autocomplete="email" dir="ltr" placeholder="noreply@example.com" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-start text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20" />
                        </div>
                    </div>
                </section>

                <section v-if="emailSettings.host" class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="test-email-title">
                    <div class="mb-4">
                        <h2 id="test-email-title" class="font-semibold text-slate-900">{{ $t('Test Email') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ $t('Test email explanation') }}</p>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="min-w-0 flex-1">
                            <label for="test-recipient-email" class="mb-1 block text-sm font-medium text-slate-800">{{ $t('Test recipient email') }}</label>
                            <input id="test-recipient-email" v-model="testRecipientEmail" type="email" autocomplete="email" dir="ltr" placeholder="name@example.com" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-start text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20" />
                        </div>
                        <button type="button" :disabled="testSending || !testRecipientEmail" @click="sendTestEmail" class="inline-flex min-h-10 shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 disabled:cursor-not-allowed disabled:opacity-50">
                            {{ testSending ? $t('Sending...') : $t('Send Test Email') }}
                        </button>
                    </div>
                </section>
            </template>

            <div v-else class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-600">
                {{ $t('Email notifications disabled explanation') }}
            </div>

            <div v-if="testResult" class="mt-4 rounded-lg border p-3 text-sm leading-6" role="status" aria-live="polite" :class="testResult.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-800'">
                {{ testResult.message }}
            </div>

            <footer class="mt-5 flex flex-col-reverse gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                <button type="button" @click="resetChanges" :disabled="isLoading || testSending" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 disabled:opacity-50">{{ $t('Discard changes') }}</button>
                <button type="button" :disabled="isLoading || testSending" @click="save" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white shadow-sm hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50">
                    <svg v-if="isLoading" class="size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    {{ isLoading ? $t('Saving...') : $t('Save') }}
                </button>
            </footer>
            </div>
        </main>
    </SettingLayout>
</template>
