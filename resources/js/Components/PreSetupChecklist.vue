<template>
    <section class="mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="pre-setup-title">
        <div v-if="collapsed" class="bg-emerald-50/70 p-4 sm:p-5">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700" aria-hidden="true">
                        <svg viewBox="0 0 20 20" fill="currentColor" class="size-5"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.2 7.26a1 1 0 0 1-1.42.004l-3.8-3.8a1 1 0 1 1 1.414-1.415l3.09 3.09 6.494-6.547a1 1 0 0 1 1.416-.006Z" clip-rule="evenodd" /></svg>
                    </span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 id="pre-setup-title" class="font-semibold text-emerald-950">{{ $t('Account readiness') }}</h2>
                            <StatusBadge tone="success" :label="$t('Ready to get started')" />
                        </div>
                        <p class="mt-1 text-sm text-emerald-900">{{ $t('All required setup items are complete. Optional steps can be completed anytime.') }}</p>
                    </div>
                </div>
                <button type="button" class="inline-flex shrink-0 items-center justify-center rounded-lg border border-emerald-200 bg-white px-4 py-2.5 text-sm font-semibold text-emerald-900 transition hover:bg-emerald-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-wait disabled:opacity-60" :disabled="saving" @click="reopen">
                    {{ $t('Show checklist') }}
                </button>
            </div>
        </div>

        <template v-else>
            <div class="border-b border-slate-100 bg-gradient-to-br from-slate-50 to-white p-4 sm:p-6">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 id="pre-setup-title" class="text-lg font-bold tracking-tight text-slate-950 sm:text-xl">{{ $t('Get started with your account') }}</h2>
                            <span class="rounded-full bg-primary/10 px-2.5 py-1 text-xs font-bold text-primary">{{ summary.completed }}/{{ summary.total }} {{ $t('steps completed') }}</span>
                        </div>
                        <p class="mt-1.5 max-w-2xl text-sm leading-6 text-slate-600">{{ $t('Complete the essentials first. You can return to recommended steps later.') }}</p>
                        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-600">
                            <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-primary" aria-hidden="true"></span>{{ $t('Required setup') }}: {{ mandatoryCompleted }}/{{ mandatoryTotal }}</span>
                            <button type="button" class="font-medium text-primary underline decoration-primary/40 underline-offset-4 hover:decoration-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" :aria-expanded="whySetupOpen" @click="whySetupOpen = !whySetupOpen">{{ $t(whySetupOpen ? 'Hide setup explanation' : 'Why these steps?') }}</button>
                        </div>
                        <p v-if="whySetupOpen" class="mt-3 max-w-2xl rounded-lg border border-blue-100 bg-blue-50 p-3 text-sm leading-6 text-blue-950">{{ $t('These steps help prepare your workspace. The checklist is guidance and does not add a new campaign sending restriction.') }}</p>
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row lg:flex-col lg:items-stretch">
                        <Link v-if="firstRequiredStep" :href="firstRequiredStep.route" class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:brightness-95 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                            {{ $t('Complete first missing item') }}
                            <span aria-hidden="true" class="text-base">→</span>
                        </Link>
                        <button type="button" class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-wait disabled:opacity-60" :disabled="saving" @click="finishLater">
                            {{ saving ? $t('Saving') : $t('Finish later') }}
                        </button>
                    </div>
                </div>

                <div class="mt-5" role="progressbar" :aria-valuenow="summary.completed" :aria-valuemin="0" :aria-valuemax="summary.total" :aria-label="$t('Setup progress')">
                    <div class="mb-2 flex items-center justify-between gap-3 text-xs font-medium text-slate-600">
                        <span>{{ $t('Overall progress') }}</span><span>{{ progress }}%</span>
                    </div>
                    <div class="h-2.5 overflow-hidden rounded-full bg-slate-200">
                        <div class="h-full rounded-full bg-primary transition-[width] duration-500" :style="{ width: `${progress}%` }"></div>
                    </div>
                </div>
            </div>

            <div class="space-y-3 p-3 sm:space-y-3.5 sm:p-5">
                <section v-for="(group, index) in visibleGroups" :key="group.id" class="overflow-hidden rounded-xl border border-slate-200 transition-shadow hover:shadow-sm">
                    <h3>
                        <button :id="`setup-group-${group.id}`" type="button" class="flex w-full items-center gap-3 px-3 py-3.5 text-start transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-primary sm:px-4" :aria-expanded="expandedGroup === group.id" :aria-controls="`setup-panel-${group.id}`" @click="toggleGroup(group.id)">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg text-xs font-bold" :class="completedIn(group) === group.total ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'" aria-hidden="true">
                                <svg v-if="completedIn(group) === group.total" viewBox="0 0 20 20" fill="currentColor" class="size-4"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.2 7.26a1 1 0 0 1-1.42.004l-3.8-3.8a1 1 0 1 1 1.414-1.415l3.09 3.09 6.494-6.547a1 1 0 0 1 1.416-.006Z" clip-rule="evenodd" /></svg>
                                <span v-else>{{ index + 1 }}</span>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold text-slate-900">{{ $t(group.title) }}</span>
                                <span class="mt-0.5 block text-xs text-slate-500">{{ completedIn(group) }}/{{ group.total }} {{ $t(group.id === 'review' ? 'required checks complete' : 'steps completed') }}</span>
                            </span>
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full text-slate-500" aria-hidden="true">
                                <svg class="size-4 transition-transform" :class="expandedGroup === group.id ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.22 7.47a.75.75 0 0 1 1.06 0L10 11.19l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0l-4.25-4.25a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                            </span>
                        </button>
                    </h3>
                    <div v-if="expandedGroup === group.id" :id="`setup-panel-${group.id}`" role="region" :aria-labelledby="`setup-group-${group.id}`" class="border-t border-slate-100 bg-white">
                        <article v-for="(step, stepIndex) in group.steps" :key="step.id" class="px-3 py-4 sm:px-4" :class="stepIndex ? 'border-t border-slate-100' : ''">
                            <div v-if="step.id === 'readiness_review'" class="space-y-4">
                                <div role="status" aria-live="polite" class="flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between" :class="step.review.completed === step.review.total ? 'border-emerald-200 bg-emerald-50/70' : (step.review.blocked ? 'border-red-200 bg-red-50/70' : 'border-amber-200 bg-amber-50/70')">
                                    <div class="flex min-w-0 items-start gap-3">
                                        <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full" :class="step.review.completed === step.review.total ? 'bg-emerald-100 text-emerald-700' : (step.review.blocked ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800')" aria-hidden="true">
                                            <svg v-if="step.review.completed === step.review.total" viewBox="0 0 20 20" fill="currentColor" class="size-5"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.2 7.26a1 1 0 0 1-1.42.004l-3.8-3.8a1 1 0 1 1 1.414-1.415l3.09 3.09 6.494-6.547a1 1 0 0 1 1.416-.006Z" clip-rule="evenodd" /></svg>
                                            <svg v-else viewBox="0 0 20 20" fill="currentColor" class="size-5"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm0-12a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 6Zm0 7a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z" clip-rule="evenodd" /></svg>
                                        </span>
                                        <div class="min-w-0">
                                            <h4 class="font-semibold" :class="step.review.completed === step.review.total ? 'text-emerald-950' : (step.review.blocked ? 'text-red-950' : 'text-amber-950')">{{ $t('Account setup summary') }}</h4>
                                            <p v-if="step.review.completed === step.review.total" class="mt-1 text-sm leading-6 text-emerald-900">{{ $t('All required account checks are complete.') }}</p>
                                            <p v-else class="mt-1 text-sm leading-6" :class="step.review.blocked ? 'text-red-950' : 'text-amber-950'">{{ $t(':count required items remain incomplete.', { count: step.review.total - step.review.completed }) }}</p>
                                            <p v-if="step.review.unknown" class="mt-1 text-sm leading-6 text-slate-700">{{ $t(':count item status could not be confirmed. Open its settings to check.', { count: step.review.unknown }) }}</p>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
                                        <StatusBadge :tone="step.review.completed === step.review.total ? 'success' : (step.review.blocked ? 'error' : (step.review.needsAction ? 'warning' : 'neutral'))" :label="$t(step.review.completed === step.review.total ? 'Required setup complete' : (step.review.blocked ? 'WhatsApp disconnected' : (step.review.needsAction ? 'Action needed' : 'Check status')))" />
                                        <span class="rounded-lg bg-white/80 px-3 py-2 text-sm font-bold text-slate-700">{{ step.review.completed }}/{{ step.review.total }}</span>
                                    </div>
                                </div>

                                <ul class="divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200" :aria-label="$t('Required account checks')">
                                    <li v-for="item in step.review.items" :key="item.id" class="p-3 sm:px-4">
                                        <div v-if="item.id === 'whatsapp_connected' && item.state !== 'complete'" class="flex flex-col gap-3 rounded-xl border p-3 sm:flex-row sm:items-start sm:justify-between sm:gap-5 sm:px-4" :class="item.state === 'blocked' ? 'border-red-300 bg-red-50' : (item.state === 'needs_action' ? 'border-amber-300 bg-amber-50' : 'border-slate-300 bg-slate-50')">
                                            <div class="flex min-w-0 items-start gap-3">
                                                <svg class="mt-0.5 size-5 shrink-0 text-amber-500" viewBox="0 0 20 20" aria-hidden="true">
                                                    <path d="M10 2.5 1.5 17h17L10 2.5Z" fill="currentColor" stroke="#b45309" stroke-width="1" stroke-linejoin="round" />
                                                    <path d="M10 7v4.5" stroke="#451a03" stroke-width="1.5" stroke-linecap="round" />
                                                    <circle cx="10" cy="14" r=".8" fill="#451a03" />
                                                </svg>
                                                <div class="min-w-0">
                                                    <p class="font-semibold leading-6" :class="item.state === 'blocked' ? 'text-red-900' : (item.state === 'needs_action' ? 'text-amber-950' : 'text-slate-800')">{{ item.alertTitle }}</p>
                                                    <p dir="auto" class="mt-1 text-sm leading-6" :class="item.state === 'blocked' ? 'text-red-400' : (item.state === 'needs_action' ? 'text-amber-800' : 'text-slate-600')">{{ item.detail }}</p>
                                                </div>
                                            </div>
                                            <Link :href="item.route" :aria-label="`${item.actionLabel}: ${item.title}`" class="inline-flex min-h-9 w-full shrink-0 items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:w-auto" :class="item.state === 'blocked' ? 'bg-red-700 hover:bg-red-800' : (item.state === 'needs_action' ? 'bg-amber-700 hover:bg-amber-800' : 'bg-slate-700 hover:bg-slate-800')">
                                                {{ item.actionLabel }}
                                            </Link>
                                        </div>

                                        <div v-else class="flex flex-col gap-3 rounded-lg px-1 py-1 sm:flex-row sm:items-center sm:justify-between">
                                            <div class="flex min-w-0 items-start gap-3">
                                                <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full" :class="item.state === 'complete' ? 'bg-emerald-100 text-emerald-700' : (item.state === 'unknown' ? 'bg-slate-100 text-slate-600' : 'bg-amber-100 text-amber-800')" aria-hidden="true">
                                                    <svg v-if="item.completed" viewBox="0 0 20 20" fill="currentColor" class="size-4"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.2 7.26a1 1 0 0 1-1.42.004l-3.8-3.8a1 1 0 1 1 1.414-1.415l3.09 3.09 6.494-6.547a1 1 0 0 1 1.416-.006Z" clip-rule="evenodd" /></svg>
                                                    <svg v-else viewBox="0 0 20 20" fill="currentColor" class="size-4"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm0-12a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 6Zm0 7a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z" clip-rule="evenodd" /></svg>
                                                </span>
                                                <div class="min-w-0">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <span class="font-medium text-slate-900">{{ item.title }}</span>
                                                        <StatusBadge :tone="item.state === 'complete' ? 'success' : (item.state === 'unknown' ? 'neutral' : 'warning')" :label="$t(item.state === 'complete' ? 'Complete' : (item.state === 'unknown' ? 'Could not verify' : 'Needs action'))" />
                                                    </div>
                                                    <p dir="auto" class="mt-1 text-sm leading-6 text-slate-600">{{ item.detail }}</p>
                                                </div>
                                            </div>
                                            <Link :href="item.route" :aria-label="`${item.actionLabel}: ${item.title}`" class="inline-flex min-h-9 w-full shrink-0 items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:w-auto" :class="item.completed ? 'text-primary underline decoration-primary/40 underline-offset-4 hover:decoration-primary' : 'rounded-lg bg-primary text-white shadow-sm hover:brightness-95'">
                                                {{ item.actionLabel }}
                                            </Link>
                                        </div>
                                    </li>
                                </ul>
                                <div v-if="step.review.advisories.length" class="rounded-xl border border-slate-200 bg-slate-50/70 p-3 sm:p-4">
                                    <div class="mb-3">
                                        <h5 class="text-sm font-semibold text-slate-800">{{ $t('Additional WhatsApp information') }}</h5>
                                        <p class="mt-0.5 text-xs leading-5 text-slate-600">{{ $t('These details are informative and are not required setup checks.') }}</p>
                                    </div>
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        <div v-for="advisory in step.review.advisories" :key="advisory.label" class="flex min-w-0 items-center justify-between gap-3 rounded-lg bg-white px-3 py-2.5">
                                            <span class="text-sm text-slate-700">{{ advisory.label }}</span>
                                            <StatusBadge :tone="advisory.tone" :label="advisory.value" />
                                        </div>
                                    </div>
                                </div>
                                <p class="rounded-lg bg-slate-50 px-3 py-2.5 text-xs leading-5 text-slate-600">{{ $t('This readiness review is informational and does not add a new campaign sending restriction.') }}</p>
                            </div>

                            <div v-else class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-5">
                                <div class="flex min-w-0 flex-1 items-start gap-3">
                                    <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full" :class="step.completed ? 'bg-emerald-100 text-emerald-700' : 'border-2 border-slate-300 text-transparent'" :aria-label="$t(step.completed ? 'Completed' : 'Not completed')">
                                        <svg v-if="step.completed" viewBox="0 0 20 20" fill="currentColor" class="size-4" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.2 7.26a1 1 0 0 1-1.42.004l-3.8-3.8a1 1 0 1 1 1.414-1.415l3.09 3.09 6.494-6.547a1 1 0 0 1 1.416-.006Z" clip-rule="evenodd" /></svg>
                                        <span v-else class="sr-only">{{ $t('Not completed') }}</span>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-medium leading-6 text-slate-900">{{ step.title }}</span>
                                            <StatusBadge :tone="step.completed ? 'success' : (step.mandatory ? 'warning' : 'neutral')" :label="$t(step.completed ? 'Completed' : (step.mandatory ? 'Required' : (recommendedSteps.includes(step.id) ? 'Recommended' : 'Optional')))" />
                                        </div>
                                        <div v-if="step.description" class="mt-2 rounded-lg border border-blue-100 bg-blue-50 p-3 text-sm leading-6 text-blue-950">
                                            <p class="font-semibold">{{ $t('How to test sending') }}</p>
                                            <p class="mt-1">{{ step.description }}</p>
                                        </div>
                                        <button type="button" class="mt-1 text-xs font-medium text-primary underline decoration-primary/40 underline-offset-4 hover:decoration-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" :aria-expanded="whyOpen === step.id" :aria-controls="`setup-why-${step.id}`" @click="toggleWhy(step.id)">{{ $t(whyOpen === step.id ? 'Hide why' : 'Why?') }}</button>
                                        <p v-if="whyOpen === step.id" :id="`setup-why-${step.id}`" class="mt-2 rounded-lg bg-slate-50 p-3 text-sm leading-6 text-slate-600">{{ step.why }}</p>
                                    </div>
                                </div>
                                <Link :href="step.route" class="inline-flex min-h-10 w-full items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:w-auto" :class="step.completed ? 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50' : 'bg-primary text-white shadow-sm hover:brightness-95'">
                                    {{ step.completed ? $t('Review') : (step.id === 'team_members' ? $t('Add users') : (step.id === 'contacts_imported' ? $t('Upload phone numbers') : (step.id === 'first_template' ? $t('Create template') : (step.id === 'first_campaign' ? $t('Create campaign') : $t('Set up'))))) }}
                                </Link>
                            </div>
                        </article>
                    </div>
                </section>
            </div>
        </template>
    </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import StatusBadge from '@/Components/StatusBadge.vue';

const props = defineProps({
    steps: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({ total: 0, completed: 0, mandatoryPending: 0 }) },
    mandatoryReady: { type: Boolean, default: false },
    dismissed: { type: Boolean, default: false },
});

const groupDefinitions = [
    { id: 'connection', title: 'Connection' },
    { id: 'campaign', title: 'Campaigns' },
    { id: 'time', title: 'Time and schedule' },
    { id: 'team', title: 'Team' },
    { id: 'contacts', title: 'Contacts' },
    { id: 'review', title: 'Review' },
];
const recommendedSteps = ['working_hours', 'team_members', 'conversation_distribution', 'ticketing', 'email_notifications', 'labels_created', 'first_campaign'];
const OPEN_INTENT_KEY = 'dashboard-pre-setup-open-intent';
const whyOpen = ref(null);
const whySetupOpen = ref(false);
const firstIncomplete = props.steps.find((step) => !step.completed)?.group ?? null;
const savedOpenIntent = readOpenIntent();
const expandedGroup = ref(props.mandatoryReady || props.dismissed ? null : firstIncomplete);
const collapsed = ref((props.dismissed || props.mandatoryReady) && !savedOpenIntent);
const saving = ref(false);

const visibleGroups = computed(() => groupDefinitions
    .map((group) => {
        const steps = props.steps.filter((step) => step.group === group.id);
        const total = group.id === 'review' ? (steps[0]?.review?.total ?? steps.length) : steps.length;
        return { ...group, steps, total };
    })
    .filter((group) => group.steps.length > 0));
const progress = computed(() => props.summary.total ? Math.round((props.summary.completed / props.summary.total) * 100) : 0);
const mandatorySteps = computed(() => props.steps.filter((step) => step.mandatory && step.id !== 'readiness_review'));
const mandatoryTotal = computed(() => mandatorySteps.value.length);
const mandatoryCompleted = computed(() => mandatorySteps.value.filter((step) => step.completed).length);
const firstRequiredStep = computed(() => mandatorySteps.value.find((step) => !step.completed) ?? null);
const completionSignature = computed(() => props.steps.map((step) => `${step.id}:${step.completed ? 1 : 0}`).join('|'));

onMounted(() => {
    const intent = readOpenIntent();
    if (!intent) return;

    collapsed.value = false;
    expandedGroup.value = visibleGroups.value.find((group) => group.steps.some((step) => !step.completed))?.id ?? null;

    if (intent.signature !== completionSignature.value) {
        clearOpenIntent();
        if (props.dismissed && !props.mandatoryReady) saveDismissed(false);
    }
});

function completedIn(group) {
    if (group.id === 'review') return group.steps[0]?.review?.completed ?? 0;
    return group.steps.filter((step) => step.completed).length;
}

function toggleGroup(groupId) {
    expandedGroup.value = expandedGroup.value === groupId ? null : groupId;
}

function toggleWhy(stepId) {
    whyOpen.value = whyOpen.value === stepId ? null : stepId;
}

function saveDismissed(dismissed, onSuccess) {
    saving.value = true;
    router.post('/dashboard/pre-setup-state', { dismissed }, {
        preserveScroll: true,
        onSuccess,
        onError: () => { collapsed.value = !dismissed; },
        onFinish: () => { saving.value = false; },
    });
}

function finishLater() {
    collapsed.value = true;
    clearOpenIntent();
    saveDismissed(true);
}

function reopen() {
    collapsed.value = false;
    expandedGroup.value = visibleGroups.value.find((group) => group.steps.some((step) => !step.completed))?.id ?? null;
    storeOpenIntent();
}

function readOpenIntent() {
    if (typeof window === 'undefined') return null;
    try {
        const stored = window.sessionStorage.getItem(OPEN_INTENT_KEY);
        return stored ? JSON.parse(stored) : null;
    } catch {
        return null;
    }
}

function storeOpenIntent() {
    if (typeof window === 'undefined') return;
    try {
        window.sessionStorage.setItem(OPEN_INTENT_KEY, JSON.stringify({ signature: completionSignature.value }));
    } catch {
        // The checklist still opens locally when browser storage is unavailable.
    }
}

function clearOpenIntent() {
    if (typeof window === 'undefined') return;
    try {
        window.sessionStorage.removeItem(OPEN_INTENT_KEY);
    } catch {
        // Storage cleanup is best-effort; it must not prevent checklist actions.
    }
}
</script>
