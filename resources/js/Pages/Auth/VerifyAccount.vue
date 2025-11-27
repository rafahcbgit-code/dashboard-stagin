<template>
    <div :class="rtlClass">
        <div class="flex h-screen justify-center">
            <div class="flex justify-center items-center">
                <div class="w-[20em] md:w-[30em]">
                    <div class="flex justify-center mb-5">
                        <Link href="/">
                            </Link>
                    </div>

                    <h1 class="text-2xl text-center">{{ $t('Verify Your Account') }}</h1>
                    <div class="text-center text-sm text-slate-500 mt-2">
                        {{ $t('Please enter the verification code sent to your email/phone.') }}
                    </div>

                    <form @submit.prevent="submitVerification()" class="mt-5 pb-10">
                        <div class="mt-5">
                            <FormInput 
                                v-model="form.code" 
                                :name="$t('Verification Code')" 
                                :error="form.errors.code" 
                                :type="'text'" 
                                placeholder="123456"
                                class="text-center tracking-widest text-lg"
                            />
                        </div>

                        <div v-if="form.errors.general" class="text-red-500 text-sm text-center mt-2">
                            {{ form.errors.general }}
                        </div>

                        <div class="mt-6">
                            <button v-if="!form.processing" type="submit" class="rounded-md bg-primary px-3 py-3 text-sm text-white shadow-sm w-full">
                                {{ $t('Verify') }}
                            </button>
                            <button v-else type="button" disabled class="rounded-md bg-primary px-3 py-3 text-sm text-white shadow-sm w-full flex justify-center opacity-70 cursor-not-allowed">
                                <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </button>
                        </div>

                        <div class="text-center mt-4">
                            <button type="button" @click="resendCode" :disabled="resendDisabled" class="text-sm text-slate-600 hover:text-primary-800 underline disabled:text-gray-400 disabled:no-underline">
                                {{ resendDisabled ? $t('Resend in') + ' ' + timer + 's' : $t('Resend Verification Code') }}
                            </button>
                        </div>

                        <div class="text-center mt-3 border-t pt-3">
                            <span class="text-sm text-slate-500">{{ $t('Or you can') }} </span>
                            <Link href="otp-email" class="text-sm font-semibold text-primary-600 hover:text-primary-800 hover:underline">
                                {{ $t('Send to email') }}
                            </Link>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
    import { Link, useForm } from "@inertiajs/vue3";
    import FormInput from '@/Components/FormInput.vue';
    import { defineProps, ref, onMounted } from 'vue';
    import { useRtl } from '@/Composables/useRtl';

    const { rtlClass } = useRtl();
    // const props = defineProps(['companyConfig']); 

    const resendDisabled = ref(false);
    const timer = ref(60);

    const form = useForm({
        code: '',
    });

    const submitVerification = () => {
        form.post('/verify-account-code', { 
            preserveScroll: true,
            onSuccess: () => {
                // Success logic
            }
        });
    };

    const resendCode = () => {
        form.post('/resend-verification-code', {
            onSuccess: () => {
                startTimer();
            }
        });
    };

    const startTimer = () => {
        resendDisabled.value = true;
        timer.value = 60;
        const interval = setInterval(() => {
            if (timer.value > 0) {
                timer.value--;
            } else {
                resendDisabled.value = false;
                clearInterval(interval);
            }
        }, 1000);
    };
</script>