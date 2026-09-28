<script setup>
import AppLayout from './Layout/App.vue'
import { computed, reactive, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useAdminPermissions } from '@/Composables/useAdminPermissions'

const props = defineProps({ senderAccounts: Array })
const { can } = useAdminPermissions()
const mayEdit = computed(() => can('notifications', 'edit'))
const newSender = reactive({ email: '', host: '', port: 587, encryption: 'tls', username: '', password: '' })
const senderDrafts = reactive({})
const errors = reactive({})
const saving = reactive({})

watch(() => props.senderAccounts, (accounts) => {
    for (const account of accounts || []) {
        senderDrafts[account.id] = {
            host: account.host,
            port: account.port,
            encryption: account.encryption,
            username: account.username,
            password: '',
        }
    }
}, { immediate: true })

const addSender = () => {
    errors.new = null
    saving.new = true
    router.post('/admin/settings/notification-senders', newSender, {
        preserveScroll: true,
        onSuccess: () => Object.assign(newSender, { email: '', host: '', port: 587, encryption: 'tls', username: '', password: '' }),
        onError: (response) => { errors.new = Object.values(response).join(' ') },
        onFinish: () => { saving.new = false },
    })
}

const updateSender = (id) => {
    errors[id] = null
    saving[id] = true
    router.put(`/admin/settings/notification-senders/${id}`, senderDrafts[id], {
        preserveScroll: true,
        onSuccess: () => { senderDrafts[id].password = '' },
        onError: (response) => { errors[id] = Object.values(response).join(' ') },
        onFinish: () => { saving[id] = false },
    })
}
</script>

<template>
    <AppLayout>
        <div class="max-w-5xl space-y-6">
            <div>
                <h1 class="text-2xl font-semibold">{{ $t('Notification email senders') }}</h1>
                <p class="mt-2 text-sm text-gray-600">{{ $t('Add an SMTP account here, then choose its address in any notification. Existing mail settings remain available.') }}</p>
                <Link href="/admin/settings/managed-notifications" class="mt-3 inline-block text-sm font-medium text-primary hover:underline">← {{ $t('Managed notifications') }}</Link>
            </div>

            <section v-for="account in senderAccounts" :key="account.id" class="rounded-lg border bg-white p-5 space-y-4">
                <h2 class="text-lg font-semibold">{{ account.email }}</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="text-sm">{{ $t('Host') }}<input v-model.trim="senderDrafts[account.id].host" :disabled="!mayEdit" class="mt-1 block w-full rounded border-gray-300" required></label>
                    <label class="text-sm">{{ $t('Port') }}<input v-model.number="senderDrafts[account.id].port" :disabled="!mayEdit" type="number" min="1" max="65535" class="mt-1 block w-full rounded border-gray-300" required></label>
                    <label class="text-sm">{{ $t('Encryption') }}<select v-model="senderDrafts[account.id].encryption" :disabled="!mayEdit" class="mt-1 block w-full rounded border-gray-300"><option value="tls">TLS</option><option value="ssl">SSL</option></select></label>
                    <label class="text-sm">{{ $t('Username') }}<input v-model="senderDrafts[account.id].username" :disabled="!mayEdit" class="mt-1 block w-full rounded border-gray-300" required></label>
                    <label v-if="mayEdit" class="text-sm md:col-span-2">{{ $t('New password (leave blank to keep current)') }}<input v-model="senderDrafts[account.id].password" type="password" autocomplete="new-password" class="mt-1 block w-full rounded border-gray-300"></label>
                </div>
                <p v-if="errors[account.id]" role="alert" class="text-sm text-red-700">{{ errors[account.id] }}</p>
                <button v-if="mayEdit" type="button" :disabled="saving[account.id]" @click="updateSender(account.id)" class="rounded bg-primary px-4 py-2 text-sm text-white disabled:opacity-50">{{ $t('Save') }}</button>
            </section>

            <section v-if="mayEdit" class="rounded-lg border bg-white p-5 space-y-4">
                <h2 class="text-lg font-semibold">{{ $t('Add sender') }}</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="text-sm">{{ $t('Sender email') }}<input v-model.trim="newSender.email" type="email" class="mt-1 block w-full rounded border-gray-300" required></label>
                    <label class="text-sm">{{ $t('Host') }}<input v-model.trim="newSender.host" class="mt-1 block w-full rounded border-gray-300" required></label>
                    <label class="text-sm">{{ $t('Port') }}<input v-model.number="newSender.port" type="number" min="1" max="65535" class="mt-1 block w-full rounded border-gray-300" required></label>
                    <label class="text-sm">{{ $t('Encryption') }}<select v-model="newSender.encryption" class="mt-1 block w-full rounded border-gray-300"><option value="tls">TLS</option><option value="ssl">SSL</option></select></label>
                    <label class="text-sm">{{ $t('Username') }}<input v-model="newSender.username" class="mt-1 block w-full rounded border-gray-300" required></label>
                    <label class="text-sm">{{ $t('Password') }}<input v-model="newSender.password" type="password" autocomplete="new-password" class="mt-1 block w-full rounded border-gray-300" required></label>
                </div>
                <p v-if="errors.new" role="alert" class="text-sm text-red-700">{{ errors.new }}</p>
                <button type="button" :disabled="saving.new" @click="addSender" class="rounded bg-primary px-4 py-2 text-sm text-white disabled:opacity-50">{{ $t('Add sender') }}</button>
            </section>
        </div>
    </AppLayout>
</template>
