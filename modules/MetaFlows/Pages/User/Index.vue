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

                                            <!-- Without endpoint group -->
                                            <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide mb-2">
                                                {{ $t('Without endpoint') }}
                                            </p>
                                            <div class="space-y-0.5 mb-4">
                                                <label
                                                    v-for="t in noEndpointTemplates"
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

                                            <!-- With endpoint group -->
                                            <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide mb-2 flex items-center gap-1">
                                                {{ $t('With endpoint') }}
                                                <span title="Requires an endpoint URL to fetch dynamic data" class="text-slate-400 cursor-help">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                                                </span>
                                            </p>
                                            <div class="space-y-0.5">
                                                <label
                                                    v-for="t in withEndpointTemplates"
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

    // ── Template definitions ─────────────────────────────────────────────────
    const TEMPLATES = [
        // ── Without endpoint ──────────────────────────────────────────────────
        {
            value: 'default', label: 'Default', group: 'no_endpoint',
            suggestedCategory: 'OTHER',
            screens: [
                {
                    id: 'WELCOME', title: 'Welcome', terminal: true,
                    components: [
                        { type: 'TextHeading', text: 'Hello World' },
                        { type: 'TextBody',    text: "Let's start building things!" },
                        { type: 'Footer', label: 'Complete', action: 'complete' },
                    ],
                },
            ],
        },
        {
            value: 'register_event', label: 'Register for an event', group: 'no_endpoint',
            suggestedCategory: 'OTHER',
            screens: [
                {
                    id: 'CONTACT', title: 'Your Details', terminal: false,
                    components: [
                        { type: 'TextHeading', text: 'Event Registration' },
                        { type: 'TextInput', label: 'Full Name',      name: 'full_name', required: true, inputType: 'text' },
                        { type: 'TextInput', label: 'Email Address',  name: 'email',     required: true, inputType: 'email' },
                        { type: 'Footer', label: 'Next', action: 'navigate', nextScreen: 'EVENT' },
                    ],
                },
                {
                    id: 'EVENT', title: 'Event Choice', terminal: true,
                    components: [
                        { type: 'TextHeading', text: 'Choose Your Session' },
                        { type: 'Dropdown', label: 'Select Event', name: 'event_choice', required: true, options: [
                            { id: 'morning',   title: 'Morning Session (9am)' },
                            { id: 'afternoon', title: 'Afternoon Session (2pm)' },
                            { id: 'evening',   title: 'Evening Session (6pm)' },
                        ]},
                        { type: 'TextArea', label: 'Dietary Requirements', name: 'dietary', required: false },
                        { type: 'Footer', label: 'Register', action: 'complete' },
                    ],
                },
            ],
        },
        {
            value: 'get_feedback', label: 'Get feedback', group: 'no_endpoint',
            suggestedCategory: 'SURVEY',
            screens: [
                {
                    id: 'RATING', title: 'Rate Us', terminal: false,
                    components: [
                        { type: 'TextHeading', text: 'How was your experience?' },
                        { type: 'RadioButtonsGroup', label: 'Rating', name: 'rating', required: true, options: [
                            { id: '5', title: '⭐⭐⭐⭐⭐  Excellent' },
                            { id: '4', title: '⭐⭐⭐⭐  Good' },
                            { id: '3', title: '⭐⭐⭐  Average' },
                            { id: '2', title: '⭐⭐  Poor' },
                            { id: '1', title: '⭐  Very Poor' },
                        ]},
                        { type: 'Footer', label: 'Next', action: 'navigate', nextScreen: 'COMMENTS' },
                    ],
                },
                {
                    id: 'COMMENTS', title: 'Comments', terminal: true,
                    components: [
                        { type: 'TextHeading', text: 'Additional Comments' },
                        { type: 'TextArea', label: 'Your comments', name: 'comments', required: false },
                        { type: 'Footer', label: 'Submit', action: 'complete' },
                    ],
                },
            ],
        },
        {
            value: 'complete_signup', label: 'Complete sign up', group: 'no_endpoint',
            suggestedCategory: 'SIGN_UP',
            screens: [
                {
                    id: 'PERSONAL', title: 'Personal Info', terminal: false,
                    components: [
                        { type: 'TextHeading', text: 'Create Your Account' },
                        { type: 'TextInput', label: 'First Name', name: 'first_name', required: true,  inputType: 'text' },
                        { type: 'TextInput', label: 'Last Name',  name: 'last_name',  required: true,  inputType: 'text' },
                        { type: 'TextInput', label: 'Email',      name: 'email',      required: true,  inputType: 'email' },
                        { type: 'Footer', label: 'Next', action: 'navigate', nextScreen: 'PREFERENCES' },
                    ],
                },
                {
                    id: 'PREFERENCES', title: 'Preferences', terminal: true,
                    components: [
                        { type: 'TextHeading', text: 'Your Preferences' },
                        { type: 'CheckboxGroup', label: 'Interested in', name: 'interests', required: false, options: [
                            { id: 'news',   title: 'News & Updates' },
                            { id: 'offers', title: 'Special Offers' },
                            { id: 'events', title: 'Events' },
                        ]},
                        { type: 'Footer', label: 'Sign Up', action: 'complete' },
                    ],
                },
            ],
        },
        {
            value: 'complete_quiz', label: 'Complete our quiz', group: 'no_endpoint',
            suggestedCategory: 'SURVEY',
            screens: [
                {
                    id: 'Q1', title: 'Question 1', terminal: false,
                    components: [
                        { type: 'TextHeading',    text: 'Quick Quiz' },
                        { type: 'TextSubheading', text: 'Question 1 of 2' },
                        { type: 'RadioButtonsGroup', label: 'Which best describes you?', name: 'q1', required: true, options: [
                            { id: 'student',      title: 'Student' },
                            { id: 'professional', title: 'Working Professional' },
                            { id: 'business',     title: 'Business Owner' },
                            { id: 'other',        title: 'Other' },
                        ]},
                        { type: 'Footer', label: 'Next', action: 'navigate', nextScreen: 'Q2' },
                    ],
                },
                {
                    id: 'Q2', title: 'Question 2', terminal: true,
                    components: [
                        { type: 'TextSubheading', text: 'Question 2 of 2' },
                        { type: 'Dropdown', label: 'How did you hear about us?', name: 'q2', required: true, options: [
                            { id: 'social',  title: 'Social Media' },
                            { id: 'friend',  title: 'Friend / Family' },
                            { id: 'search',  title: 'Search Engine' },
                            { id: 'ad',      title: 'Advertisement' },
                        ]},
                        { type: 'Footer', label: 'Submit', action: 'complete' },
                    ],
                },
            ],
        },
        {
            value: 'get_support', label: 'Get support', group: 'no_endpoint',
            suggestedCategory: 'CUSTOMER_SUPPORT',
            screens: [
                {
                    id: 'ISSUE', title: 'Support Request', terminal: false,
                    components: [
                        { type: 'TextHeading', text: 'How can we help?' },
                        { type: 'Dropdown', label: 'Issue Category', name: 'category', required: true, options: [
                            { id: 'technical', title: 'Technical Issue' },
                            { id: 'billing',   title: 'Billing Question' },
                            { id: 'feature',   title: 'Feature Request' },
                            { id: 'other',     title: 'Other' },
                        ]},
                        { type: 'TextArea', label: 'Describe your issue', name: 'description', required: true },
                        { type: 'Footer', label: 'Next', action: 'navigate', nextScreen: 'CONTACT' },
                    ],
                },
                {
                    id: 'CONTACT', title: 'Contact Info', terminal: true,
                    components: [
                        { type: 'TextHeading', text: 'Contact Details' },
                        { type: 'TextInput', label: 'Your Email', name: 'email', required: true, inputType: 'email' },
                        { type: 'Dropdown', label: 'Urgency', name: 'urgency', required: true, options: [
                            { id: 'low',    title: 'Low — Can wait a few days' },
                            { id: 'medium', title: 'Medium — Need help soon' },
                            { id: 'high',   title: 'High — Urgent' },
                        ]},
                        { type: 'Footer', label: 'Submit', action: 'complete' },
                    ],
                },
            ],
        },
        {
            value: 'update_preferences', label: 'Update preferences', group: 'no_endpoint',
            suggestedCategory: 'OTHER',
            screens: [
                {
                    id: 'PREFERENCES', title: 'Preferences', terminal: true,
                    components: [
                        { type: 'TextHeading', text: 'Update Your Preferences' },
                        { type: 'CheckboxGroup', label: 'Communication Preferences', name: 'prefs', required: false, options: [
                            { id: 'email',       title: 'Email Updates' },
                            { id: 'sms',         title: 'SMS Notifications' },
                            { id: 'whatsapp',    title: 'WhatsApp Messages' },
                            { id: 'promotions',  title: 'Promotional Offers' },
                        ]},
                        { type: 'Dropdown', label: 'Preferred Language', name: 'language', required: true, options: [
                            { id: 'en', title: 'English' },
                            { id: 'ar', title: 'Arabic' },
                            { id: 'fr', title: 'French' },
                        ]},
                        { type: 'Footer', label: 'Save Preferences', action: 'complete' },
                    ],
                },
            ],
        },

        // ── With endpoint ─────────────────────────────────────────────────────
        {
            value: 'sign_in', label: 'Sign in', group: 'with_endpoint',
            suggestedCategory: 'SIGN_IN',
            screens: [
                {
                    id: 'LOGIN', title: 'Sign In', terminal: true,
                    components: [
                        { type: 'TextHeading', text: 'Welcome Back' },
                        { type: 'TextBody',    text: 'Sign in to continue to your account.' },
                        { type: 'TextInput', label: 'Email or Phone', name: 'identifier', required: true, inputType: 'text' },
                        { type: 'TextInput', label: 'Password',       name: 'password',   required: true, inputType: 'password' },
                        { type: 'Footer', label: 'Sign In', action: 'complete' },
                    ],
                },
            ],
        },
        {
            value: 'register_account', label: 'Register for an account', group: 'with_endpoint',
            suggestedCategory: 'SIGN_UP',
            screens: [
                {
                    id: 'REGISTER', title: 'Create Account', terminal: false,
                    components: [
                        { type: 'TextHeading', text: 'Create Account' },
                        { type: 'TextInput', label: 'Full Name', name: 'name',     required: true, inputType: 'text' },
                        { type: 'TextInput', label: 'Email',     name: 'email',    required: true, inputType: 'email' },
                        { type: 'TextInput', label: 'Password',  name: 'password', required: true, inputType: 'password' },
                        { type: 'Footer', label: 'Register', action: 'navigate', nextScreen: 'TERMS' },
                    ],
                },
                {
                    id: 'TERMS', title: 'Terms and Conditions', terminal: true,
                    components: [
                        { type: 'TextHeading', text: 'Our Terms' },
                        { type: 'TextBody',    text: 'By registering, you agree to our Terms of Service and Privacy Policy. Please read them carefully before proceeding.' },
                        { type: 'Footer', label: 'Accept & Continue', action: 'complete' },
                    ],
                },
            ],
        },
        {
            value: 'book_appointment', label: 'Book an appointment', group: 'with_endpoint',
            suggestedCategory: 'APPOINTMENT_BOOKING',
            screens: [
                {
                    id: 'DETAILS', title: 'Your Details', terminal: false,
                    components: [
                        { type: 'TextHeading', text: 'Book an Appointment' },
                        { type: 'TextInput', label: 'Full Name',    name: 'name',  required: true, inputType: 'text' },
                        { type: 'TextInput', label: 'Phone Number', name: 'phone', required: true, inputType: 'phone' },
                        { type: 'Footer', label: 'Next', action: 'navigate', nextScreen: 'SLOT' },
                    ],
                },
                {
                    id: 'SLOT', title: 'Choose Time', terminal: true,
                    components: [
                        { type: 'TextHeading', text: 'Choose a Slot' },
                        { type: 'Dropdown', label: 'Preferred Day', name: 'day', required: true, options: [
                            { id: 'mon', title: 'Monday' },
                            { id: 'tue', title: 'Tuesday' },
                            { id: 'wed', title: 'Wednesday' },
                            { id: 'thu', title: 'Thursday' },
                        ]},
                        { type: 'Dropdown', label: 'Preferred Time', name: 'time', required: true, options: [
                            { id: '9am',  title: '9:00 AM' },
                            { id: '11am', title: '11:00 AM' },
                            { id: '2pm',  title: '2:00 PM' },
                            { id: '4pm',  title: '4:00 PM' },
                        ]},
                        { type: 'Footer', label: 'Book Appointment', action: 'complete' },
                    ],
                },
            ],
        },
        {
            value: 'get_quote', label: 'Get a quote', group: 'with_endpoint',
            suggestedCategory: 'LEAD_GENERATION',
            screens: [
                {
                    id: 'REQUIREMENTS', title: 'Your Requirements', terminal: false,
                    components: [
                        { type: 'TextHeading', text: 'Request a Quote' },
                        { type: 'TextInput', label: 'Company Name', name: 'company', required: true, inputType: 'text' },
                        { type: 'Dropdown', label: 'Service Type', name: 'service', required: true, options: [
                            { id: 'basic',    title: 'Basic Package' },
                            { id: 'standard', title: 'Standard Package' },
                            { id: 'premium',  title: 'Premium Package' },
                            { id: 'custom',   title: 'Custom Solution' },
                        ]},
                        { type: 'Footer', label: 'Next', action: 'navigate', nextScreen: 'CONTACT' },
                    ],
                },
                {
                    id: 'CONTACT', title: 'Contact Details', terminal: true,
                    components: [
                        { type: 'TextHeading', text: 'Contact Information' },
                        { type: 'TextInput', label: 'Your Email',     name: 'email',        required: true,  inputType: 'email' },
                        { type: 'TextInput', label: 'Phone Number',   name: 'phone',        required: false, inputType: 'phone' },
                        { type: 'TextArea',  label: 'Additional Info', name: 'requirements', required: false },
                        { type: 'Footer', label: 'Request Quote', action: 'complete' },
                    ],
                },
            ],
        },
    ];

    const noEndpointTemplates   = TEMPLATES.filter(t => t.group === 'no_endpoint');
    const withEndpointTemplates = TEMPLATES.filter(t => t.group === 'with_endpoint');

    const selectedTemplate = ref('default');

    const currentPreviewScreens = computed(() => {
        const tpl = TEMPLATES.find(t => t.value === selectedTemplate.value);
        return tpl?.screens ?? [];
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
        form.transform(data => ({
            ...data,
            categories: data.categories ? [data.categories] : ['OTHER'],
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
