<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] overflow-y-scroll">

            <div class="flex justify-between mt-8 md:mt-0">
                <div>
                    <h2 class="text-xl mb-1">{{ $t('Meta Flows') }}</h2>
                    <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                        {{ $t('Create and manage WhatsApp Flows powered by Meta') }}
                    </p>
                </div>
                <div class="space-s-2 flex items-center">
                    <button
                        @click="handlePull"
                        :disabled="pulling"
                        class="rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50 transition-colors"
                    >
                        {{ pulling ? $t('Pulling...') : $t('Pull from Meta') }}
                    </button>
                    <button
                        @click="isOpenFormModal = true"
                        class="rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:opacity-90 transition-opacity ms-2"
                    >
                        {{ $t('New Flow') }}
                    </button>
                </div>
            </div>

            <p v-if="pullMessage" :class="['text-xs mb-3', pullIsError ? 'text-red-600' : 'text-green-600']">
                {{ pullMessage }}
            </p>

            <MetaFlowsTable :rows="props.rows" :filters="props.filters" />
            <Pagination class="mt-3" :pagination="props.rows.meta" />

        </div>

        <!-- ─── New Flow Dialog (wide, 2-panel) ─── -->
        <TransitionRoot appear :show="isOpenFormModal" as="template">
            <Dialog as="div" class="relative z-50" @close="closeCreateModal">
                <!-- Backdrop -->
                <TransitionChild
                    as="template"
                    enter="duration-200 ease-out" enter-from="opacity-0" enter-to="opacity-100"
                    leave="duration-150 ease-in"  leave-from="opacity-100" leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-black/30 backdrop-blur-sm" />
                </TransitionChild>

                <div class="fixed inset-0 overflow-y-auto">
                    <div class="flex min-h-full items-center justify-center p-4">
                        <TransitionChild
                            as="template"
                            enter="duration-250 ease-out" enter-from="opacity-0 scale-95" enter-to="opacity-100 scale-100"
                            leave="duration-150 ease-in"  leave-from="opacity-100 scale-100" leave-to="opacity-0 scale-95"
                        >
                            <DialogPanel class="w-full max-w-4xl transform rounded-2xl bg-white shadow-2xl overflow-hidden">

                                <!-- Dialog header bar -->
                                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                                    <DialogTitle class="text-base font-semibold text-gray-900">
                                        {{ $t('New flow') }}
                                    </DialogTitle>
                                    <button
                                        @click="closeCreateModal"
                                        class="text-slate-400 hover:text-slate-600 rounded-full p-1 hover:bg-slate-100 transition-colors"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                    </button>
                                </div>

                                <!-- Two-column body -->
                                <div class="flex" style="height: 560px;">

                                    <!-- ── Left panel: form + template list ── -->
                                    <div class="w-[340px] shrink-0 border-e border-slate-100 flex flex-col">

                                        <!-- Name & Category -->
                                        <div class="px-5 pt-5 pb-4 space-y-4 border-b border-slate-100">
                                            <!-- Name -->
                                            <div>
                                                <label class="block text-xs font-medium text-slate-700 mb-1">
                                                    {{ $t('Name') }}
                                                    <span class="text-slate-400 font-normal float-end">{{ form.name?.length ?? 0 }}/200</span>
                                                </label>
                                                <input
                                                    v-model="form.name"
                                                    type="text"
                                                    maxlength="200"
                                                    :placeholder="$t('Enter flow name')"
                                                    class="block w-full rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-primary transition-shadow"
                                                    :class="form.errors.name ? 'border-red-400' : ''"
                                                />
                                                <p v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</p>
                                            </div>

                                            <!-- Category -->
                                            <div>
                                                <label class="block text-xs font-medium text-slate-700 mb-1">{{ $t('Categories') }}</label>
                                                <select
                                                    v-model="form.categories"
                                                    class="block w-full rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-800 bg-white focus:outline-none focus:ring-1 focus:ring-primary transition-shadow"
                                                >
                                                    <option v-for="opt in categoryOptions" :key="opt.value" :value="opt.value">
                                                        {{ opt.label }}
                                                    </option>
                                                </select>
                                            </div>
                                        </div>

                                        <!-- Template list (scrollable) -->
                                        <div class="flex-1 overflow-y-auto px-5 py-4">
                                            <p class="text-xs font-semibold text-slate-700 mb-3">{{ $t('Templates') }}</p>

                                            <div class="space-y-0.5">
                                                <label
                                                    v-for="t in META_FLOW_TEMPLATES"
                                                    :key="t.value"
                                                    class="flex items-center gap-2.5 px-3 py-2 rounded-lg cursor-pointer transition-colors"
                                                    :class="selectedTemplate === t.value
                                                        ? 'bg-primary/10 text-primary'
                                                        : 'hover:bg-slate-50 text-slate-700'"
                                                >
                                                    <input
                                                        type="radio"
                                                        :value="t.value"
                                                        v-model="selectedTemplate"
                                                        class="accent-primary shrink-0"
                                                        @change="onTemplateChange(t)"
                                                    />
                                                    <span class="text-sm">{{ $t(t.label) }}</span>
                                                </label>
                                            </div>
                                        </div>

                                        <!-- Footer actions -->
                                        <div class="px-5 py-4 border-t border-slate-100 flex items-center justify-between">
                                            <button
                                                type="button"
                                                @click="closeCreateModal"
                                                class="rounded-md border border-slate-200 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 transition-colors"
                                            >
                                                {{ $t('Cancel') }}
                                            </button>
                                            <button
                                                type="button"
                                                @click="submitForm"
                                                :disabled="!form.name?.trim() || form.processing"
                                                class="rounded-md bg-primary px-5 py-2 text-sm text-white shadow-sm hover:opacity-90 disabled:opacity-40 disabled:cursor-not-allowed transition-opacity flex items-center gap-2"
                                            >
                                                <svg v-if="form.processing" class="animate-spin" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".4"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                                                <span>{{ form.processing ? $t('Creating...') : $t('Create') }}</span>
                                            </button>
                                        </div>

                                    </div>

                                    <!-- ── Right panel: live preview ── -->
                                    <div class="flex-1 bg-slate-50 flex flex-col items-center justify-center p-6 gap-4">
                                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-widest">{{ $t('Preview') }}</p>
                                        <FlowPhonePreview :screens="currentPreviewScreens" />
                                        <p class="text-[11px] text-slate-400 text-center max-w-[220px] leading-relaxed">
                                            {{ $t('When utilising template Flow JSON, you are responsible for customising the experience to suit your use case.') }}
                                        </p>
                                    </div>

                                </div>
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </TransitionRoot>

    </AppLayout>
</template>

<script setup>
    import AppLayout from '@/Pages/User/Layout/App.vue';
    import { ref, computed } from 'vue';
    import { useForm, router } from '@inertiajs/vue3';
    import axios from 'axios';
    import {
        TransitionRoot, TransitionChild,
        Dialog, DialogPanel, DialogTitle,
    } from '@headlessui/vue';
    import MetaFlowsTable    from '@modules/MetaFlows/Pages/User/Components/MetaFlowsTable.vue';
    import FlowPhonePreview  from '@modules/MetaFlows/Pages/User/Components/FlowPhonePreview.vue';
    import Pagination        from '@/Components/Pagination.vue';
    import { META_FLOW_TEMPLATES, getPreviewScreens } from '@/Data/metaFlowTemplates.js';

    const props = defineProps(['rows', 'filters']);

    const isOpenFormModal = ref(false);
    const pulling         = ref(false);
    const pullMessage     = ref(null);
    const pullIsError     = ref(false);

    const categoryOptions = [
        { value: 'SIGN_UP',             label: 'Sign Up' },
        { value: 'SIGN_IN',             label: 'Sign In' },
        { value: 'APPOINTMENT_BOOKING', label: 'Appointment Booking' },
        { value: 'LEAD_GENERATION',     label: 'Lead Generation' },
        { value: 'CONTACT_US',          label: 'Contact Us' },
        { value: 'CUSTOMER_SUPPORT',    label: 'Customer Support' },
        { value: 'SURVEY',              label: 'Survey' },
        { value: 'OTHER',               label: 'Other' },
    ];

    // ── Template definitions — imported from @/Data/metaFlowTemplates.js ────

    const selectedTemplate = ref('default');

    const currentPreviewScreens = computed(() => {
        const tpl = META_FLOW_TEMPLATES.find(t => t.value === selectedTemplate.value);
        return tpl ? getPreviewScreens(tpl) : [];
    });

    const onTemplateChange = (template) => {
        form.categories = template.suggestedCategory;
    };

    const closeCreateModal = () => {
        isOpenFormModal.value = false;
        selectedTemplate.value = 'default';
        form.reset();
    };

    // ── Form ──────────────────────────────────────────────────────────────────
    const form = useForm({
        name:       '',
        categories: 'OTHER',
    });

    const submitForm = () => {
        const tpl = META_FLOW_TEMPLATES.find(t => t.value === selectedTemplate.value);
        form.transform(data => ({
            ...data,
            categories:    data.categories ? [data.categories] : ['OTHER'],
            template_json: tpl?.flowJson ? JSON.stringify(tpl.flowJson) : null,
        })).post('/automation/meta-flows', {
            preserveScroll: true,
            onSuccess: () => {
                closeCreateModal();
            },
        });
    };

    // ── Pull from Meta ────────────────────────────────────────────────────────
    const handlePull = async () => {
        pulling.value    = true;
        pullMessage.value = null;

        try {
            const res = await axios.post('/automation/meta-flows/pull');
            pullMessage.value = res.data.message;
            pullIsError.value  = false;
            router.reload({ only: ['rows'] });
        } catch (err) {
            pullMessage.value = err.response?.data?.message ?? 'Failed to pull flows from Meta.';
            pullIsError.value  = true;
        } finally {
            pulling.value = false;
        }
    };
</script>
