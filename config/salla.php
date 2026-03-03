<?php
return [
    //'client_id' => '091ace69-e29f-471e-90f2-77d59b47af95', //test app
    //'client_secret' => 'b3bb7d077073ec4410ea9e68dc864358bcb54079d823b052647cd42cd5870f3b', //test app
    'client_id' => env('SALLA_CLIENT_ID'), //communicatiom live app
    'client_secret' => env('SALLA_CLIENT_SECRET'),  //communicatiom live app
    'auth_url' => 'https://accounts.salla.sa/oauth2/auth',
    'token_url' => 'https://accounts.salla.sa/oauth2/token',
    'redirect' => 'https://dashboard.chatberry.net/salla/public/auth/callback',
    'callback_url' => 'https://dashboard.chatberry.net/salla/public/auth/callback',
    'salla-api-url' => 'https://api.salla.dev/admin/v2',

    // Temporary: process Salla webhooks synchronously (bypass queue)
    'webhooks' => [
        'sync' => env('SALLA_WEBHOOK_SYNC', false),
    ],
];
