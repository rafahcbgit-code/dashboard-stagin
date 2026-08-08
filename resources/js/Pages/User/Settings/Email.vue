<script setup>
import SettingLayout from './Layout.vue'
import { ref, onMounted } from 'vue'
import { useForm } from '@inertiajs/vue3'
import axios from 'axios'
import FormInput from '@/Components/FormInput.vue'
import FormSelect from '@/Components/FormSelect.vue'

const props = defineProps({
    settings: Object,
    modules: Array,
})

const isLoading = ref(false)
const testSending = ref(false)
const testResult = ref(null)
const testRecipientEmail = ref('')

const emailSettings = ref({
    active: false,
    host: '',
    port: 587,
    username: '',
    password: '',
    encryption: 'tls',
    from_name: '',
    from_address: '',
})

onMounted(() => {
    if (props.settings?.metadata) {
        const metadata = typeof props.settings.metadata === 'string'
            ? JSON.parse(props.settings.metadata)
            : props.settings.metadata

        if (metadata.email_settings) {
            emailSettings.value = {
                active: metadata.email_settings.active || false,
                host: metadata.email_settings.host || '',
                port: metadata.email_settings.port || 587,
                username: metadata.email_settings.username || '',
                password: metadata.email_settings.password || '',
                encryption: metadata.email_settings.encryption || 'tls',
                from_name: metadata.email_settings.from_name || '',
                from_address: metadata.email_settings.from_address || '',
            }
        }
    }
})

const toggleActive = () => {
    emailSettings.value.active = !emailSettings.value.active
}

const encryptionOptions = [
    { value: 'tls', label: 'TLS' },
    { value: 'ssl', label: 'SSL' },
    { value: 'none', label: 'None' },
]

const save = async () => {
    isLoading.value = true
    testResult.value = null

    try {
        await axios.post('/settings/notifications/email', emailSettings.value)
        testResult.value = { type: 'success', message: 'Email settings saved successfully.' }
    } catch (error) {
        const message = error.response?.data?.message || 'Failed to save settings.'
        testResult.value = { type: 'error', message }
    } finally {
        isLoading.value = false
    }
}

const sendTestEmail = async () => {
    testSending.value = true
    testResult.value = null

    try {
        const response = await axios.post('/settings/notifications/email/test', {
            email: testRecipientEmail.value,
            ...emailSettings.value,
        })
        testResult.value = { type: response.data.success ? 'success' : 'error', message: response.data.message }
    } catch (error) {
        const message = error.response?.data?.message
            || error.response?.data?.errors?.email?.[0]
            || 'Test email failed.'
        testResult.value = { type: 'error', message }
    } finally {
        testSending.value = false
    }
}
</script>

<template>
    <SettingLayout :modules="props.modules">
        <div class="md:h-[90vh] overflow-y-auto pb-20">
            <div class="flex justify-center items-center">
                <div class="md:w-[60em]">

                    <h2 class="text-xl mb-1">{{ $t('Email Notifications') }}</h2>
                    <p class="mb-6 flex items-center text-sm leading-6">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z" />
                        </svg>
                        <span class="ml-1 mt-1">{{ $t('Configure SMTP settings to send email notifications to your team') }}</span>
                    </p>

                    <!-- Enable toggle -->
                    <div class="bg-white border border-slate-200 rounded-lg pt-2 text-sm mb-4 px-4">
                        <div class="w-full py-2 mb-4 mt-2">
                            <div class="flex w-full">
                                <div class="text-md">
                                    <h4 class="text-[16px]">{{ $t('Enable email notifications') }}</h4>
                                    <div class="mb-1 text-slate-500">
                                        {{ $t('Send email notifications when conversations are assigned to agents') }}
                                    </div>
                                </div>
                                <div class="ml-auto">
                                    <div class="w-12 h-6 flex items-center bg-gray-300 rounded-full p-1 cursor-pointer transition-colors duration-300"
                                        :class="{ 'bg-primary': emailSettings.active }" @click="toggleActive">
                                        <div class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out"
                                            :class="{ 'ltr:translate-x-6 rtl:translate-x-[-24px]': emailSettings.active }"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SMTP Configuration -->
                    <div v-if="emailSettings.active" class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4 px-4 pb-4">
                        <div class="w-full py-2 mb-2 mt-2">
                            <h4 class="text-[16px] mb-4">{{ $t('SMTP Configuration') }}</h4>

                            <div class="grid gap-4 grid-cols-2">
                                <FormInput
                                    v-model="emailSettings.host"
                                    :name="$t('SMTP Host')"
                                    type="text"
                                    :placeholder="'smtp.example.com'"
                                />
                                <FormInput
                                    v-model="emailSettings.port"
                                    :name="$t('Port')"
                                    type="number"
                                    :placeholder="'587'"
                                />
                            </div>

                            <div class="grid gap-4 grid-cols-2 mt-4">
                                <FormInput
                                    v-model="emailSettings.username"
                                    :name="$t('Username')"
                                    type="text"
                                    :placeholder="'user@example.com'"
                                />
                                <FormInput
                                    v-model="emailSettings.password"
                                    :name="$t('Password')"
                                    type="password"
                                />
                            </div>

                            <div class="grid gap-4 grid-cols-2 mt-4">
                                <FormSelect
                                    v-model="emailSettings.encryption"
                                    :name="$t('Encryption')"
                                    :options="encryptionOptions"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- From Settings -->
                    <div v-if="emailSettings.active" class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4 px-4 pb-4">
                        <div class="w-full py-2 mb-2 mt-2">
                            <h4 class="text-[16px] mb-4">{{ $t('Sender Information') }}</h4>

                            <div class="grid gap-4 grid-cols-2">
                                <FormInput
                                    v-model="emailSettings.from_name"
                                    :name="$t('From Name')"
                                    type="text"
                                    :placeholder="'Chatberry'"
                                />
                                <FormInput
                                    v-model="emailSettings.from_address"
                                    :name="$t('From Address')"
                                    type="email"
                                    :placeholder="'noreply@example.com'"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Alert -->
                    <div v-if="testResult" class="mb-4 p-3 rounded-lg text-sm"
                        :class="testResult.type === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'">
                        {{ testResult.message }}
                    </div>

                    <!-- Actions -->
                    <div v-if="emailSettings.active && emailSettings.host" class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4 px-4 pb-4">
                        <div class="w-full py-2 mb-2 mt-2">
                            <h4 class="text-[16px] mb-4">{{ $t('Test Email') }}</h4>

                            <div class="grid gap-4 grid-cols-2">
                                <FormInput
                                    v-model="testRecipientEmail"
                                    :name="$t('Test recipient email')"
                                    type="email"
                                    :placeholder="'name@example.com'"
                                />

                                <div class="flex items-end">
                                    <button
                                        type="button"
                                        :disabled="testSending || !testRecipientEmail"
                                        @click="sendTestEmail"
                                        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
                                        :class="{ 'opacity-50 cursor-not-allowed': testSending || !testRecipientEmail }"
                                    >
                                        <svg v-if="testSending" class="animate-spin -ml-1 mr-2 h-4 w-4 text-gray-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        <span v-if="!testSending">{{ $t('Send Test Email') }}</span>
                                        <span v-else>{{ $t('Sending...') }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-1 flex items-center justify-end gap-x-6 md:w-2/3 pt-2">
                        <div class="flex gap-x-3">
                            <button type="button" class="text-sm leading-6 text-gray-900">{{ $t('Cancel') }}</button>
                            <button
                                type="button"
                                :disabled="isLoading"
                                @click="save"
                                class="inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
                                :class="{ 'opacity-50': isLoading }"
                            >
                                <svg v-if="isLoading" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span v-if="!isLoading">{{ $t('Save') }}</span>
                                <span v-else>{{ $t('Saving...') }}</span>
                            </button>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </SettingLayout>
</template>
