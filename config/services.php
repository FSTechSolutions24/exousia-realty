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
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'paymob' => [
        'api_base' => env('PAYMOB_API_BASE', 'https://accept.paymob.com'),
        'checkout_base' => env('PAYMOB_CHECKOUT_BASE', 'https://accept.paymob.com/unifiedcheckout'),
        'secret_key' => env('PAYMOB_SECRET_KEY', env('PAYMOB_API_KEY')),
        'public_key' => env('PAYMOB_PUBLIC_KEY'),
        'hmac_secret' => env('PAYMOB_HMAC_SECRET', env('PAYMOB_HMAC')),
        'iframe_id' => env('PAYMOB_IFRAME_ID'),
        'payment_methods' => array_values(array_filter(array_map('intval', explode(',', (string) env('PAYMOB_PAYMENT_METHODS', env('PAYMOB_INTEGRATION_ID', '')))))),
    ],

];
