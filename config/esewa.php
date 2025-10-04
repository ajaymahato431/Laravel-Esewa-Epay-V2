<?php

return [
    'mode' => env('ESEWA_MODE', 'uat'), // 'uat' or 'production'

    'product_code' => env('ESEWA_PRODUCT_CODE', 'EPAYTEST'),
    'secret_key'   => env('ESEWA_SECRET_KEY', '8gBm/:&EnhH.1/q'), // replace in prod

    'success_url'  => env('ESEWA_SUCCESS_URL', 'https://your-app.com/esewa/success'),
    'failure_url'  => env('ESEWA_FAILURE_URL', 'https://your-app.com/esewa/failure'),

    // Optional routing/middleware knobs (package-owned routes)
    'route_prefix' => env('ESEWA_ROUTE_PREFIX', ''),
    'middleware'   => ['web'],

    'endpoints' => [
        'uat' => [
            'form'         => 'https://rc-epay.esewa.com.np/api/epay/main/v2/form',
            'status_check' => 'https://rc.esewa.com.np/api/epay/transaction/status/',
        ],
        'production' => [
            'form'         => 'https://epay.esewa.com.np/api/epay/main/v2/form',
            'status_check' => 'https://epay.esewa.com.np/api/epay/transaction/status/',
        ],
    ],

    'http' => [
        'timeout' => 10,
    ],
];
