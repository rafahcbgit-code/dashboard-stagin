<?php

return [
    'provider' => env('CHATBOT_STUDIO_AI_PROVIDER', 'gemini'),
    'deepseek_api_key' => env('CHATBOT_STUDIO_DEEPSEEK_API_KEY', ''),
    'paid_usage_confirmed' => (bool) env('CHATBOT_STUDIO_PAID_USAGE_CONFIRMED', false),
    'deepseek_allowed_models' => ['deepseek-flash', 'deepseek-v4-pro'],
    // Use a dedicated Free Tier project with Cloud Billing disabled.
    'api_key' => env('CHATBOT_STUDIO_GEMINI_API_KEY', ''),
    'free_tier_confirmed' => (bool) env('CHATBOT_STUDIO_FREE_TIER_CONFIRMED', false),
    'model' => env('CHATBOT_STUDIO_AI_MODEL', env('CHATBOT_STUDIO_AI_PROVIDER', 'gemini') === 'deepseek' ? 'deepseek-flash' : 'gemini-3.8-flash'),
    // Text generation only; media, agent and embedding endpoints are not blueprint generators.
    'allowed_models' => [
        'gemini-3.8-flash',
        'gemini-3.7-flash',
        'gemini-3.6-flash',
        'gemini-3.5-flash',
        'gemini-3.5-flash-lite',
        'gemini-3.1-flash-lite',
        'gemini-3-flash-preview',
        'gemini-2.5-flash',
        'gemini-2.5-flash-lite',
        // Hosted Gemma is opt-in; full blueprint generation still needs project-specific verification.
        'gemma-4-26b-a4b-it',
        'gemma-4-31b-it',
    ],
    'output_tokens' => (int) env('CHATBOT_STUDIO_OUTPUT_TOKENS', 24000),
    'request_timeout' => (int) env('CHATBOT_STUDIO_REQUEST_TIMEOUT', 240),
];
