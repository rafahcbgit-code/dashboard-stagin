<?php

use Illuminate\Support\Str;

return [
    /*
    |--------------------------------------------------------------------------
    | Zid OAuth Configuration
    |--------------------------------------------------------------------------
    |
    | These values are used during the Zid OAuth2 Authorization Code flow.
    | The client credentials and redirect URI should be stored securely and
    | never exposed to the browser.
    |
    */
    'client_id' => env('ZID_CLIENT_ID', ''),
    'client_secret' => env('ZID_CLIENT_SECRET', ''),
    'redirect_uri' => env('ZID_REDIRECT_URI', ''),
    'auth_url' => env('ZID_AUTH_URL', 'https://oauth.zid.sa/oauth/authorize'),
    'token_url' => env('ZID_TOKEN_URL', 'https://oauth.zid.sa/oauth/token'),
    'manager_profile_url' => env('ZID_MANAGER_PROFILE_URL', 'https://api.zid.sa/v1/managers/account/profile'),
    'scopes' => env('ZID_SCOPES', 'read write'),
    'platform' => 'zid',
    'webhook_target' => env('ZID_WEBHOOK_TARGET', env('APP_URL') . '/zid/webhooks'),
    'webhook_events' => [
        'order.create' => ['supports_conditions' => true],
        'order.status.update' => ['supports_conditions' => true],
        'abandoned.card' => ['supports_conditions' => false],
        'customer.login' => ['supports_conditions' => false],
    ],
    'webhook_condition_keys' => [
        'delivery_option_id',
        'status',
        'payment_method',
    ],
    'webhook_conditions' => [],
];
