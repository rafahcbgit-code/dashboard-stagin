import {
    decodeMetaUnicode,
    embeddedSignupOptions,
    isAllowedMetaOrigin,
    parseEmbeddedSignupEvent,
} from '../resources/js/lib/embeddedSignupHelpers.js';

const tests = [
    {
        label: 'decodes escaped unicode',
        input: '\u0634\u0643\u0631\u0627',
        expected: 'شكرا',
    },
    {
        label: 'leaves non-string untouched',
        input: 123,
        expected: 123,
    },
];

for (const test of tests) {
    const actual = decodeMetaUnicode(test.input);

    if (actual !== test.expected) {
        console.error(`Test "${test.label}" failed: expected "${test.expected}", got "${actual}"`);
        process.exitCode = 1;
        break;
    }
}

if (process.exitCode !== 1) {
    const standardOptions = embeddedSignupOptions('v4-config');
    const coexistenceOptions = embeddedSignupOptions('v4-config', true);

    if (standardOptions.extras.sessionInfoVersion !== undefined) {
        throw new Error('Standard v4 options must not include sessionInfoVersion.');
    }

    if (standardOptions.extras.featureType !== undefined) {
        throw new Error('Standard v4 options must not include a Coexistence featureType.');
    }

    if (coexistenceOptions.extras.featureType !== 'whatsapp_business_app_onboarding') {
        throw new Error('Coexistence v4 options must retain whatsapp_business_app_onboarding.');
    }

    if (!isAllowedMetaOrigin('https://www.facebook.com') || isAllowedMetaOrigin('https://example.com')) {
        throw new Error('Meta postMessage origin validation failed.');
    }

    const coexistenceFinish = parseEmbeddedSignupEvent({
        origin: 'https://www.facebook.com',
        data: JSON.stringify({
            type: 'WA_EMBEDDED_SIGNUP',
            event: 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
            version: 3,
            data: { waba_id: '123' },
        }),
    });

    if (coexistenceFinish?.data?.waba_id !== '123' || coexistenceFinish?.version !== 3) {
        throw new Error('Coexistence completion without phone_number_id must be accepted.');
    }

    if (parseEmbeddedSignupEvent({ origin: 'https://example.com', data: '{}' }) !== null) {
        throw new Error('Untrusted postMessage origins must be rejected.');
    }

    console.log('All Embedded Signup v4 helper tests pass.');
}
