<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] overflow-y-scroll">
            <div class="md:flex justify-between hidden">
                <div>
                    <h1 class="text-xl mb-1">{{ props.config ? $t('Edit Otp Credentials') : $t('Add New Otp Credentials') }}</h1>
                    <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ml-1 mt-1">{{ $t('Configure the necessary credentials for the OTP service.') }}</span>
                    </p>
                </div>
                <div>
                    <Link href="/admin/otp-config" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Back to List') }}</Link>
                </div>
            </div>
            
            <form @submit.prevent="submitForm()" class="bg-white md:border py-5 px-5 rounded-[0.5rem] space-y-5">
                
                <div class="sm:flex border-b pb-5 pt-0">
                    <div class="sm:w-[40%] mb-1">
                        <h1 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Template Name') }}</h1>
                        <span class="flex text-xs mt-2 text-gray-400">
                            {{ $t('A descriptive name for this template configuration.') }}
                        </span>
                    </div>
                    <div class="sm:w-[60%] sm:flex space-x-6">
                        <div class="sm:w-[80%]">
                            <FormInput v-model="form.template_name" :type="'text'" :error="form.errors.template_name" :class="'w-full'" :labelClass="'mb-0'"/>
                        </div>
                    </div>
                </div>

                <div class="sm:flex border-b pb-5">
                    <div class="sm:w-[40%] mb-1">
                        <h1 class="text-sm text-gray-500 tracking-[0px]">{{ $t('UUID') }}</h1>
                        <span class="flex text-xs mt-2 text-gray-400">
                            {{ $t('The unique identifier for the service.') }}
                        </span>
                    </div>
                    <div class="sm:w-[60%] sm:flex space-x-6">
                        <div class="sm:w-[80%]">
                            <FormInput v-model="form.uuid" :type="'text'" :error="form.errors.uuid" :class="'w-full'" :labelClass="'mb-0'"/>
                        </div>
                    </div>
                </div>

                <div class="sm:flex pb-5">
                    <div class="sm:w-[40%] mb-1">
                        <h1 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Access Token') }}</h1>
                        <span class="flex text-xs mt-2 text-gray-400">
                            {{ $t('The long security token required for API access.') }}
                        </span>
                    </div>
                    <div class="sm:w-[60%] sm:flex space-x-6">
                        <div class="sm:w-[80%]">
                            <FormInput v-model="form.access_token" :type="'text'" :error="form.errors.access_token" :class="'w-full'" :labelClass="'mb-0'"/>
                        </div>
                    </div>
                </div>

                <div class="py-6">
                    <button type="submit" :disabled="form.processing" class="float-right flex items-center space-x-4 rounded-md bg-black px-3 py-2 text-sm text-white shadow-sm hover:bg-slate-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                        <svg v-if="form.processing" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span>{{ $t('Save') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<script setup>
    import AppLayout from "./../Layout/App.vue";
    import { Link, useForm } from "@inertiajs/vue3";
    import FormInput from '@/Components/FormInput.vue';

    const props = defineProps({ 
        title: String, 
        config: Object 
    });

    const form = useForm({
        template_name: props.config?.template_name || '',
        uuid: props.config?.uuid || '',
        access_token: props.config?.access_token || '',
    });

    const submitForm = async () => {
        // تم توحيد الرابط ليشير دائماً إلى مسار التخزين (store)
        const url = '/admin/otp-config';

        // استخدام post دائماً لإرسال البيانات إلى دالة store
        form.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                // يمكنك إضافة كود للتعامل مع النجاح هنا
            },
        });
    };
    
</script>