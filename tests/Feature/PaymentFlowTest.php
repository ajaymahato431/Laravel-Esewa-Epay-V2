<?php

use AjayMahato\Esewa\Enums\PaymentStatus;
use AjayMahato\Esewa\Events\EsewaPaymentInitiated;
use AjayMahato\Esewa\Exceptions\EsewaException;
use AjayMahato\Esewa\Facades\Esewa;
use AjayMahato\Esewa\Jobs\ReconcileEsewaPayment;
use AjayMahato\Esewa\Models\EsewaPayment;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Starting a payment
|--------------------------------------------------------------------------
*/

it('records the payment and renders a form that posts to eSewa', function () {
    Event::fake();

    $html = Esewa::pay(['amount' => 1500, 'transaction_uuid' => 'ORDER-1'])->getContent();

    expect($html)
        ->toContain('https://rc-epay.esewa.com.np/api/epay/main/v2/form')
        ->toContain('name="signature"')
        ->toContain('name="transaction_uuid" value="ORDER-1"')
        ->toContain('value="1500.00"');

    $payment = EsewaPayment::query()->forTransaction('ORDER-1')->firstOrFail();

    expect($payment->status)->toBe(PaymentStatus::PENDING)
        ->and($payment->total_amount)->toBe('1500.00')
        ->and($payment->product_code)->toBe('EPAYTEST');

    Event::assertDispatched(EsewaPaymentInitiated::class);
});

it('sends eSewa to the relay rather than straight to the merchant page', function () {
    // Otherwise a shopper reaches the thank-you page before anything has been
    // verified - and reaches it just as easily by typing the URL.
    $html = Esewa::pay([
        'amount' => 100,
        'transaction_uuid' => 'ORDER-2',
        'success_url' => '/orders/2/thanks',
    ])->getContent();

    expect($html)->toContain(route('esewa.relay', ['transaction' => 'ORDER-2']))
        ->not->toContain('value="/orders/2/thanks"');

    // The merchant's page is remembered for after verification.
    expect(EsewaPayment::query()->forTransaction('ORDER-2')->firstOrFail()->meta)
        ->toMatchArray(['success_redirect' => '/orders/2/thanks']);
});

it('generates a transaction id eSewa accepts', function () {
    Esewa::pay(['amount' => 100]);

    $uuid = EsewaPayment::query()->firstOrFail()->transaction_uuid;

    expect($uuid)->toMatch('/^[A-Za-z0-9-]{1,64}$/');
});

it('refuses to reuse a transaction id', function () {
    // Reuse means two orders sharing one gateway record; whichever callback
    // lands last would settle the wrong one.
    Esewa::pay(['amount' => 100, 'transaction_uuid' => 'ORDER-DUP']);
    Esewa::pay(['amount' => 200, 'transaction_uuid' => 'ORDER-DUP']);
})->throws(EsewaException::class, 'already exists');

it('adds the parts up itself', function () {
    Esewa::pay([
        'amount' => '1000.50',
        'tax_amount' => '130.25',
        'product_delivery_charge' => '69.25',
        'transaction_uuid' => 'ORDER-3',
    ]);

    expect(EsewaPayment::query()->forTransaction('ORDER-3')->firstOrFail()->total_amount)
        ->toBe('1200.00');
});

it('exposes the signed fields without rendering a page', function () {
    // For SPA and mobile clients that post to eSewa themselves.
    $prepared = Esewa::prepare(['amount' => 500, 'transaction_uuid' => 'ORDER-4']);

    expect($prepared['endpoint'])->toBe('https://rc-epay.esewa.com.np/api/epay/main/v2/form')
        ->and($prepared['payload'])->toHaveKeys(['signature', 'signed_field_names', 'total_amount'])
        ->and($prepared['payment'])->toBeInstanceOf(EsewaPayment::class);
});

/*
|--------------------------------------------------------------------------
| Reconciliation scheduling
|--------------------------------------------------------------------------
*/

it('does not queue a reconciliation job by default', function () {
    // The sync driver ignores delays, so an on-by-default job would run the
    // status check inline, in the middle of redirecting the customer.
    Queue::fake();

    Esewa::pay(['amount' => 100]);

    Queue::assertNothingPushed();
});

it('queues a delayed reconciliation job when asked to', function () {
    config()->set('esewa.reconciliation.auto_dispatch', true);
    config()->set('esewa.reconciliation.delay', 10);
    Queue::fake();

    Esewa::pay(['amount' => 100, 'transaction_uuid' => 'ORDER-5']);

    Queue::assertPushed(
        ReconcileEsewaPayment::class,
        fn (ReconcileEsewaPayment $job) => $job->transactionUuid === 'ORDER-5' && $job->delay !== null
    );
});

/*
|--------------------------------------------------------------------------
| Facade
|--------------------------------------------------------------------------
*/

it('resolves the facade to a real class with real methods', function () {
    // The previous implementation proxied through an anonymous class, so
    // nothing could autocomplete or type-check it.
    expect(Esewa::getFacadeRoot())->toBeInstanceOf(\AjayMahato\Esewa\Esewa::class)
        ->and(method_exists(\AjayMahato\Esewa\Esewa::class, 'handleCallback'))->toBeTrue()
        ->and(Esewa::mode())->toBe('uat')
        ->and(Esewa::productCode())->toBe('EPAYTEST');
});

it('finds a payment by transaction id', function () {
    $payment = esewaPayment(['transaction_uuid' => 'ORDER-FIND']);

    expect(Esewa::find('ORDER-FIND')?->is($payment))->toBeTrue()
        ->and(Esewa::find('NOPE'))->toBeNull();
});
