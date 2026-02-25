<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
    ],

    'paypal' => [
        'class' => App\Services\PayPalService::class,
    ],

    'stripe' => [
        'class' => App\Services\StripeService::class,
    ],

    'paystack' => [
        'class' => App\Services\PayStackService::class,
    ],

    'flutterwave' => [
        'class' => App\Services\FlutterwaveService::class,
    ],

    'clickpay' => [
        'class' => Modules\Clickpaysa\Controllers\ProcessPayment::class,
    ],

    'razorpay' => [
        'class' => Modules\Razorpay\Controllers\ProcessPayment::class,
    ],

    'pabbly subscriptions' => [
        'class' => Modules\Pabbly\Controllers\ProcessPayment::class,
    ],

    'google_sheets' => [
        'client_id'     => env('GOOGLE_SHEETS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_SHEETS_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_SHEETS_REDIRECT_URI', '/automation/meta-flows/google/callback'),
    ],

    // Socialite Google driver — used by GoogleOAuthController for OAuth 2.0
    'google' => [
        'client_id'     => env('GOOGLE_SHEETS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_SHEETS_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_SHEETS_REDIRECT_URI', '/automation/meta-flows/google/callback'),
    ],
];


