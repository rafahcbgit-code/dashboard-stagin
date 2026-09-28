<script setup>
import AppLayout from './Layout/App.vue'
import { computed, reactive, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useAdminPermissions } from '@/Composables/useAdminPermissions'

const props = defineProps({
    definitions: Array,
    senders: Array,
})

const { can } = useAdminPermissions()
const mayEdit = computed(() => can('notifications', 'edit'))
const drafts = reactive({})
const expanded = reactive({})
const errors = reactive({})
const saving = reactive({})
const selectedTestType = ref(props.definitions?.[0]?.type_key || '')
const selectedTestDefinition = computed(() => props.definitions?.find((definition) => definition.type_key === selectedTestType.value))
const testSenderEmail = ref(selectedTestDefinition.value?.sender_email || props.senders?.[0] || '')
const testRecipientEmail = ref('')
const testResult = ref(null)
const testError = ref(null)
const testing = ref(false)
const testSamples = computed(() => Object.entries(selectedTestDefinition.value?.test_preview?.variables || {}))

watch(selectedTestType, () => {
    testSenderEmail.value = selectedTestDefinition.value?.sender_email || props.senders?.[0] || ''
    testResult.value = null
    testError.value = null
})

const draftFrom = (definition) => ({
    revision: definition.revision,
    enabled: definition.enabled,
    delivery_mode: definition.delivery_mode,
    dashboard_template: definition.dashboard_template,
    email_subject: definition.email_subject,
    email_body: definition.email_body,
    sender_email: definition.sender_email || props.senders?.[0] || '',
    webhook_url: definition.webhook_url || '',
    webhook_secret: '',
})
const toggleDefinition = (key) => { expanded[key] = !expanded[key] }
for (const definition of props.definitions || []) {
    drafts[definition.type_key] = draftFrom(definition)
}

const save = (key) => {
    errors[key] = null
    saving[key] = true
    router.put(`/admin/settings/managed-notifications/${encodeURIComponent(key)}`, drafts[key], {
        preserveScroll: true,
        onSuccess: () => {
            const saved = props.definitions?.find((definition) => definition.type_key === key)
            if (saved) drafts[key] = draftFrom(saved)
        },
        onError: (response) => { errors[key] = Object.values(response).join(' ') },
        onFinish: () => { saving[key] = false },
    })
}

const testEmail = () => {
    if (!selectedTestDefinition.value) return
    testError.value = null
    testResult.value = null
    testing.value = true
    router.post(`/admin/settings/managed-notifications/${encodeURIComponent(selectedTestType.value)}/test-email`, {
        sender_email: testSenderEmail.value,
        recipient_email: testRecipientEmail.value,
        revision: selectedTestDefinition.value.revision,
    }, {
        preserveScroll: true,
        onSuccess: (page) => { testResult.value = page.props.flash?.status || null },
        onError: (response) => { testError.value = Object.values(response).join(' ') },
        onFinish: () => { testing.value = false },
    })
}
</script>

<template>
    <AppLayout>
        <div class="max-w-5xl space-y-8">
            <div>
                <h1 class="text-2xl font-semibold">{{ $t('Managed notifications') }}</h1>
                <p class="mt-2 text-sm text-gray-600">{{ $t('Configure the available notification types. Meta alerts begin only when their event sources are connected.') }}</p>
                <div class="mt-3 flex flex-wrap gap-4 text-sm font-medium text-primary">
                    <Link href="/admin/settings/notification-senders" class="hover:underline">{{ $t('Notification email senders') }} →</Link>
                    <Link href="/admin/settings/notification-deliveries" class="hover:underline">{{ $t('Recent delivery status') }} →</Link>
                </div>
            </div>

            <div v-for="definition in definitions" :key="definition.type_key" class="rounded-lg border bg-white p-5">
                <div class="flex items-start justify-between gap-4">
                    <button type="button" class="min-w-0 flex-1 text-start focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" :aria-expanded="Boolean(expanded[definition.type_key])" :aria-controls="`notification-settings-${definition.type_key}`" @click="toggleDefinition(definition.type_key)">
                        <span class="block text-lg font-semibold">{{ $t(definition.name) }}</span>
                        <span v-if="definition.description" class="mt-2 block max-w-2xl text-sm leading-6 text-gray-700">{{ $t(definition.description) }}</span>
                        <span class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-primary">
                            <span aria-hidden="true">{{ expanded[definition.type_key] ? '▴' : '▾' }}</span>
                            {{ expanded[definition.type_key] ? $t('Hide settings') : $t('Show settings') }}
                        </span>
                    </button>
                    <span class="shrink-0 rounded-full px-2 py-1 text-xs font-medium" :class="definition.enabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'">{{ definition.enabled ? $t('Enabled') : $t('Disabled') }}</span>
                </div>

                <div v-show="expanded[definition.type_key]" :id="`notification-settings-${definition.type_key}`" class="mt-5 space-y-4 border-t pt-4">
                <p class="text-xs text-gray-500">{{ definition.type_key }}</p>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="drafts[definition.type_key].enabled" type="checkbox" :disabled="!mayEdit">
                    {{ $t('Enabled') }}
                </label>
                <p class="text-xs text-gray-600">{{ $t('Variables') }}: <code v-for="variable in definition.variables" :key="variable" class="mr-2">{{ '{' + '{' + variable + '}' + '}' }}</code></p>

                <div class="grid md:grid-cols-2 gap-4">
                    <label class="block text-sm">
                        {{ $t('Delivery mode') }}
                        <select v-model="drafts[definition.type_key].delivery_mode" :disabled="!mayEdit || definition.type_key === 'subscription.resource_warning'" class="mt-1 block w-full rounded border-gray-300">
                            <option value="dashboard">{{ $t('Dashboard only') }}</option>
                            <option value="email">{{ $t('Email only') }}</option>
                            <option value="dashboard_email">{{ $t('Dashboard and email') }}</option>
                        </select>
                        <span v-if="definition.type_key === 'subscription.resource_warning'" class="text-xs text-gray-500">70% and 80%: dashboard. 90% and 100%: dashboard and email.</span>
                    </label>
                    <label class="block text-sm">
                        {{ $t('Sender email') }}
                        <select v-model="drafts[definition.type_key].sender_email" :disabled="!mayEdit" class="mt-1 block w-full rounded border-gray-300">
                            <option v-for="sender in senders" :key="sender" :value="sender">{{ sender }}</option>
                        </select>
                    </label>
                </div>

                <label class="block text-sm">
                    {{ $t('Dashboard message') }}
                    <textarea v-model="drafts[definition.type_key].dashboard_template" :disabled="!mayEdit" rows="2" class="mt-1 block w-full rounded border-gray-300"></textarea>
                </label>
                <label class="block text-sm">
                    {{ $t('Email subject') }}
                    <input v-model="drafts[definition.type_key].email_subject" :disabled="!mayEdit" class="mt-1 block w-full rounded border-gray-300">
                </label>
                <label class="block text-sm">
                    {{ $t('Email body') }}
                    <textarea v-model="drafts[definition.type_key].email_body" :disabled="!mayEdit" rows="4" class="mt-1 block w-full rounded border-gray-300"></textarea>
                </label>
                <label class="block text-sm">
                    {{ $t('Optional webhook URL') }}
                    <input v-model="drafts[definition.type_key].webhook_url" :disabled="!mayEdit" type="url" placeholder="https://" class="mt-1 block w-full rounded border-gray-300">
                </label>
                <label v-if="drafts[definition.type_key].webhook_url" class="block text-sm">
                    {{ definition.has_webhook_secret ? $t('Replace webhook signing secret') : $t('Webhook signing secret') }}
                    <input v-model="drafts[definition.type_key].webhook_secret" :disabled="!mayEdit" type="password" autocomplete="new-password" class="mt-1 block w-full rounded border-gray-300">
                    <span class="text-xs text-gray-500">{{ $t('Use 32–128 characters. Leave blank to keep the existing secret.') }}</span>
                </label>

                <p v-if="errors[definition.type_key]" class="text-sm text-red-700" role="alert">{{ errors[definition.type_key] }}</p>
                <div v-if="mayEdit" class="flex flex-wrap gap-3 items-center">
                    <button type="button" :disabled="saving[definition.type_key]" @click="save(definition.type_key)" class="rounded bg-primary px-4 py-2 text-sm text-white disabled:opacity-50">{{ $t('Save') }}</button>
                </div>
                </div>
            </div>

            <section v-if="mayEdit" class="rounded-lg border bg-white p-5 space-y-4">
                <h2 class="text-lg font-semibold">{{ $t('Test email delivery') }}</h2>
                <p class="text-sm text-gray-600">{{ $t('Preview and send a saved template with example values. This does not create a notification event.') }}</p>
                <div class="grid md:grid-cols-3 gap-4">
                    <label class="block text-sm">
                        {{ $t('Notification type') }}
                        <select v-model="selectedTestType" class="mt-1 block w-full rounded border-gray-300">
                            <option v-for="definition in definitions" :key="definition.type_key" :value="definition.type_key">{{ $t(definition.name) }}</option>
                        </select>
                    </label>
                    <label class="block text-sm">
                        {{ $t('Sender email') }}
                        <select v-model="testSenderEmail" class="mt-1 block w-full rounded border-gray-300">
                            <option v-for="sender in senders" :key="sender" :value="sender">{{ sender }}</option>
                        </select>
                    </label>
                    <label class="block text-sm">
                        {{ $t('Recipient email') }}
                        <input v-model.trim="testRecipientEmail" type="email" autocomplete="email" class="mt-1 block w-full rounded border-gray-300">
                    </label>
                </div>
                <p v-if="selectedTestDefinition?.test_preview?.error" role="alert" class="text-sm text-red-700">{{ selectedTestDefinition.test_preview.error }}</p>
                <div v-else-if="selectedTestDefinition?.test_preview" class="rounded border bg-gray-50 p-4 space-y-3 text-sm">
                    <p class="font-medium">{{ $t('Saved template preview') }}</p>
                    <p><strong>{{ $t('Sample variables') }}:</strong> <span v-for="[key, value] in testSamples" :key="key" class="mr-3"><code>{{ key }}</code>: {{ value }}</span></p>
                    <p><strong>{{ $t('Subject') }}:</strong> {{ selectedTestDefinition.test_preview.subject }}</p>
                    <pre class="whitespace-pre-wrap font-sans">{{ selectedTestDefinition.test_preview.body }}</pre>
                </div>
                <p v-if="testError" role="alert" class="text-sm text-red-700">{{ testError }}</p>
                <p v-if="testResult" :role="testResult.type === 'error' ? 'alert' : 'status'" :class="testResult.type === 'error' ? 'text-red-700' : 'text-green-700'" class="text-sm">{{ testResult.message }}</p>
                <button type="button" :disabled="testing || !selectedTestDefinition || selectedTestDefinition.test_preview?.error || !testRecipientEmail || !testSenderEmail" @click="testEmail" class="rounded bg-primary px-4 py-2 text-sm text-white disabled:opacity-50">{{ $t('Send test email') }}</button>
            </section>

        </div>
    </AppLayout>
</template>
