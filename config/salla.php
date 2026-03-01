<?php
return [
    //'client_id' => '28bce576-7c26-4526-ab0b-2da13ddafe33', //test app
    //'client_secret' => '56f2405e9fe53cbfba2dadb81e6b824a4caed5407101d340cd2d047eef163469', //test app
    'client_id' => env('SALLA_CLIENT_ID', 'c79af04b-ca12-4d74-9fb3-2142d5c9f531'), //live app
    'client_secret' => env('SALLA_CLIENT_ID', '79ddb0c8ee3da7ea0215d496a2633e1e76ac3fcae0b8de1167e2346866a4f91c'),  //live app
    'auth_url' => 'https://accounts.salla.sa/oauth2/auth',
    'token_url' => 'https://accounts.salla.sa/oauth2/token',
    'redirect' => 'http://localhost:8000/salla/public/auth/callback',
    'callback_url' => 'http://localhost:8000/salla/public/auth/callback',
    'salla-api-url' => 'https://api.salla.dev/admin/v2',

    // Temporary: process Salla webhooks synchronously (bypass queue)
    'webhooks' => [
        'sync' => env('SALLA_WEBHOOK_SYNC', false),
    ],
];
