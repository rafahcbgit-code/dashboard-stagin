/**
 * Meta Flow Templates
 *
 * Each template stores a complete, valid Meta Flow JSON object (v6.3).
 * This is the single source of truth — it is uploaded to Meta as the
 * flow asset on creation AND used to drive the create-modal live preview.
 *
 * Adding a new template:
 *   1. Add an entry to META_FLOW_TEMPLATES below.
 *   2. Set `value` (unique key), `label` (display name), `suggestedCategory`.
 *   3. Write the full Meta Flow JSON in `flowJson`.
 *   4. No other changes needed — everything else is derived automatically.
 *
 * Meta Flow JSON rules (v6.3):
 *   - Each screen needs: id, title, terminal (true on last screen), layout
 *   - layout.type is always "SingleColumnLayout"
 *   - Use a Form wrapper for screens that have input fields
 *   - Footer on-click-action: { name: "complete"|"navigate", payload: {}, next?: { type:"screen", name:"SCREEN_ID" } }
 *   - Input types: text, email, phone, number, password
 *   - Choice components (Dropdown, RadioButtonsGroup, CheckboxGroup) use "data-source" array
 */

// ─── Shared converter: Meta Flow JSON → FlowPhonePreview-compatible screens ───
// Mirrors the same logic used in View.vue so the preview is consistent.
const mapPreviewComp = (comp) => {
    switch (comp.type) {
        case 'TextHeading':
        case 'TextSubheading':
        case 'TextBody':
            return { type: comp.type, text: comp.text || '' };
        case 'TextInput':
            return {
                type: 'TextInput',
                label: comp.label || '',
                name: comp.name || '',
                required: !!comp.required,
                inputType: comp['input-type'] || 'text',
            };
        case 'TextArea':
            return { type: 'TextArea', label: comp.label || '', name: comp.name || '', required: !!comp.required };
        case 'Dropdown':
        case 'CheckboxGroup':
        case 'RadioButtonsGroup':
            return {
                type: comp.type,
                label: comp.label || '',
                name: comp.name || '',
                required: !!comp.required,
                options: (comp['data-source'] || []).map(o => ({ id: o.id || '', title: o.title || '' })),
            };
        case 'Footer': {
            const ca = comp['on-click-action'] ?? {};
            return {
                type: 'Footer',
                label: comp.label || 'Continue',
                action: ca.name || 'complete',
                nextScreen: ca.next?.name || '',
            };
        }
        default: return null;
    }
};

const flattenChildren = (children) => {
    const result = [];
    for (const child of (children || [])) {
        if (child.type === 'Form') {
            for (const fc of (child.children || [])) result.push(mapPreviewComp(fc));
        } else {
            result.push(mapPreviewComp(child));
        }
    }
    return result.filter(Boolean);
};

/**
 * Convert a template's flowJson into the simplified screen array that
 * FlowPhonePreview expects.
 */
export const getPreviewScreens = (template) => {
    try {
        return (template.flowJson.screens || []).map(screen => ({
            id:         screen.id || 'SCREEN',
            title:      screen.title || '',
            terminal:   !!screen.terminal,
            components: flattenChildren(screen.layout?.children),
        }));
    } catch {
        return [];
    }
};

// ─── Template definitions ─────────────────────────────────────────────────────

export const META_FLOW_TEMPLATES = [

    // ── Default ──────────────────────────────────────────────────────────────
    {
        value: 'default',
        label: 'Default',
        suggestedCategory: 'OTHER',
        flowJson: {
            version: '6.3',
            screens: [
                {
                    id: 'WELCOME',
                    title: 'Welcome',
                    terminal: true,
                    layout: {
                        type: 'SingleColumnLayout',
                        children: [
                            { type: 'TextHeading', text: 'Hello World' },
                            { type: 'TextBody', text: "Let's start building your flow!" },
                            {
                                type: 'Footer',
                                label: 'Get Started',
                                'on-click-action': { name: 'complete', payload: {} },
                            },
                        ],
                    },
                },
            ],
        },
    },

    // ── Customer Feedback ────────────────────────────────────────────────────
    {
        value: 'customer_feedback',
        label: 'Customer feedback',
        suggestedCategory: 'SURVEY',
        flowJson: {
            version: '6.3',
            screens: [
                {
                    id: 'RATING',
                    title: 'Rate Us',
                    terminal: false,
                    layout: {
                        type: 'SingleColumnLayout',
                        children: [
                            { type: 'TextHeading', text: 'How was your experience?' },
                            { type: 'TextBody', text: 'Your feedback helps us improve our service.' },
                            {
                                type: 'Form',
                                name: 'rating_form',
                                children: [
                                    {
                                        type: 'RadioButtonsGroup',
                                        label: 'Overall Rating',
                                        name: 'rating',
                                        required: true,
                                        'data-source': [
                                            { id: '5', title: '⭐⭐⭐⭐⭐  Excellent' },
                                            { id: '4', title: '⭐⭐⭐⭐  Good' },
                                            { id: '3', title: '⭐⭐⭐  Average' },
                                            { id: '2', title: '⭐⭐  Poor' },
                                            { id: '1', title: '⭐  Very Poor' },
                                        ],
                                    },
                                    {
                                        type: 'Footer',
                                        label: 'Next',
                                        'on-click-action': {
                                            name: 'navigate',
                                            next: { type: 'screen', name: 'COMMENTS' },
                                            payload: { rating: '${form.rating}' },
                                        },
                                    },
                                ],
                            },
                        ],
                    },
                },
                {
                    id: 'COMMENTS',
                    title: 'Your Comments',
                    terminal: true,
                    layout: {
                        type: 'SingleColumnLayout',
                        children: [
                            { type: 'TextHeading', text: 'Tell us more' },
                            {
                                type: 'Form',
                                name: 'comments_form',
                                children: [
                                    {
                                        type: 'TextArea',
                                        label: 'Additional comments (optional)',
                                        name: 'comments',
                                        required: false,
                                    },
                                    {
                                        type: 'TextInput',
                                        label: 'Your email (optional)',
                                        name: 'email',
                                        'input-type': 'email',
                                        required: false,
                                    },
                                    {
                                        type: 'Footer',
                                        label: 'Submit Feedback',
                                        'on-click-action': { name: 'complete', payload: {} },
                                    },
                                ],
                            },
                        ],
                    },
                },
            ],
        },
    },

    // ── Follow-up ────────────────────────────────────────────────────────────
    {
        value: 'followup',
        label: 'Follow-up',
        suggestedCategory: 'OTHER',
        flowJson: {
            version: '6.3',
            screens: [
                {
                    id: 'FOLLOWUP',
                    title: 'Follow-up',
                    terminal: false,
                    layout: {
                        type: 'SingleColumnLayout',
                        children: [
                            { type: 'TextHeading', text: 'Quick Check-in' },
                            { type: 'TextBody', text: 'We hope everything went well. We would love to hear from you.' },
                            {
                                type: 'Form',
                                name: 'followup_form',
                                children: [
                                    {
                                        type: 'RadioButtonsGroup',
                                        label: 'Was your issue resolved?',
                                        name: 'resolved',
                                        required: true,
                                        'data-source': [
                                            { id: 'yes',     title: 'Yes, fully resolved' },
                                            { id: 'partial', title: 'Partially resolved' },
                                            { id: 'no',      title: 'No, still ongoing' },
                                        ],
                                    },
                                    {
                                        type: 'Footer',
                                        label: 'Next',
                                        'on-click-action': {
                                            name: 'navigate',
                                            next: { type: 'screen', name: 'DETAILS' },
                                            payload: { resolved: '${form.resolved}' },
                                        },
                                    },
                                ],
                            },
                        ],
                    },
                },
                {
                    id: 'DETAILS',
                    title: 'More Details',
                    terminal: true,
                    layout: {
                        type: 'SingleColumnLayout',
                        children: [
                            { type: 'TextHeading', text: 'A little more detail' },
                            {
                                type: 'Form',
                                name: 'details_form',
                                children: [
                                    {
                                        type: 'RadioButtonsGroup',
                                        label: 'How satisfied were you with our response time?',
                                        name: 'response_time',
                                        required: true,
                                        'data-source': [
                                            { id: 'very_fast', title: 'Very fast' },
                                            { id: 'fast',      title: 'Fast' },
                                            { id: 'slow',      title: 'Slow' },
                                            { id: 'very_slow', title: 'Very slow' },
                                        ],
                                    },
                                    {
                                        type: 'TextArea',
                                        label: 'Any additional feedback?',
                                        name: 'additional_feedback',
                                        required: false,
                                    },
                                    {
                                        type: 'Footer',
                                        label: 'Submit',
                                        'on-click-action': { name: 'complete', payload: {} },
                                    },
                                ],
                            },
                        ],
                    },
                },
            ],
        },
    },

    // ── Contact Us ───────────────────────────────────────────────────────────
    {
        value: 'contact_us',
        label: 'Contact us',
        suggestedCategory: 'CONTACT_US',
        flowJson: {
            version: '6.3',
            screens: [
                {
                    id: 'CONTACT',
                    title: 'Contact Us',
                    terminal: true,
                    layout: {
                        type: 'SingleColumnLayout',
                        children: [
                            { type: 'TextHeading', text: 'Get in Touch' },
                            { type: 'TextBody', text: 'Fill in the form below and we will get back to you as soon as possible.' },
                            {
                                type: 'Form',
                                name: 'contact_form',
                                children: [
                                    {
                                        type: 'TextInput',
                                        label: 'Full Name',
                                        name: 'full_name',
                                        'input-type': 'text',
                                        required: true,
                                    },
                                    {
                                        type: 'TextInput',
                                        label: 'Email Address',
                                        name: 'email',
                                        'input-type': 'email',
                                        required: true,
                                    },
                                    {
                                        type: 'Dropdown',
                                        label: 'Topic',
                                        name: 'topic',
                                        required: true,
                                        'data-source': [
                                            { id: 'sales',   title: 'Sales Enquiry' },
                                            { id: 'support', title: 'Technical Support' },
                                            { id: 'billing', title: 'Billing Question' },
                                            { id: 'other',   title: 'Other' },
                                        ],
                                    },
                                    {
                                        type: 'TextArea',
                                        label: 'Message',
                                        name: 'message',
                                        required: true,
                                    },
                                    {
                                        type: 'Footer',
                                        label: 'Send Message',
                                        'on-click-action': { name: 'complete', payload: {} },
                                    },
                                ],
                            },
                        ],
                    },
                },
            ],
        },
    },

    // ── Customer Satisfaction (CSAT) ─────────────────────────────────────────
    {
        value: 'csat',
        label: 'Customer satisfaction (CSAT)',
        suggestedCategory: 'SURVEY',
        flowJson: {
            version: '6.3',
            screens: [
                {
                    id: 'CSAT',
                    title: 'Satisfaction Survey',
                    terminal: true,
                    layout: {
                        type: 'SingleColumnLayout',
                        children: [
                            { type: 'TextHeading', text: 'How satisfied are you?' },
                            { type: 'TextBody', text: 'This takes less than a minute. Your response helps us serve you better.' },
                            {
                                type: 'Form',
                                name: 'csat_form',
                                children: [
                                    {
                                        type: 'RadioButtonsGroup',
                                        label: 'Overall satisfaction',
                                        name: 'satisfaction',
                                        required: true,
                                        'data-source': [
                                            { id: '5', title: '😄  Very Satisfied' },
                                            { id: '4', title: '🙂  Satisfied' },
                                            { id: '3', title: '😐  Neutral' },
                                            { id: '2', title: '😕  Dissatisfied' },
                                            { id: '1', title: '😞  Very Dissatisfied' },
                                        ],
                                    },
                                    {
                                        type: 'RadioButtonsGroup',
                                        label: 'Would you recommend us?',
                                        name: 'recommend',
                                        required: true,
                                        'data-source': [
                                            { id: 'definitely',    title: 'Definitely' },
                                            { id: 'probably',      title: 'Probably' },
                                            { id: 'not_sure',      title: 'Not sure' },
                                            { id: 'probably_not',  title: 'Probably not' },
                                        ],
                                    },
                                    {
                                        type: 'TextArea',
                                        label: 'What can we improve? (optional)',
                                        name: 'improvement',
                                        required: false,
                                    },
                                    {
                                        type: 'Footer',
                                        label: 'Submit Survey',
                                        'on-click-action': { name: 'complete', payload: {} },
                                    },
                                ],
                            },
                        ],
                    },
                },
            ],
        },
    },

    // ── Lead Generation ──────────────────────────────────────────────────────
    {
        value: 'lead_generation',
        label: 'Lead generation',
        suggestedCategory: 'LEAD_GENERATION',
        flowJson: {
            version: '6.3',
            screens: [
                {
                    id: 'LEAD_INFO',
                    title: 'Your Details',
                    terminal: false,
                    layout: {
                        type: 'SingleColumnLayout',
                        children: [
                            { type: 'TextHeading', text: "Let's Get Started" },
                            { type: 'TextBody', text: 'Tell us a little about yourself and we will be in touch shortly.' },
                            {
                                type: 'Form',
                                name: 'lead_form',
                                children: [
                                    {
                                        type: 'TextInput',
                                        label: 'Full Name',
                                        name: 'full_name',
                                        'input-type': 'text',
                                        required: true,
                                    },
                                    {
                                        type: 'TextInput',
                                        label: 'Email Address',
                                        name: 'email',
                                        'input-type': 'email',
                                        required: true,
                                    },
                                    {
                                        type: 'TextInput',
                                        label: 'Phone Number',
                                        name: 'phone',
                                        'input-type': 'phone',
                                        required: false,
                                    },
                                    {
                                        type: 'Footer',
                                        label: 'Next',
                                        'on-click-action': {
                                            name: 'navigate',
                                            next: { type: 'screen', name: 'INTEREST' },
                                            payload: {},
                                        },
                                    },
                                ],
                            },
                        ],
                    },
                },
                {
                    id: 'INTEREST',
                    title: 'Your Interest',
                    terminal: true,
                    layout: {
                        type: 'SingleColumnLayout',
                        children: [
                            { type: 'TextHeading', text: 'What are you interested in?' },
                            {
                                type: 'Form',
                                name: 'interest_form',
                                children: [
                                    {
                                        type: 'Dropdown',
                                        label: 'Service / Product',
                                        name: 'interest',
                                        required: true,
                                        'data-source': [
                                            { id: 'product_a', title: 'Product A' },
                                            { id: 'product_b', title: 'Product B' },
                                            { id: 'product_c', title: 'Product C' },
                                            { id: 'custom',    title: 'Custom Solution' },
                                        ],
                                    },
                                    {
                                        type: 'RadioButtonsGroup',
                                        label: 'Budget range',
                                        name: 'budget',
                                        required: false,
                                        'data-source': [
                                            { id: 'under_1k',  title: 'Under $1,000' },
                                            { id: '1k_5k',     title: '$1,000 – $5,000' },
                                            { id: '5k_20k',    title: '$5,000 – $20,000' },
                                            { id: 'over_20k',  title: 'Over $20,000' },
                                        ],
                                    },
                                    {
                                        type: 'Footer',
                                        label: 'Submit',
                                        'on-click-action': { name: 'complete', payload: {} },
                                    },
                                ],
                            },
                        ],
                    },
                },
            ],
        },
    },

    // ── Register for an Event ────────────────────────────────────────────────
    {
        value: 'register_event',
        label: 'Register for an event',
        suggestedCategory: 'OTHER',
        flowJson: {
            version: '6.3',
            screens: [
                {
                    id: 'CONTACT',
                    title: 'Your Details',
                    terminal: false,
                    layout: {
                        type: 'SingleColumnLayout',
                        children: [
                            { type: 'TextHeading', text: 'Event Registration' },
                            {
                                type: 'Form',
                                name: 'contact_form',
                                children: [
                                    {
                                        type: 'TextInput',
                                        label: 'Full Name',
                                        name: 'full_name',
                                        'input-type': 'text',
                                        required: true,
                                    },
                                    {
                                        type: 'TextInput',
                                        label: 'Email Address',
                                        name: 'email',
                                        'input-type': 'email',
                                        required: true,
                                    },
                                    {
                                        type: 'Footer',
                                        label: 'Next',
                                        'on-click-action': {
                                            name: 'navigate',
                                            next: { type: 'screen', name: 'EVENT' },
                                            payload: {},
                                        },
                                    },
                                ],
                            },
                        ],
                    },
                },
                {
                    id: 'EVENT',
                    title: 'Event Choice',
                    terminal: true,
                    layout: {
                        type: 'SingleColumnLayout',
                        children: [
                            { type: 'TextHeading', text: 'Choose Your Session' },
                            {
                                type: 'Form',
                                name: 'event_form',
                                children: [
                                    {
                                        type: 'Dropdown',
                                        label: 'Select Session',
                                        name: 'event_choice',
                                        required: true,
                                        'data-source': [
                                            { id: 'morning',   title: 'Morning Session (9 AM)' },
                                            { id: 'afternoon', title: 'Afternoon Session (2 PM)' },
                                            { id: 'evening',   title: 'Evening Session (6 PM)' },
                                        ],
                                    },
                                    {
                                        type: 'TextArea',
                                        label: 'Dietary requirements (optional)',
                                        name: 'dietary',
                                        required: false,
                                    },
                                    {
                                        type: 'Footer',
                                        label: 'Register',
                                        'on-click-action': { name: 'complete', payload: {} },
                                    },
                                ],
                            },
                        ],
                    },
                },
            ],
        },
    },

    // ── Complete Sign Up ─────────────────────────────────────────────────────
    {
        value: 'complete_signup',
        label: 'Complete sign up',
        suggestedCategory: 'SIGN_UP',
        flowJson: {
            version: '6.3',
            screens: [
                {
                    id: 'PERSONAL',
                    title: 'Personal Info',
                    terminal: false,
                    layout: {
                        type: 'SingleColumnLayout',
                        children: [
                            { type: 'TextHeading', text: 'Create Your Account' },
                            {
                                type: 'Form',
                                name: 'personal_form',
                                children: [
                                    {
                                        type: 'TextInput',
                                        label: 'First Name',
                                        name: 'first_name',
                                        'input-type': 'text',
                                        required: true,
                                    },
                                    {
                                        type: 'TextInput',
                                        label: 'Last Name',
                                        name: 'last_name',
                                        'input-type': 'text',
                                        required: true,
                                    },
                                    {
                                        type: 'TextInput',
                                        label: 'Email',
                                        name: 'email',
                                        'input-type': 'email',
                                        required: true,
                                    },
                                    {
                                        type: 'Footer',
                                        label: 'Next',
                                        'on-click-action': {
                                            name: 'navigate',
                                            next: { type: 'screen', name: 'PREFERENCES' },
                                            payload: {},
                                        },
                                    },
                                ],
                            },
                        ],
                    },
                },
                {
                    id: 'PREFERENCES',
                    title: 'Preferences',
                    terminal: true,
                    layout: {
                        type: 'SingleColumnLayout',
                        children: [
                            { type: 'TextHeading', text: 'Your Preferences' },
                            {
                                type: 'Form',
                                name: 'prefs_form',
                                children: [
                                    {
                                        type: 'CheckboxGroup',
                                        label: 'I am interested in',
                                        name: 'interests',
                                        required: false,
                                        'data-source': [
                                            { id: 'news',   title: 'News & Updates' },
                                            { id: 'offers', title: 'Special Offers' },
                                            { id: 'events', title: 'Events' },
                                        ],
                                    },
                                    {
                                        type: 'Footer',
                                        label: 'Sign Up',
                                        'on-click-action': { name: 'complete', payload: {} },
                                    },
                                ],
                            },
                        ],
                    },
                },
            ],
        },
    },

    // ── Get Support ──────────────────────────────────────────────────────────
    {
        value: 'get_support',
        label: 'Get support',
        suggestedCategory: 'CUSTOMER_SUPPORT',
        flowJson: {
            version: '6.3',
            screens: [
                {
                    id: 'ISSUE',
                    title: 'Support Request',
                    terminal: false,
                    layout: {
                        type: 'SingleColumnLayout',
                        children: [
                            { type: 'TextHeading', text: 'How can we help?' },
                            {
                                type: 'Form',
                                name: 'issue_form',
                                children: [
                                    {
                                        type: 'Dropdown',
                                        label: 'Issue Category',
                                        name: 'category',
                                        required: true,
                                        'data-source': [
                                            { id: 'technical', title: 'Technical Issue' },
                                            { id: 'billing',   title: 'Billing Question' },
                                            { id: 'feature',   title: 'Feature Request' },
                                            { id: 'other',     title: 'Other' },
                                        ],
                                    },
                                    {
                                        type: 'TextArea',
                                        label: 'Describe your issue',
                                        name: 'description',
                                        required: true,
                                    },
                                    {
                                        type: 'Footer',
                                        label: 'Next',
                                        'on-click-action': {
                                            name: 'navigate',
                                            next: { type: 'screen', name: 'CONTACT' },
                                            payload: {},
                                        },
                                    },
                                ],
                            },
                        ],
                    },
                },
                {
                    id: 'CONTACT',
                    title: 'Contact Info',
                    terminal: true,
                    layout: {
                        type: 'SingleColumnLayout',
                        children: [
                            { type: 'TextHeading', text: 'Contact Details' },
                            {
                                type: 'Form',
                                name: 'contact_form',
                                children: [
                                    {
                                        type: 'TextInput',
                                        label: 'Your Email',
                                        name: 'email',
                                        'input-type': 'email',
                                        required: true,
                                    },
                                    {
                                        type: 'Dropdown',
                                        label: 'Urgency',
                                        name: 'urgency',
                                        required: true,
                                        'data-source': [
                                            { id: 'low',    title: 'Low — Can wait a few days' },
                                            { id: 'medium', title: 'Medium — Need help soon' },
                                            { id: 'high',   title: 'High — Urgent' },
                                        ],
                                    },
                                    {
                                        type: 'Footer',
                                        label: 'Submit',
                                        'on-click-action': { name: 'complete', payload: {} },
                                    },
                                ],
                            },
                        ],
                    },
                },
            ],
        },
    },

    // ── Complete a Quiz ──────────────────────────────────────────────────────
    {
        value: 'complete_quiz',
        label: 'Complete a quiz',
        suggestedCategory: 'SURVEY',
        flowJson: {
            version: '6.3',
            screens: [
                {
                    id: 'Q1',
                    title: 'Question 1',
                    terminal: false,
                    layout: {
                        type: 'SingleColumnLayout',
                        children: [
                            { type: 'TextHeading',    text: 'Quick Quiz' },
                            { type: 'TextSubheading', text: 'Question 1 of 2' },
                            {
                                type: 'Form',
                                name: 'q1_form',
                                children: [
                                    {
                                        type: 'RadioButtonsGroup',
                                        label: 'Which best describes you?',
                                        name: 'q1',
                                        required: true,
                                        'data-source': [
                                            { id: 'student',      title: 'Student' },
                                            { id: 'professional', title: 'Working Professional' },
                                            { id: 'business',     title: 'Business Owner' },
                                            { id: 'other',        title: 'Other' },
                                        ],
                                    },
                                    {
                                        type: 'Footer',
                                        label: 'Next',
                                        'on-click-action': {
                                            name: 'navigate',
                                            next: { type: 'screen', name: 'Q2' },
                                            payload: { q1: '${form.q1}' },
                                        },
                                    },
                                ],
                            },
                        ],
                    },
                },
                {
                    id: 'Q2',
                    title: 'Question 2',
                    terminal: true,
                    layout: {
                        type: 'SingleColumnLayout',
                        children: [
                            { type: 'TextSubheading', text: 'Question 2 of 2' },
                            {
                                type: 'Form',
                                name: 'q2_form',
                                children: [
                                    {
                                        type: 'Dropdown',
                                        label: 'How did you hear about us?',
                                        name: 'q2',
                                        required: true,
                                        'data-source': [
                                            { id: 'social',  title: 'Social Media' },
                                            { id: 'friend',  title: 'Friend / Family' },
                                            { id: 'search',  title: 'Search Engine' },
                                            { id: 'ad',      title: 'Advertisement' },
                                        ],
                                    },
                                    {
                                        type: 'Footer',
                                        label: 'Submit',
                                        'on-click-action': { name: 'complete', payload: {} },
                                    },
                                ],
                            },
                        ],
                    },
                },
            ],
        },
    },

    // ── Update Preferences ───────────────────────────────────────────────────
    {
        value: 'update_preferences',
        label: 'Update preferences',
        suggestedCategory: 'OTHER',
        flowJson: {
            version: '6.3',
            screens: [
                {
                    id: 'PREFERENCES',
                    title: 'Update Preferences',
                    terminal: true,
                    layout: {
                        type: 'SingleColumnLayout',
                        children: [
                            { type: 'TextHeading', text: 'Update Your Preferences' },
                            {
                                type: 'Form',
                                name: 'prefs_form',
                                children: [
                                    {
                                        type: 'CheckboxGroup',
                                        label: 'Communication Preferences',
                                        name: 'prefs',
                                        required: false,
                                        'data-source': [
                                            { id: 'email',      title: 'Email Updates' },
                                            { id: 'sms',        title: 'SMS Notifications' },
                                            { id: 'whatsapp',   title: 'WhatsApp Messages' },
                                            { id: 'promotions', title: 'Promotional Offers' },
                                        ],
                                    },
                                    {
                                        type: 'Dropdown',
                                        label: 'Preferred Language',
                                        name: 'language',
                                        required: true,
                                        'data-source': [
                                            { id: 'en', title: 'English' },
                                            { id: 'ar', title: 'Arabic' },
                                            { id: 'fr', title: 'French' },
                                        ],
                                    },
                                    {
                                        type: 'Footer',
                                        label: 'Save Preferences',
                                        'on-click-action': { name: 'complete', payload: {} },
                                    },
                                ],
                            },
                        ],
                    },
                },
            ],
        },
    },
];
