<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { router } from "@inertiajs/vue3";

const props = defineProps({
  appId: String,
  configId: String,        // Coexistence flow config ID
  graphAPIVersion: String, // e.g. "v21.0"
});

const isSetupLoading = ref(false);
let sessionPhoneNumberId = null;
let sessionWabaId = null;
let authCode = null;
let submitTimer = null;
let hasSubmitted = false;
const sessionInfoWaitMs = 8000;

function getSignupValue(payload, keys) {
  if (!payload || typeof payload !== 'object') {
    return null;
  }

  for (const key of keys) {
    if (payload[key]) {
      return payload[key];
    }
  }

  return null;
}

function captureSessionInfo(payload) {
  const phoneNumberId = getSignupValue(payload, ['phone_number_id', 'phoneNumberId']);
  const wabaId = getSignupValue(payload, ['waba_id', 'wabaId', 'whatsapp_business_account_id']);

  if (phoneNumberId) {
    sessionPhoneNumberId = phoneNumberId;
  }

  if (wabaId) {
    sessionWabaId = wabaId;
  }
}

function submitSignup() {
  if (hasSubmitted || !authCode) {
    return;
  }

  if (!sessionWabaId) {
    console.warn('[ESU-CO] Submitting without waba_id after waiting for session info; backend fallback will be used.');
  }

  hasSubmitted = true;
  clearTimeout(submitTimer);

  router.post(`/whatsapp/exchange-code`, {
    token: authCode,
    coexistence: true,
    phone_number_id: sessionPhoneNumberId,
    waba_id: sessionWabaId,
  }, {
    preserveState: true,
    onSuccess: () => {
      router.visit('/settings/whatsapp', { preserveState: false });
    },
    onError: () => {
      isSetupLoading.value = false;
      hasSubmitted = false;
    }
  });
}

function queueSignupSubmit() {
  if (hasSubmitted || !authCode) {
    return;
  }

  if (sessionWabaId) {
    submitSignup();
    return;
  }

  if (!submitTimer) {
    console.log('[ESU-CO] Auth code received before waba_id; waiting for Meta session info.');
    submitTimer = setTimeout(submitSignup, sessionInfoWaitMs);
  }
}

function initFacebookSDK() {
  window.fbAsyncInit = function () {
    FB.init({
      appId: props.appId,
      cookie: true,
      xfbml: true,
      version: props.graphAPIVersion,
    });
  };

  (function (d, s, id) {
    if (d.getElementById(id)) return;
    const js = d.createElement(s);
    js.id = id;
    js.src = "https://connect.facebook.net/en_US/sdk.js";
    const fjs = d.getElementsByTagName(s)[0];
    fjs.parentNode.insertBefore(js, fjs);
  }(document, 'script', 'facebook-jssdk'));
}

function sessionInfoListener(event) {
  if (event.origin !== "https://www.facebook.com" && event.origin !== "https://web.facebook.com") {
    return;
  }

  try {
    const data = JSON.parse(event.data);
    if (data.type === 'WA_EMBEDDED_SIGNUP') {
      captureSessionInfo(data.data);

      if (sessionWabaId) {
        console.log('[ESU-CO] Session info captured:', {
          phone_number_id: sessionPhoneNumberId,
          waba_id: sessionWabaId
        });
        queueSignupSubmit();
      }

      if (data.event === 'FINISH_ONLY_WABA' || data.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING') {
        queueSignupSubmit();
      }

      if (data.event === 'FINISH') {
        const { phone_number_id, waba_id } = data.data;
        sessionPhoneNumberId = phone_number_id || sessionPhoneNumberId;
        sessionWabaId = waba_id || sessionWabaId;
        console.log("✅ Coexistence finished:", { phone_number_id, waba_id });
      } else if (data.event === 'CANCEL') {
        console.log('[ESU-CO] User cancelled at step:', data.data?.current_step);
      } else if (data.event === 'ERROR') {
        console.error('[ESU-CO] Error event from Meta:', JSON.stringify(data.data));
      } else {
        console.log('[ESU-CO] Embedded Signup event:', data.event, data.data);
      }
    }
  } catch {
    // ignore non-JSON messages
  }
}

function launchWhatsAppCoexistenceSignup() {
  clearTimeout(submitTimer);
  submitTimer = null;
  hasSubmitted = false;
  authCode = null;
  sessionPhoneNumberId = null;
  sessionWabaId = null;
  window.removeEventListener("message", sessionInfoListener);
  window.addEventListener("message", sessionInfoListener);

  if (typeof fbq !== 'undefined') {
    fbq('trackCustom', 'WhatsAppOnboardingStart', {
      appId: props.appId,
      feature: 'whatsapp_embedded_signup_coexistence'
    });
  }

  FB.login(function (response) {
    if (response.authResponse) {
      authCode = response.authResponse.code;
      isSetupLoading.value = true;
      queueSignupSubmit();
      return;

    } else {
      isSetupLoading.value = false;
      console.log('❌ User cancelled login or did not authorize.');
    }
  }, {
    config_id: props.configId, 
    response_type: 'code', 
    override_default_response_type: true,
    extras: {
      setup: {},
      featureType: 'whatsapp_business_app_onboarding',
      sessionInfoVersion: '3'
    }
  });
}

onMounted(() => {
  initFacebookSDK();
});

onBeforeUnmount(() => {
  window.removeEventListener("message", sessionInfoListener);
});
</script>



<template>
    <div v-if="isSetupLoading" class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50">
        <div class="bg-white p-6 rounded-lg shadow-lg text-center text-sm">
            <div class="flex justify-center mb-4">
                <!-- FIXED: Simple spinner SVG -->
                <svg class="animate-spin h-12 w-12 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>
            <p>{{ $t('Please wait for your whatsapp coexistence setup!') }}</p>
        </div>
    </div>


    <button @click="launchWhatsAppCoexistenceSignup" class="bg-primary text-white p-2 rounded-lg text-sm mt-5 flex px-3 w-fit">
        {{ $t('Setup WhatsApp Coexistence') }}
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20">
            <g fill="currentColor" fill-rule="evenodd" clip-rule="evenodd">
                <g opacity=".2">
                    <path d="M12.206 5.848a1.5 1.5 0 0 1 2.113.192l3.333 4a1.5 1.5 0 1 1-2.304 1.92l-3.334-4a1.5 1.5 0 0 1 .192-2.112Z"/>
                    <path d="M12.206 16.152a1.5 1.5 0 0 1-.192-2.112l3.334-4a1.5 1.5 0 0 1 2.304 1.92l-3.333 4a1.5 1.5 0 0 1-2.113.192Z"/>
                    <path d="M16 11a1.5 1.5 0 0 1-1.5 1.5h-8a1.5 1.5 0 0 1 0-3h8A1.5 1.5 0 0 1 16 11Z"/>
                </g>
                <path d="M11.347 5.616a.5.5 0 0 1 .704.064l3.333 4a.5.5 0 0 1-.768.64l-3.333-4a.5.5 0 0 1 .064-.704Z"/>
                <path d="M11.347 14.384a.5.5 0 0 1-.064-.704l3.333-4a.5.5 0 0 1 .768.64l-3.333 4a.5.5 0 0 1-.704.064Z"/>
                <path d="M15.5 10a.5.5 0 0 1-.5.5H5a.5.5 0 0 1 0-1h10a.5.5 0 0 1 .5.5Z"/>
            </g>
        </svg>
    </button>
</template>
