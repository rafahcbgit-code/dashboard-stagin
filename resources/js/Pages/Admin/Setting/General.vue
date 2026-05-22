<template>
    <AppLayout>
        <div>
            <h2 class="text-xl mb-1">{{ $t('General settings') }}</h2>
            <p class="mb-6 flex items-center text-sm leading-6">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                <span class="ml-1 mt-1">{{ $t('Configure general account information') }}</span>
            </p>
        </div>
        <form @submit.prevent="submitForm()" enctype="multipart/form-data">
            <div class="space-y-12">
                <div class="pb-12">
                    <div class="grid gap-6 grid-cols-2 pb-10 border-b md:w-2/3">
                        <FormInput v-model="form.company_name" :name="$t('Organization/company name')" :error="form.errors.company_name" :type="'text'" :class="'col-span-2'"/>
                        <FormInput v-model="form.phone" :name="$t('Phone')" :error="form.errors.phone" :type="'text'" :class="'col-span-1'"/>
                        <FormInput v-model="form.email" :name="$t('Email')" :error="form.errors.email" :type="'text'" :class="'col-span-1'"/>
                        <FormTextArea v-model="form.address" :name="$t('Address')" :showLabel="true" :error="form.errors.address" :class="'col-span-2'"/>
                    </div>

                    <div class="pt-5">
                        <h2 class="text-base text-gray-900">{{ $t('Social media accounts') }}</h2>
                        <p class="text-sm leading-6 mb-5">{{ $t('Add your social media account links') }}</p>

                        <div class="grid gap-6 grid-cols-2 pb-10 border-b md:w-2/3">
                            <FormInput v-model="form.socials.facebook" :name="'Facebook'" :type="'text'" :class="'col-span-1'"/>
                            <FormInput v-model="form.socials.twitter" :name="'Twitter'" :type="'text'" :class="'col-span-1'"/>
                            <FormInput v-model="form.socials.instagram" :name="'Instagram'" :type="'text'" :class="'col-span-1'"/>
                            <FormInput v-model="form.socials.slack" :name="'Slack'" :type="'text'" :class="'col-span-1'"/>
                            <FormInput v-model="form.socials.linkedin" :name="'LinkedIn'" :type="'text'" :class="'col-span-1'"/>
                        </div>
                    </div>

                    <!--<div class="pt-5">
                        <h2 class="text-base text-gray-900">{{ $t('App environment') }}</h2>
                        <p class="text-sm leading-6 mb-5">{{ $t('Set your app environment') }}</p>

                        <div class="grid gap-6 grid-cols-2 pb-10 border-b md:w-2/3">
                            <FormSelect v-model="form.app_environment" :name="$t('App environment')" :type="'text'"  :options="options" :error="form.errors.app_environment" :class="'col-span-2'"/>
                        </div>
                    </div>-->

                    <div class="pt-5">
                        <h2 class="text-base text-gray-900">{{ $t('Frontend Settings') }}</h2>
                        <p class="text-sm leading-6 mb-5">{{ $t('Show/hide the main frontend page') }}</p>

                        <div class="flex gap-x-10 md:w-2/3 pb-10 border-b">
                            <div class="w-[80%]">
                                <span>{{ $t('Use the current default frontend') }}</span>
                                <div class="text-xs text-slate-700 flex items-center">
                                    <svg class="mr-1" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    <span>{{ $t('Toggle on/off to show/hide the main frontend page') }}</span>
                                </div>
                            </div>
                            <div class="w-[20%]">
                                <FormToggleSwitch v-model="form.display_frontend" class="float-right"/>
                            </div>
                        </div>
                    </div>

                    <div class="pt-5">
                        <h2 class="text-base text-gray-900">{{ $t('AI Confidence Settings') }}</h2>
                        <p class="text-sm leading-6 mb-5">{{ $t('Set the global confidence threshold used for AI trigger matching and Knowledge Base answers. This applies to all organizations.') }}</p>

                        <div class="md:w-2/3 pb-10 border-b">
                            <div class="rounded-lg border border-slate-200 p-5">
                                <div class="flex items-start gap-3">
                                    <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-violet-100 flex items-center justify-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-violet-600"><path d="M12 2a5 5 0 0 1 5 5v3a5 5 0 0 1-10 0V7a5 5 0 0 1 5-5z"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="m4.93 19.07 1.41-1.41"/><path d="m17.66 6.34 1.41-1.41"/></svg>
                                    </div>
                                    <div class="flex-1">
                                        <label class="block text-sm font-semibold text-slate-800">{{ $t('AI Confidence Threshold') }}</label>
                                        <p class="text-xs text-slate-500 mt-0.5">{{ $t('Controls how confident AI must be for both trigger matching and Knowledge Base answers. Higher = stricter matching, fewer false positives. Lower = more matches but less accurate.') }}</p>
                                    </div>
                                </div>
                                <div class="mt-4 rounded-lg border border-violet-100 bg-violet-50/50 p-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <label class="text-xs font-medium text-slate-600">{{ $t('Confidence required') }}</label>
                                        <span class="text-sm font-bold text-primary tabular-nums">{{ form.ai_confidence_threshold }}%</span>
                                    </div>
                                    <input
                                        v-model.number="form.ai_confidence_threshold"
                                        type="range"
                                        min="0"
                                        max="100"
                                        step="5"
                                        class="w-full h-2 rounded-lg appearance-none cursor-pointer accent-primary"
                                    />
                                    <div class="flex justify-between text-[10px] text-slate-400 mt-1">
                                        <span>{{ $t('More matches (50%)') }}</span>
                                        <span>{{ $t('Stricter matching (95%)') }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pt-5">
                        <h2 class="text-base text-gray-900">{{ $t('Site logo and favicon') }}</h2>
                        <p class="text-sm leading-6 mb-5">{{ $t('Add your logo and favicon') }}</p>

                        <div class="grid gap-6 grid-cols-2 pb-10 border-b md:w-2/3">
                            <FormImageLogo v-model="form.logo" :name="$t('Site logo')" :error="form.errors.logo" :label="$t('Upload logo')" :imageUrl="getImageUrl('logo')" :class="'col-span-2'"/>
                            <FormImageFavicon v-model="form.favicon" :name="$t('Favicon')" :error="form.errors.favicon" :label="$t('Upload logo')" :imageUrl="getImageUrl('favicon')" :class="'col-span-2'"/>
                        </div>
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-x-6 md:w-2/3">
                        <button type="button" class="text-sm leading-6 text-gray-900">{{ $t('Cancel') }}</button>
                        <button
                            :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': isLoading }]"
                            :disabled="isLoading"
                        >
                            <svg v-if="isLoading" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            <span v-else>{{ $t('Save') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./Layout/App.vue";
    import { defineProps, ref } from 'vue';
    import { useForm } from "@inertiajs/vue3";
    import FormCheckbox from '@/Components/FormCheckbox.vue';
    import FormImageFavicon from '@/Components/FormImageFavicon.vue';
    import FormImageLogo from '@/Components/FormImageLogo.vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import FormTextArea from '@/Components/FormTextArea.vue';
    import FormToggleSwitch from '@/Components/FormToggleSwitch.vue';

    const props = defineProps({
        config: {
            type: Array,
            required: true
        }
    });

    const getValueByKey = (key) => {
        const found = props.config.find(item => item.key === key);
        return found ? found.value : '';
    };

    const getImageUrl = (value) => {
        const file = getValueByKey(value);
        return file != '' && file != null ? '/media/' + file : null;
    }

    const options = [
        { label: 'Local', value: 'local' },
        { label: 'Production', value: 'production' },
    ]

    const socials = JSON.parse(getValueByKey('socials'));
    const isLoading = ref(false);

    const form = useForm({
        _method: 'put',
        company_name: getValueByKey('company_name'),
        phone: getValueByKey('phone'),
        email: getValueByKey('email'),
        address: getValueByKey('address'),
        recaptcha_active: getValueByKey('recaptcha_active') === '1',
        recaptcha_site_key: getValueByKey('recaptcha_site_key'),
        recaptcha_secret_key: getValueByKey('recaptcha_secret_key'),
        google_analytics_tracking_id: getValueByKey('google_analytics_tracking_id'),
        google_maps_api_key: getValueByKey('google_maps_api_key'),
        app_environment: getValueByKey('app_environment'),
        display_frontend:  getValueByKey('display_frontend') == 1 || getValueByKey('display_frontend') == '' ? true : false,
        ai_confidence_threshold: parseInt(getValueByKey('ai_confidence_threshold')) || 85,
        logo: null,
        favicon: null,
        socials: {
            facebook: socials?.facebook,
            twitter: socials?.twitter,
            instagram: socials?.instagram,
            slack: socials?.slack,
            linkedin: socials?.linkedin
        }
    })

    const submitForm = async () => {
        form.post('/admin/settings?type=general', {
            preserveScroll: true,
        })
    };
</script>