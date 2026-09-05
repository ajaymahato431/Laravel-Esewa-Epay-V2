<?php

use AjayMahato\Esewa\EsewaClient;
use AjayMahato\Esewa\Exceptions\EsewaConfigurationException;
use AjayMahato\Esewa\Exceptions\EsewaException;

function payloadParams(array $overrides = []): array
{
    return array_merge([
        'amount' => 1000,
        'transaction_uuid' => 'ORDER-1',
        'success_url' => 'https://shop.test/esewa/relay',
        'failure_url' => 'https://shop.test/esewa/relay',
    ], $overrides);
}

it('builds the field set eSewa documents', function () {
    $payload = esewaClient()->buildFormPayload(payloadParams([
        'amount' => 1000,
        'tax_amount' => 130,
        'product_service_charge' => 0,
        'product_delivery_charge' => 70,
        'total_amount' => 1200,
    ]));

    expect(array_keys($payload))->toEqualCanonicalizing([
        'amount', 'tax_amount', 'product_service_charge', 'product_delivery_charge',
        'total_amount', 'transaction_uuid', 'product_code', 'success_url', 'failure_url',
        'signed_field_names', 'signature',
    ])->and($payload['total_amount'])->toBe('1200.00');
});

it('defaults the total to the sum of the parts', function () {
    $payload = esewaClient()->buildFormPayload(payloadParams([
        'amount' => '1000.50',
        'tax_amount' => '130.25',
        'product_delivery_charge' => '69.25',
    ]));

    expect($payload['total_amount'])->toBe('1200.00');
});

it('refuses a total that does not equal the parts', function () {
    // eSewa rejects this server-side; failing here gives a usable error instead
    // of a blank gateway page.
    esewaClient()->buildFormPayload(payloadParams([
        'amount' => 1000,
        'tax_amount' => 100,
        'total_amount' => 1000,
    ]));
})->throws(EsewaException::class, 'total_amount to equal');

it('refuses a zero or negative charge', function () {
    esewaClient()->buildFormPayload(payloadParams(['amount' => 0]));
})->throws(EsewaException::class, 'greater than zero');

it('rejects transaction ids eSewa cannot accept', function (string $uuid) {
    // eSewa allows only letters, digits and hyphens, and fails the payment
    // silently on anything else.
    esewaClient()->buildFormPayload(payloadParams(['transaction_uuid' => $uuid]));
})->with([
    'underscore' => 'ORDER_1',
    'space' => 'ORDER 1',
    'slash' => 'ORDER/1',
    'empty' => '',
])->throws(EsewaException::class);

it('accepts the transaction id format the package generates', function () {
    $uuid = app(\AjayMahato\Esewa\PaymentManager::class)->generateTransactionUuid();

    expect(esewaClient()->buildFormPayload(payloadParams(['transaction_uuid' => $uuid])))
        ->toHaveKey('signature');
});

it('requires the redirect urls', function () {
    esewaClient()->buildFormPayload(['amount' => 100, 'transaction_uuid' => 'ORDER-1']);
})->throws(EsewaException::class, 'success_url');

/*
|--------------------------------------------------------------------------
| Configuration guards
|--------------------------------------------------------------------------
*/

it('refuses to sign without a secret key', function () {
    esewaClient(['secret_key' => null])->buildRequestSignature('100', 'ORDER-1');
})->throws(EsewaConfigurationException::class, 'ESEWA_SECRET_KEY');

it('refuses to sign production traffic with the published sandbox key', function () {
    // The UAT key is printed in eSewa's public documentation. Using it live
    // means anyone can forge a callback.
    esewaClient(['mode' => 'production', 'secret_key' => EsewaClient::UAT_SECRET_KEY])
        ->buildRequestSignature('100', 'ORDER-1');
})->throws(EsewaConfigurationException::class, 'before going live');

it('accepts a real production key', function () {
    expect(esewaClient(['mode' => 'production', 'secret_key' => 'live-secret'])->formEndpoint())
        ->toBe('https://epay.esewa.com.np/api/epay/main/v2/form');
});

it('uses the documented production status endpoint', function () {
    expect(esewaClient(['mode' => 'production', 'secret_key' => 'live'])->statusEndpoint())
        ->toBe('https://esewa.com.np/api/epay/transaction/status/');
});

it('rejects an unknown mode instead of falling back silently', function () {
    esewaClient(['mode' => 'staging'])->formEndpoint();
})->throws(EsewaConfigurationException::class, 'Unknown eSewa mode');
