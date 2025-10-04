<?php

use AjayMahato\Esewa\Enums\PaymentStatus;
use AjayMahato\Esewa\Events\EsewaPaymentVerified;
use AjayMahato\Esewa\Models\EsewaPayment;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('reconciles a pending payment via browser callback', function () {
    $uuid = '240101-000001-TEST';

    $payment = EsewaPayment::create([
        'transaction_uuid' => $uuid,
        'product_code' => config('esewa.product_code', 'EPAYTEST'),
        'amount' => 1000,
        'tax_amount' => 0,
        'service_charge' => 0,
        'delivery_charge' => 0,
        'total_amount' => 1000,
        'status' => PaymentStatus::PENDING,
    ]);

    Event::fake();
    Http::fakeSequence()->push([
        'status' => 'COMPLETE',
        'transaction_code' => 'TXN123',
    ], 200);

    $response = $this->get("/esewa/callback?transaction_uuid={$uuid}");

    $response->assertOk()->assertSee('Payment Complete');

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::COMPLETE);
    expect($payment->ref_id)->toBe('TXN123');
    expect($payment->verified_at)->not()->toBeNull();

    Event::assertDispatched(EsewaPaymentVerified::class, fn ($event) => $event->payment->transaction_uuid === $uuid);
});

it('redirects back with session payload when redirect query is present', function () {
    $uuid = '240101-000002-TEST';

    EsewaPayment::create([
        'transaction_uuid' => $uuid,
        'product_code' => config('esewa.product_code', 'EPAYTEST'),
        'amount' => 500,
        'tax_amount' => 0,
        'service_charge' => 0,
        'delivery_charge' => 0,
        'total_amount' => 500,
        'status' => PaymentStatus::PENDING,
    ]);

    Event::fake();
    Http::fakeSequence()->push([
        'status' => 'COMPLETE',
        'transaction_code' => 'REDIR123',
    ], 200);

    $response = $this->get("/esewa/callback?transaction_uuid={$uuid}&redirect=/orders/thank-you");

    $response->assertRedirect('/orders/thank-you');

    $payload = session('esewa');

    expect($payload)->not()->toBeNull()
        ->and($payload['payment']->transaction_uuid)->toBe($uuid)
        ->and($payload['meta']['status'])->toBe('COMPLETE')
        ->and($payload['meta']['ref_id'])->toBe('REDIR123');
});
