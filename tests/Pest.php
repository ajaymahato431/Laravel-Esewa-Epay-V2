<?php

use AjayMahato\Esewa\EsewaClient;
use AjayMahato\Esewa\Enums\PaymentStatus;
use AjayMahato\Esewa\Models\EsewaPayment;
use AjayMahato\Esewa\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');

/**
 * A client wired to the documented UAT credentials.
 *
 * @param  array<string, mixed>  $overrides
 */
function esewaClient(array $overrides = []): EsewaClient
{
    return new EsewaClient(array_replace_recursive([
        'mode' => 'uat',
        'product_code' => 'EPAYTEST',
        'secret_key' => EsewaClient::UAT_SECRET_KEY,
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
        'http' => ['timeout' => 5, 'retry' => ['times' => 0, 'sleep' => 0]],
    ], $overrides));
}

/**
 * Build a signed callback payload the way eSewa would.
 *
 * @param  array<string, mixed>  $overrides
 */
function esewaCallbackPayload(array $overrides = []): string
{
    return esewaClient()->signedCallbackPayload($overrides);
}

/**
 * Persist a payment ready to receive a callback.
 *
 * @param  array<string, mixed>  $attributes
 */
function esewaPayment(array $attributes = []): EsewaPayment
{
    return EsewaPayment::query()->create(array_merge([
        'transaction_uuid' => 'TEST-'.strtoupper(bin2hex(random_bytes(4))),
        'product_code' => 'EPAYTEST',
        'amount' => '1000.00',
        'tax_amount' => '0.00',
        'service_charge' => '0.00',
        'delivery_charge' => '0.00',
        'total_amount' => '1000.00',
        'status' => PaymentStatus::PENDING,
    ], $attributes));
}
