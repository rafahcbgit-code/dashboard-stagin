<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Service Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the AI service used across the platform for intent
    | classification, fallback responses, and AI-powered matching.
    | These settings are managed by super admins and are global (not per-org).
    |
    */

    'endpoint' => env('OLLAMA_ENDPOINT', 'http://localhost:11434'),
    'api_key' => env('OLLAMA_API_KEY', ''),
    'model' => env('OLLAMA_MODEL', env('AI_DEFAULT_MODEL', 'gemma3:4b')),
    'timeout' => env('OLLAMA_TIMEOUT', 60),
    'max_tokens' => env('OLLAMA_MAX_TOKENS', 500),
    'temperature' => env('AI_TEMPERATURE', 0.30),
    'context_window' => env('AI_CONTEXT_WINDOW', 10),

    /*
    |--------------------------------------------------------------------------
    | Intent Classification Settings
    |--------------------------------------------------------------------------
    |
    | Default settings for intent classification via AI. The confidence
    | threshold determines how confident the AI must be to consider a match.
    | Individual trigger groups can override this threshold.
    |
    */

    'intent_classification' => [
        'default_confidence_threshold' => 75,
        'max_retries' => 1,
        'retry_delay_ms' => 500,
    ],
];