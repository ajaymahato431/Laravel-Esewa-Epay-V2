<?php

use AjayMahato\Esewa\Enums\PaymentStatus;
use AjayMahato\Esewa\Events\EsewaPaymentFailed;
use AjayMahato\Esewa\Events\EsewaPaymentStatusUpdated;
use AjayMahato\Esewa\Events\EsewaPaymentVerified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| The happy path
|--------------------------------------------------------------------------
*/

it('marks a payment complete from a signed callback', function () {
    $payment = esewaPayment(['total_amount' => '1000.00']);
    Event::fake();

    $this->post('/esewa/callback', [
        'data' => esewaCallbackPayload([
            'transaction_uuid' => $payment->transaction_uuid,
            'total_amount' => '1000.0',
            'transaction_code' => 'TXN123',
        ]),
    ])->assertStatus(200);

    $payment->refresh();

    expect($payment->status)->toBe(PaymentStatus::COMPLETE)
        ->and($payment->ref_id)->toBe('TXN123')
        ->and($payment->verified_at)->not->toBeNull();

    Event::assertDispatched(EsewaPaymentVerified::class);
    Event::assertDispatched(EsewaPaymentStatusUpdated::class);
});

it('accepts the callback over GET as well as POST', function () {
    // eSewa redirects the browser with a GET. A POST-only route answers 405 and
    // the payment silently never reconciles.
    $payment = esewaPayment();

    $this->get('/esewa/callback?data='.esewaCallbackPayload([
        'transaction_uuid' => $payment->transaction_uuid,
        'total_amount' => $payment->total_amount,
    ]))->assertStatus(200);

    expect($payment->refresh()->status)->toBe(PaymentStatus::COMPLETE);
});

it('answers JSON for API clients', function () {
    $payment = esewaPayment();

    $this->postJson('/esewa/callback', [
        'data' => esewaCallbackPayload([
            'transaction_uuid' => $payment->transaction_uuid,
            'total_amount' => $payment->total_amount,
        ]),
    ])->assertOk()->assertJson(['ok' => true, 'status' => 'COMPLETE']);
});

it('reports a still-pending payment with 202 rather than 200', function () {
    $payment = esewaPayment();

    $this->post('/esewa/callback', [
        'data' => esewaCallbackPayload([
            'transaction_uuid' => $payment->transaction_uuid,
            'total_amount' => $payment->total_amount,
            'status' => 'PENDING',
        ]),
    ])->assertStatus(202);

    expect($payment->refresh()->status)->toBe(PaymentStatus::PENDING)
        ->and($payment->verified_at)->toBeNull();
});

it('dispatches the failure event when the customer cancels', function () {
    $payment = esewaPayment();
    Event::fake();

    $this->post('/esewa/callback', [
        'data' => esewaCallbackPayload([
            'transaction_uuid' => $payment->transaction_uuid,
            'total_amount' => $payment->total_amount,
            'status' => 'CANCELED',
        ]),
    ]);

    expect($payment->refresh()->status)->toBe(PaymentStatus::CANCELED);

    Event::assertDispatched(EsewaPaymentFailed::class);
    Event::assertNotDispatched(EsewaPaymentVerified::class);
});

/*
|--------------------------------------------------------------------------
| Money
|--------------------------------------------------------------------------
*/

it('accepts a callback whose amount is formatted differently to the stored one', function (string $callbackAmount) {
    // eSewa echoes amounts as 1000, 1000.0 or "1,000.0" depending on the path.
    // All of them describe the same 1000.00 payment.
    $payment = esewaPayment(['total_amount' => '1000.00']);

    $this->post('/esewa/callback', [
        'data' => esewaCallbackPayload([
            'transaction_uuid' => $payment->transaction_uuid,
            'total_amount' => $callbackAmount,
        ]),
    ]);

    expect($payment->refresh()->status)->toBe(PaymentStatus::COMPLETE);
})->with(['1000', '1000.0', '1000.00', '1,000.0']);

it('preserves paisa end to end', function () {
    $payment = esewaPayment(['amount' => '1234.50', 'total_amount' => '1234.50']);

    $this->post('/esewa/callback', [
        'data' => esewaCallbackPayload([
            'transaction_uuid' => $payment->transaction_uuid,
            'total_amount' => '1234.50',
        ]),
    ])->assertStatus(200);

    expect($payment->refresh()->total_amount)->toBe('1234.50');
});

it('refuses a callback whose amount is not the amount that was charged', function () {
    // The signature proves eSewa sent it; this proves it is about this payment.
    $payment = esewaPayment(['total_amount' => '1000.00']);

    $this->post('/esewa/callback', [
        'data' => esewaCallbackPayload([
            'transaction_uuid' => $payment->transaction_uuid,
            'total_amount' => '10.00',
        ]),
    ])->assertStatus(422);

    expect($payment->refresh()->status)->toBe(PaymentStatus::PENDING);
});

/*
|--------------------------------------------------------------------------
| Idempotency
|--------------------------------------------------------------------------
*/

it('fires the verified event only once however many times the callback arrives', function () {
    // Customers refresh. Relays retry. Reconciliation races the browser. None of
    // that may fulfil an order twice.
    $payment = esewaPayment();
    Event::fake();

    $data = esewaCallbackPayload([
        'transaction_uuid' => $payment->transaction_uuid,
        'total_amount' => $payment->total_amount,
    ]);

    foreach (range(1, 3) as $ignored) {
        $this->post('/esewa/callback', ['data' => $data])->assertStatus(200);
    }

    Event::assertDispatchedTimes(EsewaPaymentVerified::class, 1);
});

it('keeps the original verification timestamp on a repeat callback', function () {
    $payment = esewaPayment();

    $data = esewaCallbackPayload([
        'transaction_uuid' => $payment->transaction_uuid,
        'total_amount' => $payment->total_amount,
    ]);

    $this->post('/esewa/callback', ['data' => $data]);
    $first = $payment->refresh()->verified_at;

    $this->travel(5)->minutes();
    $this->post('/esewa/callback', ['data' => $data]);

    expect($payment->refresh()->verified_at->timestamp)->toBe($first->timestamp);
});

/*
|--------------------------------------------------------------------------
| Rejections
|--------------------------------------------------------------------------
*/

it('rejects a forged callback', function () {
    $payment = esewaPayment();

    $forged = base64_encode((string) json_encode([
        'transaction_uuid' => $payment->transaction_uuid,
        'total_amount' => $payment->total_amount,
        'status' => 'COMPLETE',
        'product_code' => 'EPAYTEST',
        'signed_field_names' => 'transaction_uuid,total_amount,status,product_code',
        'signature' => 'ZmFrZSBzaWduYXR1cmU=',
    ]));

    $this->post('/esewa/callback', ['data' => $forged])->assertStatus(422);

    expect($payment->refresh()->status)->toBe(PaymentStatus::PENDING)
        ->and($payment->verified_at)->toBeNull();
});

it('rejects a callback for a payment that was never started', function () {
    // Amounts are only trustworthy because they were recorded before the
    // customer was sent to eSewa.
    $this->post('/esewa/callback', [
        'data' => esewaCallbackPayload(['transaction_uuid' => 'NEVER-STARTED']),
    ])->assertStatus(422);
});

it('rejects a request carrying neither payload nor transaction id', function () {
    $this->post('/esewa/callback')->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Status-check fallback
|--------------------------------------------------------------------------
*/

it('asks eSewa directly when it is sent back without a signed payload', function () {
    $payment = esewaPayment();
    Event::fake();

    Http::fake([
        '*' => Http::response(['status' => 'COMPLETE', 'ref_id' => '0007G36']),
    ]);

    $this->post('/esewa/callback', ['transaction_uuid' => $payment->transaction_uuid])
        ->assertStatus(200);

    $payment->refresh();

    expect($payment->status)->toBe(PaymentStatus::COMPLETE)
        ->and($payment->ref_id)->toBe('0007G36');

    Event::assertDispatched(EsewaPaymentVerified::class);
});

it('surfaces a gateway outage instead of marking the payment failed', function () {
    // {"code": 0, "error_message": "Service is currently unavailable"} means we
    // do not know, which is not the same as "did not pay".
    $payment = esewaPayment();

    Http::fake([
        '*' => Http::response(['code' => 0, 'error_message' => 'Service is currently unavailable']),
    ]);

    $this->post('/esewa/callback', ['transaction_uuid' => $payment->transaction_uuid])
        ->assertStatus(422);

    expect($payment->refresh()->status)->toBe(PaymentStatus::PENDING);
});
