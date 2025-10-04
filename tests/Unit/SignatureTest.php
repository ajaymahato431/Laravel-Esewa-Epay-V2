<?php

use AjayMahato\Esewa\EsewaClient;

it('builds request signature', function () {
    $client = new EsewaClient([
        'mode' => 'uat',
        'product_code' => 'EPAYTEST',
        'secret_key' => '8gBm/:&EnhH.1/q',
        'endpoints' => [
            'uat' => ['form' => '', 'status_check' => ''],
            'production' => ['form' => '', 'status_check' => ''],
        ],
    ]);

    $sig = $client->buildRequestSignature('110', '241028');
    expect($sig)->toBeString();
});

it('verifies callback signatures', function () {
    $client = new EsewaClient([
        'mode' => 'uat',
        'product_code' => 'EPAYTEST',
        'secret_key' => '8gBm/:&EnhH.1/q',
        'endpoints' => [
            'uat' => ['form' => '', 'status_check' => ''],
            'production' => ['form' => '', 'status_check' => ''],
        ],
    ]);

    $payload = [
        'transaction_code' => '000AWEO',
        'status' => 'COMPLETE',
        'total_amount' => '1000.0',
        'transaction_uuid' => '250610-162413',
        'product_code' => 'EPAYTEST',
        'signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names',
    ];
    $payload['signature'] = $client->buildSignatureForFields($payload, $payload['signed_field_names']);

    $verified = $client->verifyCallback(base64_encode(json_encode($payload)));
    expect($verified['status'])->toBe('COMPLETE');
});
