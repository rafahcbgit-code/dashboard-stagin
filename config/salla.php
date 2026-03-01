<?php
return[
    'client_id' => '28bce576-7c26-4526-ab0b-2da13ddafe33',
    'client_secret' => '56f2405e9fe53cbfba2dadb81e6b824a4caed5407101d340cd2d047eef163469',
    'auth_url' => 'https://accounts.salla.sa/oauth2/auth',
    'token_url' => 'https://accounts.salla.sa/oauth2/token',
    'redirect' => 'http://localhost:8000/salla/public/auth/callback', // تغير وقت النقل ل سيرفر
    'callback_url' => 'http://localhost:8000/salla/public/auth/callback', // للتوافق مع الكود القديم
    'salla-api-url' => 'https://api.salla.dev/admin/v2',

    // Temporary: process Salla webhooks synchronously (bypass queue)
    'webhooks' => [
        'sync' => env('SALLA_WEBHOOK_SYNC', false),
    ],
];
