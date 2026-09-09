export const decodeMetaUnicode = (value) => {
    if (typeof value !== 'string') {
        return value;
    }

    return value.replace(/\\u([0-9A-Fa-f]{4})/g, (_, hex) =>
        String.fromCharCode(parseInt(hex, 16))
    );
};

export const META_EMBEDDED_SIGNUP_ORIGINS = Object.freeze([
    'https://www.facebook.com',
    'https://web.facebook.com',
]);

export const isAllowedMetaOrigin = (origin) =>
    META_EMBEDDED_SIGNUP_ORIGINS.includes(origin);

export const embeddedSignupOptions = (configId, coexistence = false) => ({
    config_id: configId,
    response_type: 'code',
    override_default_response_type: true,
    extras: {
        setup: {},
        ...(coexistence
            ? { featureType: 'whatsapp_business_app_onboarding' }
            : {}),
    },
});

export const parseEmbeddedSignupEvent = (messageEvent) => {
    if (!messageEvent || !isAllowedMetaOrigin(messageEvent.origin)) {
        return null;
    }

    try {
        const payload = typeof messageEvent.data === 'string'
            ? JSON.parse(messageEvent.data)
            : messageEvent.data;

        return payload?.type === 'WA_EMBEDDED_SIGNUP' ? payload : null;
    } catch {
        return null;
    }
};
