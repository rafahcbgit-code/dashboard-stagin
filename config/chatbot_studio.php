<?php

return [
    // Use a dedicated Free Tier project with Cloud Billing disabled.
    'api_key' => env('CHATBOT_STUDIO_GEMINI_API_KEY', ''),
    'free_tier_confirmed' => (bool) env('CHATBOT_STUDIO_FREE_TIER_CONFIRMED', false),
    'model' => env('CHATBOT_STUDIO_AI_MODEL', 'gemini-3.8-flash'),
    'allowed_models' => ['gemini-3.8-flash', 'gemini-2.5-flash'],
    'output_tokens' => (int) env('CHATBOT_STUDIO_OUTPUT_TOKENS', 24000),
    'request_timeout' => (int) env('CHATBOT_STUDIO_REQUEST_TIMEOUT', 240),
];
