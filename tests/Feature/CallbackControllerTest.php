<?php

use AjayMahato\Esewa\Enums\PaymentStatus;
use AjayMahato\Esewa\Events\EsewaPaymentVerified;
use AjayMahato\Esewa\Models\EsewaPayment;
use Illuminate\Support\Facades\Event;

it('reconciles a pending payment via posted callback', function () {
    $uuid = '240101-000001-TEST';

    $payment = EsewaPayment::create([
        'transaction_uuid' => $uuid,
        'product_code'     => config('esewa.product_code', 'EPAYTEST'),
        'amount'           => 1000,
        'tax_amount'       => 0,
        'service_charge'   => 0,
        'delivery_charge'  => 0,
        'total_amount'     => 1000,
        'status'           => PaymentStatus::PENDING,
        'meta'             => ['success_redirect' => '/orders/complete'],
    ]);

    Event::fake();

    $base64 = esewaCallbackPayload([
        'transaction_uuid' => $uuid,
        'transaction_code' => 'TXN123',
        'status'           => 'COMPLETE',
        'total_amount'     => '1000',
    ]);

    $response = $this->post('/esewa/callback', ['data' => $base64]);

    $response->assertRedirect('/orders/complete');

    $payload = session('esewa');

    expect($payload)->not()->toBeNull()
        ->and($payload['meta']['ok'])->toBeTrue()
        ->and($payload['meta']['status'])->toBe('COMPLETE');

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::COMPLETE);
    expect($payment->ref_id)->toBe('TXN123');
    expect($payment->verified_at)->not()->toBeNull();

    Event::assertDispatched(EsewaPaymentVerified::class, fn ($event) => $event->payment->transaction_uuid === $uuid);
});

it('redirects back with session payload when redirect input is provided', function () {
    $uuid = '240101-000002-TEST';

    EsewaPayment::create([
        'transaction_uuid' => $uuid,
        'product_code'     => config('esewa.product_code', 'EPAYTEST'),
        'amount'           => 500,
        'tax_amount'       => 0,
        'service_charge'   => 0,
        'delivery_charge'  => 0,
        'total_amount'     => 500,
        'status'           => PaymentStatus::PENDING,
    ]);

    Event::fake();

    $base64 = esewaCallbackPayload([
        'transaction_uuid' => $uuid,
        'transaction_code' => 'REDIR123',
        'status'           => 'COMPLETE',
        'total_amount'     => '500',
    ]);

    $response = $this->post('/esewa/callback', [
        'data'     => $base64,
        'redirect' => '/orders/thank-you',
    ]);

    $response->assertRedirect('/orders/thank-you');

    $payload = session('esewa');

    expect($payload)->not()->toBeNull()
        ->and($payload['payment']->transaction_uuid)->toBe($uuid)
        ->and($payload['meta']['status'])->toBe('COMPLETE')
        ->and($payload['meta']['ref_id'])->toBe('REDIR123')
        ->and($payload['meta']['ok'])->toBeTrue();
});

it('redirects to the stored failure URL when the callback status is not complete', function () {
    $uuid = '240101-000003-TEST';

    EsewaPayment::create([
        'transaction_uuid' => $uuid,
        'product_code'     => config('esewa.product_code', 'EPAYTEST'),
        'amount'           => 1200,
        'tax_amount'       => 0,
        'service_charge'   => 0,
        'delivery_charge'  => 0,
        'total_amount'     => 1200,
        'status'           => PaymentStatus::PENDING,
        'meta'             => ['failure_redirect' => '/orders/pending'],
    ]);

    $base64 = esewaCallbackPayload([
        'transaction_uuid' => $uuid,
        'status'           => 'PENDING',
        'total_amount'     => '1200',
        'transaction_code' => 'PENDING123',
    ]);

    $response = $this->post('/esewa/callback', ['data' => $base64]);

    $response->assertRedirect('/orders/pending');

    $payload = session('esewa');

    expect($payload)->not()->toBeNull()
        ->and($payload['meta']['ok'])->toBeFalse();

    $payment = EsewaPayment::firstWhere('transaction_uuid', $uuid);

    expect($payment->status)->toBe(PaymentStatus::PENDING);
    expect($payment->verified_at)->toBeNull();
});

it('returns JSON when the callback is posted via API client', function () {
    $uuid = '240101-000004-TEST';

    EsewaPayment::create([
        'transaction_uuid' => $uuid,
        'product_code'     => config('esewa.product_code', 'EPAYTEST'),
        'amount'           => 750,
        'tax_amount'       => 0,
        'service_charge'   => 0,
        'delivery_charge'  => 0,
        'total_amount'     => 750,
        'status'           => PaymentStatus::PENDING,
    ]);

    $base64 = esewaCallbackPayload([
        'transaction_uuid' => $uuid,
        'status'           => 'COMPLETE',
        'total_amount'     => '750',
        'transaction_code' => 'API123',
    ]);

    $response = $this->postJson('/esewa/callback', ['data' => $base64]);

    $response->assertOk()->assertJson([
        'ok'     => true,
        'status' => 'COMPLETE',
    ]);
});

it('renders the relay page which auto posts to the callback route', function () {
    $base64 = esewaCallbackPayload([
        'transaction_uuid' => 'RELAY-TEST-UUID',
    ]);

    $response = $this->get("/esewa/relay?data={$base64}");

    $response->assertOk()
        ->assertSee('id="esewa-relay"', false)
        ->assertSee('action="' . route('esewa.callback') . '"', false)
        ->assertSee('name="data"', false);
});

it('parses relay URLs where eSewa appends ?data after an existing redirect query parameter', function () {
    $base64 = esewaCallbackPayload([
        'transaction_uuid' => 'RELAY-QUERY-UUID',
    ]);

    $response = $this->get("/esewa/relay?redirect=/orders/complete?data={$base64}");

    $response->assertOk()
        ->assertSee('value="/orders/complete"', false)
        ->assertSee("value=\"{$base64}\"", false);
});
it('accepts relay payload via POST', function () {
    $base64 = esewaCallbackPayload([
        'transaction_uuid' => 'RELAY-POST-UUID',
    ]);

    $response = $this->post('/esewa/relay', ['data' => $base64]);

    $response->assertOk()
        ->assertSee('id="esewa-relay"', false)
        ->assertSee("value=\"{$base64}\"", false);
});


it('shows a friendly message when the relay payload is missing', function () {
    $response = $this->get('/esewa/relay?redirect=/orders/complete');

    $response->assertStatus(422)->assertSee('has not sent the signed payload yet');
});

function esewaCallbackPayload(array $overrides): string
{
    $client = app(\AjayMahato\Esewa\EsewaClient::class);

    $payload = array_merge([
        'transaction_code'    => $overrides['transaction_code'] ?? 'TXN-CALLBACK',
        'status'              => $overrides['status'] ?? 'COMPLETE',
        'total_amount'        => $overrides['total_amount'] ?? '1000',
        'transaction_uuid'    => $overrides['transaction_uuid'] ?? 'UUID-CALLBACK',
        'product_code'        => $overrides['product_code'] ?? config('esewa.product_code', 'EPAYTEST'),
    ], $overrides);

    $payload['signed_field_names'] = $payload['signed_field_names']
        ?? 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names';

    $payload['signature'] = $client->buildSignatureForFields($payload, $payload['signed_field_names']);

    return base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
}
