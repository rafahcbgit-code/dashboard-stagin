<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit px-4 py-6 md:p-8 text-[#000] overflow-y-scroll">
            <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
                <div class="flex items-center gap-3">
                    <Link
                        :href="`/automation/flows/${flow.uuid}`"
                        class="w-8 h-8 flex items-center justify-center rounded-lg border border-slate-200 text-slate-400 hover:text-slate-700 hover:border-slate-300 transition-colors"
                    >
                        <ChevronLeftIcon class="w-4 h-4" />
                    </Link>
                    <div>
                        <div class="flex items-center gap-2">
                            <h4 class="text-[15px] font-semibold text-slate-800">{{ flow.name }}</h4>
                            <span class="text-slate-400 text-sm">/</span>
                            <span class="text-sm text-slate-500">{{ $t('Question Responses') }}</span>
                        </div>
                        <p class="text-xs text-slate-400 mt-0.5">
                            {{ responses.total }} {{ $t('submissions matching current filters') }}
                        </p>
                    </div>
                </div>

                <a
                    :href="exportUrl"
                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50 transition-colors flex items-center gap-1.5"
                    :class="{ 'opacity-40 pointer-events-none': !responses.data?.length }"
                    :title="$t('Export filtered CSV')"
                >
                    <DownloadIcon class="w-3.5 h-3.5" />
                    {{ $t('Export filtered CSV') }}
                </a>
            </div>

            <div class="grid grid-cols-2 xl:grid-cols-6 gap-4 mb-5">
                <div class="bg-white border border-slate-200 rounded-lg p-4">
                    <p class="text-xs text-slate-400 font-medium uppercase mb-1">{{ $t('Submissions') }}</p>
                    <p class="text-2xl font-bold text-slate-800">{{ stats.total_submissions }}</p>
                </div>
                <div class="bg-white border border-slate-200 rounded-lg p-4">
                    <p class="text-xs text-slate-400 font-medium uppercase mb-1">{{ $t('Answers') }}</p>
                    <p class="text-2xl font-bold text-slate-800">{{ stats.total_answers }}</p>
                </div>
                <div class="bg-white border border-slate-200 rounded-lg p-4">
                    <p class="text-xs text-slate-400 font-medium uppercase mb-1">{{ $t('Contacts') }}</p>
                    <p class="text-2xl font-bold text-slate-800">{{ stats.unique_contacts }}</p>
                </div>
                <div class="bg-white border border-slate-200 rounded-lg p-4">
                    <p class="text-xs text-slate-400 font-medium uppercase mb-1">{{ $t('Completion') }}</p>
                    <p class="text-2xl font-bold text-slate-800">{{ stats.completion_rate }}%</p>
                </div>
                <div class="bg-white border border-slate-200 rounded-lg p-4">
                    <p class="text-xs text-slate-400 font-medium uppercase mb-1">{{ $t('Invalid Attempts') }}</p>
                    <p class="text-2xl font-bold text-slate-800">{{ stats.invalid_attempts }}</p>
                </div>
                <div class="bg-white border border-slate-200 rounded-lg p-4">
                    <p class="text-xs text-slate-400 font-medium uppercase mb-1">{{ $t('Latest') }}</p>
                    <p class="text-sm font-semibold text-slate-800 truncate">{{ formatDate(stats.latest_answer_at) }}</p>
                </div>
            </div>

            <form @submit.prevent="applyFilters" class="bg-white border border-slate-200 rounded-lg p-4 mb-5">
                <div class="grid grid-cols-1 md:grid-cols-6 gap-3">
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 mb-1">{{ $t('Search contact') }}</label>
                        <input
                            v-model="filterForm.search"
                            type="search"
                            class="w-full rounded-md border border-slate-200 px-3 py-2 text-sm focus:border-primary focus:ring-primary"
                            :placeholder="$t('Name, phone, or email')"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">{{ $t('Question') }}</label>
                        <select
                            v-model="filterForm.variable"
                            class="w-full rounded-md border border-slate-200 px-3 py-2 text-sm focus:border-primary focus:ring-primary"
                        >
                            <option value="">{{ $t('All questions') }}</option>
                            <option v-for="question in questions" :key="question.variable_name" :value="question.variable_name">
                                {{ question.question_text || question.variable_name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">{{ $t('Status') }}</label>
                        <select
                            v-model="filterForm.status"
                            class="w-full rounded-md border border-slate-200 px-3 py-2 text-sm focus:border-primary focus:ring-primary"
                        >
                            <option value="">{{ $t('All statuses') }}</option>
                            <option value="answered">{{ $t('Answered') }}</option>
                            <option value="invalid">{{ $t('Invalid attempt') }}</option>
                            <option value="max_retries_exceeded">{{ $t('Max retries') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">{{ $t('From') }}</label>
                        <input v-model="filterForm.date_from" type="date" class="w-full rounded-md border border-slate-200 px-3 py-2 text-sm focus:border-primary focus:ring-primary" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">{{ $t('To') }}</label>
                        <input v-model="filterForm.date_to" type="date" class="w-full rounded-md border border-slate-200 px-3 py-2 text-sm focus:border-primary focus:ring-primary" />
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button type="button" @click="clearFilters" class="rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-500 hover:bg-slate-50">
                        {{ $t('Clear') }}
                    </button>
                    <button type="submit" class="rounded-md bg-primary px-3 py-2 text-sm text-white">
                        {{ $t('Apply filters') }}
                    </button>
                </div>
            </form>

            <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
                <div v-if="!responses.data?.length" class="flex flex-col items-center justify-center py-16 text-center">
                    <div class="w-14 h-14 rounded-2xl bg-slate-100 flex items-center justify-center mb-4">
                        <MessageSquareIcon class="w-6 h-6 text-slate-400" />
                    </div>
                    <p class="text-sm font-medium text-slate-600 mb-1">{{ $t('No question submissions yet') }}</p>
                    <p class="text-xs text-slate-400 max-w-md">
                        {{ $t('Add a Question node, connect it after Start, publish the flow, then test it from WhatsApp. Answers will appear here grouped by contact submission.') }}
                    </p>
                    <Link :href="`/automation/flows/${flow.uuid}`" class="mt-4 rounded-md bg-primary px-3 py-2 text-sm text-white">
                        {{ $t('Open flow builder') }}
                    </Link>
                </div>

                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50">
                            <th class="text-start text-xs font-semibold text-slate-500 px-4 py-3 uppercase">{{ $t('Contact') }}</th>
                            <th class="text-start text-xs font-semibold text-slate-500 px-4 py-3 uppercase">{{ $t('Status') }}</th>
                            <th class="text-start text-xs font-semibold text-slate-500 px-4 py-3 uppercase">{{ $t('Answers') }}</th>
                            <th class="text-start text-xs font-semibold text-slate-500 px-4 py-3 uppercase hidden lg:table-cell">{{ $t('Submitted') }}</th>
                            <th class="text-start text-xs font-semibold text-slate-500 px-4 py-3 uppercase">{{ $t('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="run in responses.data"
                            :key="run.id"
                            class="border-b border-slate-100 last:border-0 hover:bg-slate-50 transition-colors"
                        >
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-700">{{ run.contact?.name || $t('Unknown contact') }}</div>
                                <div class="text-xs text-slate-400">{{ run.contact?.phone || '-' }}</div>
                                <div class="text-xs text-slate-400">{{ run.contact?.email || '-' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="['inline-flex items-center rounded px-2 py-0.5 text-xs font-medium', statusClass(run.status)]">
                                    {{ statusLabel(run.status) }}
                                </span>
                                <div v-if="run.invalid_attempts" class="text-xs text-slate-400 mt-1">
                                    {{ run.invalid_attempts }} {{ $t('invalid attempts') }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="grid grid-cols-1 xl:grid-cols-2 gap-2 max-w-3xl">
                                    <div
                                        v-for="answer in run.answers.slice(0, 4)"
                                        :key="answer.id"
                                        class="rounded-md border border-slate-100 bg-slate-50 px-3 py-2"
                                    >
                                        <div class="text-xs text-slate-400 truncate">{{ answer.question_text || answer.variable_name }}</div>
                                        <div class="text-sm text-slate-800 truncate">{{ answer.answer_value || '-' }}</div>
                                    </div>
                                    <div v-if="run.answers.length > 4" class="text-xs text-slate-400 self-center">
                                        +{{ run.answers.length - 4 }} {{ $t('more') }}
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 hidden lg:table-cell text-slate-500 text-xs">{{ formatDate(run.submitted_at) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <a
                                        v-if="run.contact?.chat_url"
                                        :href="run.contact.chat_url"
                                        class="rounded-md border border-slate-200 p-2 text-slate-500 hover:bg-slate-50"
                                        :title="$t('Open chat')"
                                    >
                                        <MessageCircleIcon class="w-4 h-4" />
                                    </a>
                                    <button
                                        type="button"
                                        @click="selectedRun = run"
                                        class="rounded-md border border-slate-200 px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-50"
                                    >
                                        {{ $t('Details') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="responses.last_page > 1" class="px-4 py-3 border-t border-slate-200 flex items-center justify-between text-sm text-slate-500">
                    <span class="text-xs">
                        {{ $t('Showing') }} {{ responses.from }}-{{ responses.to }} {{ $t('of') }} {{ responses.total }}
                    </span>
                    <div class="flex gap-1">
                        <Link
                            v-for="link in responses.links"
                            :key="link.label"
                            :href="link.url ?? '#'"
                            :class="[
                                'px-3 py-1 rounded-md text-xs font-medium transition-colors',
                                link.active ? 'bg-primary text-white' : 'border border-slate-200 text-slate-500 hover:bg-slate-50',
                                !link.url ? 'opacity-40 pointer-events-none' : '',
                            ]"
                            v-html="link.label"
                        />
                    </div>
                </div>
            </div>
        </div>

        <div v-if="selectedRun" class="fixed inset-0 z-50">
            <button type="button" class="absolute inset-0 bg-slate-900/30" @click="selectedRun = null"></button>
            <aside class="absolute end-0 top-0 h-full w-full max-w-2xl overflow-y-auto bg-white shadow-xl">
                <div class="sticky top-0 bg-white border-b border-slate-200 px-5 py-4 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold text-slate-800">{{ selectedRun.contact?.name || $t('Unknown contact') }}</p>
                        <p class="text-xs text-slate-400">{{ formatDate(selectedRun.submitted_at) }}</p>
                    </div>
                    <button type="button" class="rounded-md border border-slate-200 p-2 text-slate-500" @click="selectedRun = null">
                        <XIcon class="w-4 h-4" />
                    </button>
                </div>

                <div class="p-5 space-y-6">
                    <section>
                        <p class="text-xs font-semibold text-slate-500 uppercase mb-3">{{ $t('Submitted Answers') }}</p>
                        <div class="space-y-3">
                            <div v-for="answer in selectedRun.answers" :key="answer.id" class="border border-slate-200 rounded-lg p-4">
                                <div class="flex items-center justify-between gap-3 mb-2">
                                    <p class="text-sm font-medium text-slate-800">{{ answer.question_text || answer.variable_name }}</p>
                                    <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ answer.input_type || 'text' }}</span>
                                </div>
                                <p class="text-sm text-slate-700 whitespace-pre-wrap">{{ answer.answer_value || '-' }}</p>
                                <p class="text-xs text-slate-400 mt-2">{{ answer.variable_name }} · {{ answer.node_id }}</p>
                            </div>
                        </div>
                    </section>

                    <section v-if="selectedRun.attempts?.length">
                        <p class="text-xs font-semibold text-slate-500 uppercase mb-3">{{ $t('Invalid Attempts') }}</p>
                        <div class="space-y-2">
                            <div v-for="attempt in selectedRun.attempts" :key="attempt.id" class="border border-red-100 bg-red-50 rounded-lg p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-sm text-red-800">{{ attempt.answer_value || '-' }}</p>
                                    <span class="text-xs text-red-600">{{ statusLabel(attempt.status) }}</span>
                                </div>
                                <p class="text-xs text-red-500 mt-1">{{ attempt.question_text || attempt.variable_name }}</p>
                            </div>
                        </div>
                    </section>

                    <section>
                        <p class="text-xs font-semibold text-slate-500 uppercase mb-3">{{ $t('Contact') }}</p>
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt class="text-xs text-slate-400">{{ $t('Phone') }}</dt>
                                <dd class="text-slate-800">{{ selectedRun.contact?.phone || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-400">{{ $t('Email') }}</dt>
                                <dd class="text-slate-800">{{ selectedRun.contact?.email || '-' }}</dd>
                            </div>
                        </dl>
                        <a
                            v-if="selectedRun.contact?.chat_url"
                            :href="selectedRun.contact.chat_url"
                            class="mt-4 inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm text-white"
                        >
                            <MessageCircleIcon class="w-4 h-4" />
                            {{ $t('Open chat') }}
                        </a>
                    </section>
                </div>
            </aside>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Pages/User/Layout/App.vue'
import { Link, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { ChevronLeftIcon, DownloadIcon, MessageCircleIcon, MessageSquareIcon, XIcon } from 'lucide-vue-next'

const props = defineProps({
    flow: { type: Object, required: true },
    responses: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    questions: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
})

const selectedRun = ref(null)
const filterForm = ref({
    search: props.filters.search ?? '',
    variable: props.filters.variable ?? '',
    status: props.filters.status ?? '',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
})

const exportUrl = computed(() => {
    const params = new URLSearchParams()
    Object.entries(filterForm.value).forEach(([key, value]) => {
        if (value) params.set(key, value)
    })

    const query = params.toString()
    return `/automation/flows/${props.flow.uuid}/responses/export${query ? `?${query}` : ''}`
})

const applyFilters = () => {
    router.get(`/automation/flows/${props.flow.uuid}/responses`, cleanFilters(), {
        preserveState: true,
        preserveScroll: true,
    })
}

const clearFilters = () => {
    filterForm.value = {
        search: '',
        variable: '',
        status: '',
        date_from: '',
        date_to: '',
    }
    router.get(`/automation/flows/${props.flow.uuid}/responses`, {}, {
        preserveState: true,
        preserveScroll: true,
    })
}

const cleanFilters = () => Object.fromEntries(Object.entries(filterForm.value).filter(([, value]) => value))

const formatDate = (value) => {
    if (!value) return '-'
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return value

    return new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date)
}

const statusLabel = (status) => {
    const map = {
        answered: 'Answered',
        answered_with_retries: 'Answered with retries',
        invalid: 'Invalid attempt',
        max_retries_exceeded: 'Max retries',
    }
    return map[status] ?? status
}

const statusClass = (status) => {
    const map = {
        answered: 'bg-green-50 text-green-700',
        answered_with_retries: 'bg-amber-50 text-amber-700',
        invalid: 'bg-red-50 text-red-700',
        max_retries_exceeded: 'bg-red-50 text-red-700',
    }
    return map[status] ?? 'bg-slate-100 text-slate-600'
}
</script>
