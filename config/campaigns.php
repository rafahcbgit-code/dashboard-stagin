<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Campaign Batch Processing (Story 2.3)
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

];
