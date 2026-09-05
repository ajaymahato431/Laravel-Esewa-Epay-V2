<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | "uat" targets eSewa's sandbox; "production" targets live money.
    |
    */

    'mode' => env('ESEWA_MODE', 'uat'),

    /*
    |--------------------------------------------------------------------------
    | Merchant credentials
    |--------------------------------------------------------------------------
    |
    | The secret key has no default on purpose. A wrong key produces signatures
    | eSewa silently rejects, and a shared default would mean signing production
    | traffic with a key published in eSewa's own documentation - so the package
    | throws instead of guessing.
    |
    | UAT sandbox values: product code "EPAYTEST", secret "8gBm/:&EnhH.1/q".
    |
    */

    'product_code' => env('ESEWA_PRODUCT_CODE', 'EPAYTEST'),

    'secret_key' => env('ESEWA_SECRET_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Where customers end up
    |--------------------------------------------------------------------------
    |
    | Defaults used when a payment does not specify its own success/failure URL.
    | Relative paths are recommended.
    |
    | Only relative paths, this application's own host, and hosts listed in
    | "allowed_hosts" are ever used as redirect targets. Anything else is
    | discarded and logged, because these routes are public and an unchecked
    | redirect parameter would let anyone bounce your customers off-site at the
    | exact moment they most trust your domain.
    |
    */

    'redirect' => [
        'success' => env('ESEWA_SUCCESS_URL'),
        'failure' => env('ESEWA_FAILURE_URL'),

        // e.g. ['checkout.example.com', '*.example.com']
        'allowed_hosts' => array_values(array_filter(
            explode(',', (string) env('ESEWA_ALLOWED_REDIRECT_HOSTS', ''))
        )),
    ],

    /*
    |--------------------------------------------------------------------------
    | Package routes
    |--------------------------------------------------------------------------
    |
    | Registers {prefix}/callback and {prefix}/relay/{transaction?}. Turn this
    | off to define your own routes and call Esewa::handleCallback() yourself.
    |
    | There is no route that starts a payment: choosing an amount is your
    | application's decision, so Esewa::pay() is called from your own controller.
    |
    */

    'routes' => [
        'enabled' => (bool) env('ESEWA_ROUTES_ENABLED', true),
        'prefix' => env('ESEWA_ROUTE_PREFIX', 'esewa'),
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    'database' => [
        'connection' => env('ESEWA_DB_CONNECTION'),
        'table' => env('ESEWA_DB_TABLE', 'esewa_payments'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | Browser callbacks get lost - customers close tabs and lose signal. When
    | auto_dispatch is on, every payment queues a delayed job that asks eSewa
    | what actually happened.
    |
    | It is off by default because the "sync" queue driver ignores delays and
    | would run the check inline, in the middle of the redirect to eSewa. Turn it
    | on once you have a real queue worker, and schedule "esewa:reconcile" as a
    | backstop either way.
    |
    */

    'reconciliation' => [
        'auto_dispatch' => (bool) env('ESEWA_AUTO_RECONCILE', false),
        'delay' => (int) env('ESEWA_RECONCILE_DELAY', 10), // minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateway endpoints
    |--------------------------------------------------------------------------
    |
    | @see https://developer.esewa.com.np/pages/Epay
    |
    */

    'endpoints' => [
        'uat' => [
            'form' => 'https://rc-epay.esewa.com.np/api/epay/main/v2/form',
            'status_check' => 'https://rc.esewa.com.np/api/epay/transaction/status/',
        ],
        'production' => [
            'form' => 'https://epay.esewa.com.np/api/epay/main/v2/form',
            'status_check' => 'https://esewa.com.np/api/epay/transaction/status/',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP client
    |--------------------------------------------------------------------------
    |
    | Applies to status checks. Retries matter here: a transient network blip
    | should not leave a paid order marked unpaid.
    |
    */

    'http' => [
        'timeout' => (int) env('ESEWA_HTTP_TIMEOUT', 30),
        'retry' => [
            'times' => (int) env('ESEWA_HTTP_RETRY_TIMES', 2),
            'sleep' => (int) env('ESEWA_HTTP_RETRY_SLEEP', 250), // milliseconds
        ],
    ],

];
