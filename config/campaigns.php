<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Campaign Batch Processing
    |--------------------------------------------------------------------------
    |
    | Default batch size for processing campaign logs. This controls how many
    | campaign log records are processed in each chunk during campaign execution.
    |
    | Can be overridden per organization via metadata.campaigns.batch_size
    |
    */

    'batch_size' => env('CAMPAIGN_BATCH_SIZE', 1000),

    /*
    |--------------------------------------------------------------------------
    | Maximum Batch Size
    |--------------------------------------------------------------------------
    |
    | Safety limit for batch size to prevent memory issues. Organization-level
    | overrides cannot exceed this value. The system will cap any configured
    | batch size at this maximum.
    |
    */

    'max_batch_size' => env('CAMPAIGN_MAX_BATCH_SIZE', 1000),

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for exponential backoff retries on failed campaign messages.
    |
    | default_intervals: Array of wait times (in minutes) between retry attempts.
    |                    Example: [5, 15, 60] = first retry after 5 min,
    |                    second after 15 min, third after 60 min.
    |
    | max_retries: Maximum number of retry attempts (derived from intervals count
    |              if not explicitly set). After this, message is marked as
    |              'permanently_failed'.
    |
    | log_permanent_failures: Whether to log permanently failed messages at
    |                         warning level for review.
    |
    | Can be overridden per organization via metadata.campaigns.resend_intervals
    |
    */

    'retry' => [
        'default_intervals' => array_map('intval', explode(',', env('CAMPAIGN_RETRY_INTERVALS', '5,15,60'))),
        'max_retries' => (int) env('CAMPAIGN_MAX_RETRIES', 3),
        'log_permanent_failures' => env('CAMPAIGN_LOG_PERMANENT_FAILURES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunk Processing Configuration
    |--------------------------------------------------------------------------
    |
    | chunk_size: Number of messages processed per ProcessCampaignChunkJob.
    |             Each chunk sends messages concurrently via Guzzle Pool.
    |
    | concurrency: Max concurrent HTTP requests per chunk job.
    |              Controls parallelism of WhatsApp API calls.
    |              Start with 5-10 and increase if rate limits allow.
    |
    | load_test_mode: When true, skip Meta API calls and return fake success
    |                 responses. Used for benchmarking without hitting the API.
    |
    */

    'chunk_size' => env('CAMPAIGN_CHUNK_SIZE', 100),

    'concurrency' => env('CAMPAIGN_CONCURRENCY', 10),

    /*
    |--------------------------------------------------------------------------
    | Request Delay (milliseconds)
    |--------------------------------------------------------------------------
    |
    | Milliseconds to pause between each API request within a chunk job.
    | This spaces out sends to avoid hitting Meta's rate limits (130429).
    |
    | At concurrency=2 with delay=100ms: effective rate ~10 req/sec
    | At concurrency=5 with delay=200ms: effective rate ~5 req/sec
    | At concurrency=5 with delay=0: no throttling, burst as fast as possible
    |
    */

    'request_delay_ms' => env('CAMPAIGN_REQUEST_DELAY_MS', 100),

    'load_test_mode' => env('CAMPAIGN_LOAD_TEST_MODE', false),

    'success_log_sample_rate' => (float) env('CAMPAIGN_SUCCESS_LOG_SAMPLE_RATE', 0.01),

    'throttle' => [
        'enabled' => env('CAMPAIGN_THROTTLE_ENABLED', true),
        'default_phone_mps' => (int) env('CAMPAIGN_DEFAULT_PHONE_MPS', 20),
        'max_phone_mps' => (int) env('CAMPAIGN_MAX_PHONE_MPS', 80),
        'rate_limit_backoff_seconds' => (int) env('CAMPAIGN_RATE_LIMIT_BACKOFF_SECONDS', 120),
    ],

    'dispatch' => [
        'wave_size' => (int) env('CAMPAIGN_DISPATCH_WAVE_SIZE', 2100),
        'per_campaign_wave_size' => (int) env('CAMPAIGN_DISPATCH_PER_CAMPAIGN_WAVE_SIZE', 700),
        'redispatch_delay_seconds' => (int) env('CAMPAIGN_REDISPATCH_DELAY_SECONDS', 5),
        'lock_seconds' => (int) env('CAMPAIGN_DISPATCH_LOCK_SECONDS', 30),
        'reservation_ttl_seconds' => (int) env('CAMPAIGN_DISPATCH_RESERVATION_TTL_SECONDS', 900),
    ],

    'queues' => [
        'logs' => env('CAMPAIGN_LOGS_QUEUE', 'campaign-logs'),
        'dispatchers' => env('CAMPAIGN_DISPATCHERS_QUEUE', 'campaign-dispatchers'),
        'messages' => env('CAMPAIGN_MESSAGES_QUEUE', 'campaign-messages'),
        'post_send' => env('CAMPAIGN_POST_SEND_QUEUE', 'campaign-post-send'),
    ],

];
