<script setup>
    import { ref, onMounted } from 'vue';
    import { router } from "@inertiajs/vue3";
    import { decodeMetaUnicode } from '../lib/embeddedSignupHelpers.js';
    
    const props = defineProps(['appId', 'configId', 'graphAPIVersion'])

    const isSetupLoading = ref(false);

    onMounted(() => {
        console.log('[ESU] Component mounted');
        console.log('[ESU] Props received:', {
            appId: props.appId,
            configId: props.configId,
            graphAPIVersion: props.graphAPIVersion,
        });
        console.log('[ESU] FB SDK already loaded?', typeof FB !== 'undefined');

        window.fbAsyncInit = function () {
            console.log('[ESU] fbAsyncInit fired — calling FB.init()');
            FB.init({
                appId: props.appId,
                cookie: true,
                xfbml: true,
                version: props.graphAPIVersion
            });
            console.log('[ESU] FB.init() done');
        };

        // Load the JavaScript SDK asynchronously
        (function (d, s, id) {
            const existing = d.getElementById(id);
            console.log('[ESU] FB SDK script already in DOM?', !!existing);
            if (existing) return;
            var js, fjs = d.getElementsByTagName(s)[0];
            js = d.createElement(s);
            js.id = id;
            js.src = "https://connect.facebook.net/en_US/sdk.js";
            js.onload = () => console.log('[ESU] FB SDK script loaded from network');
            fjs.parentNode.insertBefore(js, fjs);
        }(document, 'script', 'facebook-jssdk'));
    });

    let sessionPhoneNumberId = null;
    let sessionWabaId = null;
    let authCode = null;
    let codeGeneratedAt = null;
    let submitTimer = null;
    let hasSubmitted = false;
    const sessionInfoWaitMs = 8000;

    const getSignupValue = (payload, keys) => {
        if (!payload || typeof payload !== 'object') {
            return null;
        }

        for (const key of keys) {
            if (payload[key]) {
                return payload[key];
            }
        }

        return null;
    };

    const captureSessionInfo = (payload) => {
        const phoneNumberId = getSignupValue(payload, ['phone_number_id', 'phoneNumberId']);
        const wabaId = getSignupValue(payload, ['waba_id', 'wabaId', 'whatsapp_business_account_id']);

        if (phoneNumberId) {
            sessionPhoneNumberId = phoneNumberId;
        }

        if (wabaId) {
            sessionWabaId = wabaId;
        }
    };

    const submitSignup = () => {
        if (hasSubmitted || !authCode) {
            return;
        }

        if (!sessionWabaId) {
            console.warn('[ESU] Submitting without waba_id after waiting for session info; backend fallback will be used.');
        }

        hasSubmitted = true;
        clearTimeout(submitTimer);
        console.log('[ESU] Sending POST to /whatsapp/exchange-code at', new Date().toISOString());

        router.post(`/whatsapp/exchange-code`, {
            token: authCode,
            phone_number_id: sessionPhoneNumberId,
            waba_id: sessionWabaId,
        }, {
            preserveState: true,
            onSuccess: (page) => {
                isSetupLoading.value = false;
                const status = page?.props?.flash?.status;

                if (status?.type === 'error') {
                    hasSubmitted = false;
                    console.error('[ESU] Backend diagnosis:', status.message);
                    return;
                }

                console.log('[ESU] Backend exchange-code: SUCCESS');
            },
            onError: (errors) => {
                console.error('[ESU] Backend exchange-code: ERROR', JSON.stringify(errors));
                isSetupLoading.value = false;
                hasSubmitted = false;
            },
            onFinish: () => {
                const elapsed = Date.now() - codeGeneratedAt;
                console.log('[ESU] Backend request finished. Time from code received to finish:', elapsed, 'ms');
            }
        })
    };

    const queueSignupSubmit = () => {
        if (hasSubmitted || !authCode) {
            return;
        }

        if (sessionWabaId) {
            submitSignup();
            return;
        }

        if (!submitTimer) {
            console.log('[ESU] Auth code received before waba_id; waiting for Meta session info.');
            submitTimer = setTimeout(submitSignup, sessionInfoWaitMs);
        }
    };

    const logEmbeddedSignupEvent = (eventName, payload) => {
        if (!payload) {
            console.log('[ESU] Embedded Signup event:', eventName, 'payload: empty');
            return;
        }

        const decodedHtml = decodeMetaUnicode(payload.html);
        const payloadForLog = {
            ...payload,
            ...(decodedHtml && decodedHtml !== payload.html ? { decoded_html: decodedHtml } : {})
        };

        console.log('[ESU] Embedded Signup event:', eventName, payloadForLog);
    };

    const sessionInfoListener = (event) => {
        if (event.origin !== "https://www.facebook.com" && event.origin !== "https://web.facebook.com") {
            return;
        }

        try {
            const data = JSON.parse(event.data);

            if (data.type === 'WA_EMBEDDED_SIGNUP') {
                logEmbeddedSignupEvent(data.event, data.data);
                captureSessionInfo(data.data);

                if (sessionWabaId) {
                    console.log('[ESU] Session info captured - phone_number_id:', sessionPhoneNumberId, '| waba_id:', sessionWabaId);
                    queueSignupSubmit();
                }

                if (data.event === 'FINISH_ONLY_WABA' || data.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING') {
                    queueSignupSubmit();
                }

                if (data.event === 'FINISH') {
                    const {phone_number_id, waba_id} = data.data;
                    sessionPhoneNumberId = phone_number_id || sessionPhoneNumberId;
                    sessionWabaId = waba_id || sessionWabaId;
                    console.log('[ESU] FINISH event — phone_number_id:', phone_number_id, '| waba_id:', waba_id);
                } else if (data.event === 'CANCEL') {
                    console.warn('[ESU] CANCEL event — user cancelled at step:', data.data?.current_step);
                } else if (data.event === 'ERROR') {
                    console.error('[ESU] ERROR event from Meta dialog:', JSON.stringify(data.data));
                } else {
                    console.log('[ESU] Unknown WA_EMBEDDED_SIGNUP event:', data.event, JSON.stringify(data.data));
                }
            }
        } catch {
            // non-JSON postMessage — ignore
        }
    };

    function launchWhatsAppSignup() {
        console.log('[ESU] launchWhatsAppSignup() called at', new Date().toISOString());
        console.log('[ESU] FB object available?', typeof FB !== 'undefined');
        clearTimeout(submitTimer);
        submitTimer = null;
        hasSubmitted = false;
        authCode = null;
        sessionPhoneNumberId = null;
        sessionWabaId = null;
        codeGeneratedAt = null;
        window.removeEventListener("message", sessionInfoListener);
        window.addEventListener("message", sessionInfoListener);

        // Conversion tracking code
        if (typeof fbq !== 'undefined') {
            fbq('trackCustom', 'WhatsAppOnboardingStart', {
                appId: props.appId,
                feature: 'whatsapp_embedded_signup'
            });
        }

        // Launch Facebook login
        FB.login(function (response) {
            const callbackAt = new Date().toISOString();
            console.log('[ESU] FB.login() callback fired at', callbackAt);
            console.log('[ESU] Full authResponse:', JSON.stringify(response));

            if (response.authResponse) {
                codeGeneratedAt = Date.now();
                const code = response.authResponse.code;
                authCode = code;
                console.log('[ESU] Auth code received — length:', code?.length, '| first 10 chars:', code?.substring(0, 10));
                console.log('[ESU] session phone_number_id:', sessionPhoneNumberId, '| waba_id:', sessionWabaId);

                isSetupLoading.value = true;
                queueSignupSubmit();
                return;

            } else {
                console.warn('[ESU] No authResponse — user cancelled or did not authorize. Status:', response.status);
            }
        }, {
            config_id: props.configId,
            response_type: 'code',
            override_default_response_type: true,
            extras: {
                sessionInfoVersion: '3',
                setup: {}
            }
        });
    }
</script>
<template>
    <div v-if="isSetupLoading" class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50">
        <div class="bg-white p-6 rounded-lg shadow-lg text-center text-sm">
            <div class="flex justify-center mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 24 24"><path fill="black" d="M12,1A11,11,0,1,0,23,12,11,11,0,0,0,12,1Zm0,20a9,9,0,1,1,9-9A9,9,0,0,1,12,21Z" transform="matrix(0 0 0 0 12 12)"><animateTransform id="svgSpinnersPulseRingsMultiple0" attributeName="transform" begin="0;svgSpinnersPulseRingsMultiple2.end" calcMode="spline" dur="1.2s" keySplines=".52,.6,.25,.99" type="translate" values="12 12;0 0"/><animateTransform additive="sum" attributeName="transform" begin="0;svgSpinnersPulseRingsMultiple2.end" calcMode="spline" dur="1.2s" keySplines=".52,.6,.25,.99" type="scale" values="0;1"/><animate attributeName="opacity" begin="0;svgSpinnersPulseRingsMultiple2.end" calcMode="spline" dur="1.2s" keySplines=".52,.6,.25,.99" values="1;0"/></path><path fill="black" d="M12,1A11,11,0,1,0,23,12,11,11,0,0,0,12,1Zm0,20a9,9,0,1,1,9-9A9,9,0,0,1,12,21Z" transform="matrix(0 0 0 0 12 12)"><animateTransform id="svgSpinnersPulseRingsMultiple1" attributeName="transform" begin="svgSpinnersPulseRingsMultiple0.begin+0.2s" calcMode="spline" dur="1.2s" keySplines=".52,.6,.25,.99" type="translate" values="12 12;0 0"/><animateTransform additive="sum" attributeName="transform" begin="svgSpinnersPulseRingsMultiple0.begin+0.2s" calcMode="spline" dur="1.2s" keySplines=".52,.6,.25,.99" type="scale" values="0;1"/><animate attributeName="opacity" begin="svgSpinnersPulseRingsMultiple0.begin+0.2s" calcMode="spline" dur="1.2s" keySplines=".52,.6,.25,.99" values="1;0"/></path><path fill="black" d="M12,1A11,11,0,1,0,23,12,11,11,0,0,0,12,1Zm0,20a9,9,0,1,1,9-9A9,9,0,0,1,12,21Z" transform="matrix(0 0 0 0 12 12)"><animateTransform id="svgSpinnersPulseRingsMultiple2" attributeName="transform" begin="svgSpinnersPulseRingsMultiple0.begin+0.4s" calcMode="spline" dur="1.2s" keySplines=".52,.6,.25,.99" type="translate" values="12 12;0 0"/><animateTransform additive="sum" attributeName="transform" begin="svgSpinnersPulseRingsMultiple0.begin+0.4s" calcMode="spline" dur="1.2s" keySplines=".52,.6,.25,.99" type="scale" values="0;1"/><animate attributeName="opacity" begin="svgSpinnersPulseRingsMultiple0.begin+0.4s" calcMode="spline" dur="1.2s" keySplines=".52,.6,.25,.99" values="1;0"/></path></svg>
            </div>
            <p>{{ $t('Please wait for your whatsapp account to be connected!') }}</p>
        </div>
    </div>

    <button @click="launchWhatsAppSignup" class="bg-primary text-white p-2 rounded-lg text-sm mt-5 flex px-3 w-fit">
        {{ $t('Setup whatsapp') }}
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"><g fill="currentColor" fill-rule="evenodd" clip-rule="evenodd"><g opacity=".2"><path d="M12.206 5.848a1.5 1.5 0 0 1 2.113.192l3.333 4a1.5 1.5 0 1 1-2.304 1.92l-3.334-4a1.5 1.5 0 0 1 .192-2.112Z"/><path d="M12.206 16.152a1.5 1.5 0 0 1-.192-2.112l3.334-4a1.5 1.5 0 0 1 2.304 1.92l-3.333 4a1.5 1.5 0 0 1-2.113.192Z"/><path d="M16 11a1.5 1.5 0 0 1-1.5 1.5h-8a1.5 1.5 0 0 1 0-3h8A1.5 1.5 0 0 1 16 11Z"/></g><path d="M11.347 5.616a.5.5 0 0 1 .704.064l3.333 4a.5.5 0 0 1-.768.64l-3.333-4a.5.5 0 0 1 .064-.704Z"/><path d="M11.347 14.384a.5.5 0 0 1-.064-.704l3.333-4a.5.5 0 0 1 .768.64l-3.333 4a.5.5 0 0 1-.704.064Z"/><path d="M15.5 10a.5.5 0 0 1-.5.5H5a.5.5 0 0 1 0-1h20a.5.5 0 0 1 .5.5Z"/></g></svg>
    </button>
</template>
