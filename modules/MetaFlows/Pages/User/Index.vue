<template>
    <AppLayout>
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center">
                <div class="md:w-[60em]">
                    <div class="bg-white border border-slate-200 rounded-lg py-2 text-sm pb-4 px-4 mb-20">
                        <div class="w-full py-2 mb-2 mt-2">
                            <div class="flex w-full mb-4">
                                <div class="text-md">
                                    <h4 class="text-[16px]">{{ $t('Meta Flows') }}</h4>
                                    <span class="flex items-center mt-1 text-slate-500">
                                        {{ $t('Create and manage WhatsApp Flows powered by Meta') }}
                                    </span>
                                </div>
                                <div class="ml-auto flex items-center gap-2">
                                    <!-- Pull all existing flows from Meta into this account -->
                                    <button
                                        @click="handlePull"
                                        :disabled="pulling"
                                        class="rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50"
                                    >
                                        {{ pulling ? $t('Pulling...') : $t('Pull from Meta') }}
                                    </button>

                                    <button
                                        @click="isOpenFormModal = true"
                                        class="rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                    >
                                        {{ $t('New Flow') }}
                                    </button>
                                </div>
                            </div>

                            <!-- Inline feedback for pull result -->
                            <p v-if="pullMessage" :class="['text-xs mb-3', pullIsError ? 'text-red-600' : 'text-green-600']">
                                {{ pullMessage }}
                            </p>

                            <div class="w-5/5">
                                <MetaFlowsTable :rows="props.rows" :filters="props.filters" />
                                <div class="px-4 pb-4">
                                    <Pagination class="mt-3" :pagination="props.rows.meta" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create flow modal -->
        <Modal :label="$t('Create Meta Flow')" :isOpen="isOpenFormModal">
            <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
                <form @submit.prevent="submitForm()" class="grid gap-x-6 gap-y-4 sm:grid-cols-6">
                    <FormInput
                        v-model="form.name"
                        :error="form.errors.name"
                        :name="$t('Flow Name')"
                        :type="'text'"
                        :class="'sm:col-span-6'"
                    />

                    <!-- Category is required by Meta -->
                    <FormSelect
                        v-model="form.categories"
                        :error="form.errors.categories"
                        :name="$t('Category')"
                        :options="categoryOptions"
                        :multiple="false"
                        :placeholder="$t('Select a category')"
                        :class="'sm:col-span-6'"
                    />

                    <div class="mt-4 flex">
                        <button
                            type="button"
                            @click="isOpenFormModal = false"
                            class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none mr-4"
                        >
                            {{ $t('Cancel') }}
                        </button>
                        <button
                            :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none', { 'opacity-50': form.processing }]"
                            :disabled="form.processing"
                        >
                            <svg v-if="form.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            <span v-else>{{ $t('Create') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AppLayout>
</template>

<script setup>
    import AppLayout from '@/Pages/User/Layout/App.vue';
    import { ref } from 'vue';
    import { useForm, router } from '@inertiajs/vue3';
    import axios from 'axios';
    import MetaFlowsTable from '@modules/MetaFlows/Pages/User/Components/MetaFlowsTable.vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import Modal from '@/Components/Modal.vue';
    import Pagination from '@/Components/Pagination.vue';

    const props = defineProps(['rows', 'filters']);

    const isOpenFormModal = ref(false);

    const pulling    = ref(false);
    const pullMessage = ref(null);
    const pullIsError = ref(false);

    // Meta's allowed flow categories
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

    const form = useForm({
        name:       null,
        categories: 'OTHER',
    });

    const submitForm = () => {
        // Meta expects categories as an array
        form.transform((data) => ({
            ...data,
            categories: data.categories ? [data.categories] : ['OTHER'],
        })).post('/automation/meta-flows', {
            preserveScroll: true,
            onSuccess: () => {
                isOpenFormModal.value = false;
                form.reset();
            },
        });
    };

    // Import all flows from this WABA on Meta that don't yet exist locally
    const handlePull = async () => {
        pulling.value    = true;
        pullMessage.value = null;

        try {
            const res = await axios.post('/automation/meta-flows/pull');
            pullMessage.value = res.data.message;
            pullIsError.value  = false;

            // Reload the table so newly imported flows appear
            router.reload({ only: ['rows'] });
        } catch (err) {
            pullMessage.value = err.response?.data?.message ?? 'Failed to pull flows from Meta.';
            pullIsError.value  = true;
        } finally {
            pulling.value = false;
        }
    };
</script>
