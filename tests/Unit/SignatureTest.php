<?php

use AjayMahato\Esewa\Exceptions\SignatureVerificationException;
use AjayMahato\Esewa\Support\CallbackPayload;

/*
|--------------------------------------------------------------------------
| Vectors published by eSewa
|--------------------------------------------------------------------------
|
| https://developer.esewa.com.np/pages/Epay
|
| These are the closest thing to a conformance suite the gateway offers. If
| either stops matching, the package is signing something eSewa will reject.
|
*/

it('reproduces the request signature published by eSewa', function () {
    // The documented message, signed verbatim.
    expect(esewaClient()->sign('total_amount=110,transaction_uuid=241028,product_code=EPAYTEST'))
        ->toBe('i94zsd3oXF6ZsSr/kGqT4sSzYQzjj1W/waxjWyRwaME=');
});

it('signs exactly the values it submits', function () {
    // What matters in practice is not matching a documentation string but that
    // the signature covers the same text as the form fields: eSewa recomputes
    // the HMAC from what we post, so any divergence is a rejected payment.
    $payload = esewaClient()->buildFormPayload([
        'amount' => 110,
        'transaction_uuid' => '241028',
        'success_url' => 'https://shop.test/esewa/relay',
        'failure_url' => 'https://shop.test/esewa/relay',
    ]);

    $signed = sprintf(
        'total_amount=%s,transaction_uuid=%s,product_code=%s',
        $payload['total_amount'],
        $payload['transaction_uuid'],
        $payload['product_code'],
    );

    expect($payload['signature'])->toBe(esewaClient()->sign($signed))
        ->and($payload['signed_field_names'])->toBe('total_amount,transaction_uuid,product_code')
        ->and($payload['total_amount'])->toBe('110.00');
});

it('verifies the callback payload published by eSewa', function () {
    // Verbatim from the documentation, including total_amount as a JSON *number*.
    $json = '{"transaction_code":"000AWEO","status":"COMPLETE","total_amount":1000.0,'
        .'"transaction_uuid":"250610-162413","product_code":"EPAYTEST",'
        .'"signed_field_names":"transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names",'
        .'"signature":"62GcfZTmVkzhtUeh+QJ1AqiJrjoWWGof3U+eTPTZ7fA="}';

    $verified = esewaClient()->verifyCallback(base64_encode($json));

    expect($verified['status'])->toBe('COMPLETE')
        ->and($verified['transaction_uuid'])->toBe('250610-162413');
});

it('signs the raw numeric literal rather than the cast value', function () {
    // Regression guard. Interpolating the decoded float yields "1000", which
    // produces a different HMAC and rejects a genuine payment.
    $payload = CallbackPayload::fromJson(
        '{"total_amount":1000.0,"signed_field_names":"total_amount"}'
    );

    expect($payload->signatureSource())->toBe('total_amount=1000.0')
        ->and($payload->signatureSource())->not->toBe('total_amount=1000');
});

/*
|--------------------------------------------------------------------------
| Literal handling
|--------------------------------------------------------------------------
*/

it('preserves every literal shape eSewa can send', function (string $json, string $expected) {
    expect(CallbackPayload::fromJson($json)->rawValue('total_amount'))->toBe($expected);
})->with([
    'integer' => ['{"total_amount":1000}', '1000'],
    'float with trailing zero' => ['{"total_amount":1000.0}', '1000.0'],
    'float with paisa' => ['{"total_amount":1234.50}', '1234.50'],
    'string' => ['{"total_amount":"1000.0"}', '1000.0'],
    'string with separator' => ['{"total_amount":"1,000.0"}', '1,000.0'],
    'negative' => ['{"total_amount":-5.5}', '-5.5'],
    'null' => ['{"total_amount":null}', 'null'],
    'not the first key' => ['{"status":"COMPLETE","total_amount":1000.0}', '1000.0'],
]);

it('does not confuse a field with one whose name merely contains it', function () {
    $json = '{"amount":25.0,"total_amount":1000.0}';

    expect(CallbackPayload::fromJson($json)->rawValue('amount'))->toBe('25.0')
        ->and(CallbackPayload::fromJson($json)->rawValue('total_amount'))->toBe('1000.0');
});

/*
|--------------------------------------------------------------------------
| Rejections
|--------------------------------------------------------------------------
*/

it('rejects a tampered payload', function () {
    $encoded = esewaCallbackPayload(['transaction_uuid' => 'ORDER-1', 'total_amount' => '100.00']);

    $fields = json_decode((string) base64_decode($encoded, true), true);
    $fields['total_amount'] = '99999.00';

    esewaClient()->verifyCallback(base64_encode((string) json_encode($fields)));
})->throws(SignatureVerificationException::class, 'does not match');

it('rejects a payload that signs too few fields', function () {
    // Signed correctly, but only over "status". Without this guard a captured
    // COMPLETE signature could be replayed against any transaction or amount.
    $client = esewaClient();

    $fields = [
        'status' => 'COMPLETE',
        'transaction_uuid' => 'ORDER-1',
        'total_amount' => '100.00',
        'product_code' => 'EPAYTEST',
        'signed_field_names' => 'status',
    ];
    $fields['signature'] = $client->buildSignatureForFields($fields, 'status');

    $client->verifyCallback(base64_encode((string) json_encode($fields)));
})->throws(SignatureVerificationException::class, 'does not sign the required field');

it('rejects a validly signed payload meant for another merchant', function () {
    $foreign = esewaClient(['product_code' => 'OTHERSHOP']);

    $encoded = $foreign->signedCallbackPayload(['product_code' => 'OTHERSHOP']);

    esewaClient()->verifyCallback($encoded);
})->throws(SignatureVerificationException::class, 'is configured for');

it('rejects malformed payloads', function (string $payload) {
    esewaClient()->verifyCallback($payload);
})->with([
    'not base64' => '!!!not base64!!!',
    'not json' => 'bm90IGpzb24=',           // "not json"
    'json but not an object' => 'WzEsMiwzXQ==', // "[1,2,3]"
    'empty' => '',
])->throws(SignatureVerificationException::class);

it('rejects a payload with no signature at all', function () {
    esewaClient()->verifyCallback(base64_encode('{"status":"COMPLETE"}'));
})->throws(SignatureVerificationException::class, 'missing');

it('reports which signed field is absent from the payload', function () {
    $fields = [
        'status' => 'COMPLETE',
        'transaction_uuid' => 'ORDER-1',
        'total_amount' => '100.00',
        'product_code' => 'EPAYTEST',
        'signed_field_names' => 'status,transaction_uuid,total_amount,product_code,ref_id',
        'signature' => 'irrelevant',
    ];

    esewaClient()->verifyCallback(base64_encode((string) json_encode($fields)));
})->throws(SignatureVerificationException::class, 'ref_id');

/*
|--------------------------------------------------------------------------
| Round trip
|--------------------------------------------------------------------------
*/

it('verifies the payloads it generates for tests', function () {
    $encoded = esewaCallbackPayload([
        'transaction_uuid' => 'ORDER-42',
        'total_amount' => '1234.50',
    ]);

    expect(esewaClient()->verifyCallback($encoded))
        ->toMatchArray([
            'transaction_uuid' => 'ORDER-42',
            'total_amount' => '1234.50',
            'status' => 'COMPLETE',
        ]);
});
