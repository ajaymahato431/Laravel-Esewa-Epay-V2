<?php

use AjayMahato\Esewa\Facades\Esewa;
use AjayMahato\Esewa\Models\EsewaPayment;
use AjayMahato\Esewa\Tests\Fixtures\Order;

beforeEach(function () {
    $this->createOrdersTable();
});

it('links a payment to the thing being paid for', function () {
    // Replaces hand-rolling meta['payable'] and looking the model back up by
    // class name in a listener.
    $order = Order::create(['reference' => 'INV-1']);

    Esewa::pay(['amount' => 2500, 'payable' => $order, 'transaction_uuid' => 'ORDER-P1']);

    $payment = EsewaPayment::query()->forTransaction('ORDER-P1')->firstOrFail();

    expect($payment->payable)->toBeInstanceOf(Order::class)
        ->and($payment->payable->is($order))->toBeTrue();
});

it('reaches the payments from the order', function () {
    $order = Order::create(['reference' => 'INV-2']);

    Esewa::pay(['amount' => 100, 'payable' => $order, 'transaction_uuid' => 'ORDER-P2']);
    Esewa::pay(['amount' => 100, 'payable' => $order, 'transaction_uuid' => 'ORDER-P3']);

    expect($order->esewaPayments)->toHaveCount(2)
        ->and($order->latestEsewaPayment->transaction_uuid)->toBe('ORDER-P3');
});

it('reports whether the order has actually been paid', function () {
    $order = Order::create(['reference' => 'INV-3']);

    Esewa::pay(['amount' => 100, 'payable' => $order, 'transaction_uuid' => 'ORDER-P4']);

    expect($order->hasCompletedEsewaPayment())->toBeFalse();

    $this->post('/esewa/callback', [
        'data' => esewaCallbackPayload([
            'transaction_uuid' => 'ORDER-P4',
            'total_amount' => '100.00',
        ]),
    ]);

    expect($order->hasCompletedEsewaPayment())->toBeTrue()
        ->and($order->completedEsewaPayment()->first()->transaction_uuid)->toBe('ORDER-P4');
});

it('gives a listener the order directly', function () {
    // The whole point: a listener can fulfil the order without knowing how the
    // payment recorded which order it was for.
    $order = Order::create(['reference' => 'INV-4']);

    Esewa::pay(['amount' => 100, 'payable' => $order, 'transaction_uuid' => 'ORDER-P5']);

    $payment = Esewa::handleCallback(esewaCallbackPayload([
        'transaction_uuid' => 'ORDER-P5',
        'total_amount' => '100.00',
    ]));

    expect($payment->payable->reference)->toBe('INV-4');
});

it('works without a payable', function () {
    Esewa::pay(['amount' => 100, 'transaction_uuid' => 'ORDER-P6']);

    $payment = EsewaPayment::query()->forTransaction('ORDER-P6')->firstOrFail();

    expect($payment->payable)->toBeNull()
        ->and($payment->payable_type)->toBeNull();
});
