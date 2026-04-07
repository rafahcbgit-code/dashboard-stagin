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
];
