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

    'batch_size' => env('CAMPAIGN_BATCH_SIZE', 500),

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

];
