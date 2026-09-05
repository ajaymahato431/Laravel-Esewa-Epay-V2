<?php

use AjayMahato\Esewa\Enums\PaymentStatus;
use AjayMahato\Esewa\Events\EsewaPaymentVerified;
use AjayMahato\Esewa\Exceptions\EsewaException;
use AjayMahato\Esewa\Facades\Esewa;
use AjayMahato\Esewa\Jobs\ReconcileEsewaPayment;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| The job
|--------------------------------------------------------------------------
*/

it('settles a payment whose callback never arrived', function () {
    // Customers close tabs and lose signal. eSewa is the authority.
    $payment = esewaPayment();
    Event::fake();

    Http::fake(['*' => Http::response(['status' => 'COMPLETE', 'ref_id' => '0007G36'])]);

    dispatch_sync(new ReconcileEsewaPayment($payment->transaction_uuid));

    $payment->refresh();

    expect($payment->status)->toBe(PaymentStatus::COMPLETE)
        ->and($payment->ref_id)->toBe('0007G36')
        ->and($payment->verified_at)->not->toBeNull();

    Event::assertDispatched(EsewaPaymentVerified::class);
});

it('does not re-check a payment that already settled', function () {
    $payment = esewaPayment(['status' => PaymentStatus::COMPLETE]);

    Http::fake();

    dispatch_sync(new ReconcileEsewaPayment($payment->transaction_uuid));

    Http::assertNothingSent();
});

it('survives an unknown transaction without throwing', function () {
    Http::fake();

    dispatch_sync(new ReconcileEsewaPayment('NEVER-EXISTED'));

    Http::assertNothingSent();
});

it('keeps polling an ambiguous payment', function () {
    // eSewa resolves AMBIGUOUS later, so it must not be treated as settled.
    $payment = esewaPayment(['status' => PaymentStatus::AMBIGUOUS]);

    Http::fake(['*' => Http::response(['status' => 'COMPLETE', 'ref_id' => 'LATE1'])]);

    dispatch_sync(new ReconcileEsewaPayment($payment->transaction_uuid));

    expect($payment->refresh()->status)->toBe(PaymentStatus::COMPLETE);
});

it('is unique per transaction so a sweep does not double-poll', function () {
    expect((new ReconcileEsewaPayment('ORDER-1'))->uniqueId())->toBe('ORDER-1');
});

/*
|--------------------------------------------------------------------------
| Status check request
|--------------------------------------------------------------------------
*/

it('sends the query parameters eSewa documents', function () {
    $payment = esewaPayment(['total_amount' => '1234.50']);

    Http::fake(['*' => Http::response(['status' => 'PENDING', 'ref_id' => null])]);

    Esewa::reconcile($payment);

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://rc.esewa.com.np/api/epay/transaction/status/')
            && $query['product_code'] === 'EPAYTEST'
            && $query['total_amount'] === '1234.50'
            && isset($query['transaction_uuid']);
    });
});

it('leaves the payment untouched when the gateway is unreachable', function () {
    // "We could not ask" must never be recorded as "did not pay".
    $payment = esewaPayment();

    Http::fake(['*' => Http::response('gateway down', 503)]);

    expect(fn () => Esewa::reconcile($payment))
        ->toThrow(EsewaException::class);

    expect($payment->refresh()->status)->toBe(PaymentStatus::PENDING);
});

/*
|--------------------------------------------------------------------------
| The command
|--------------------------------------------------------------------------
*/

it('sweeps unresolved payments', function () {
    $stale = esewaPayment(['transaction_uuid' => 'STALE-1']);
    $stale->forceFill(['created_at' => now()->subHour()])->save();

    Http::fake(['*' => Http::response(['status' => 'COMPLETE', 'ref_id' => 'SWEPT'])]);

    $this->artisan('esewa:reconcile')->assertSuccessful();

    expect($stale->refresh()->status)->toBe(PaymentStatus::COMPLETE);
});

it('leaves payments the customer may still be completing alone', function () {
    // A payment started 30 seconds ago is not stale; the customer is probably
    // still on the eSewa page.
    esewaPayment(['transaction_uuid' => 'FRESH-1']);

    Http::fake();

    $this->artisan('esewa:reconcile')->assertSuccessful();

    Http::assertNothingSent();
});

it('reconciles a single transaction on demand', function () {
    $payment = esewaPayment(['transaction_uuid' => 'SUPPORT-1']);

    Http::fake(['*' => Http::response(['status' => 'COMPLETE', 'ref_id' => 'MANUAL'])]);

    $this->artisan('esewa:reconcile', ['transaction' => 'SUPPORT-1'])->assertSuccessful();

    expect($payment->refresh()->ref_id)->toBe('MANUAL');
});

it('fails loudly when asked about a transaction it does not know', function () {
    $this->artisan('esewa:reconcile', ['transaction' => 'NOPE'])->assertFailed();
});
