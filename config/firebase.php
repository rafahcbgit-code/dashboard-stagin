<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase credentials
    |--------------------------------------------------------------------------
    |
    | Keep environment access inside configuration files so these values are
    | available when Laravel's configuration is cached in production.
    |
    */

    'credentials' => env('FIREBASE_CREDENTIALS'),

    'credentials_json' => env('FIREBASE_CREDENTIALS_JSON'),

];
