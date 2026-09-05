<?php

use AjayMahato\Esewa\Facades\Esewa;
use AjayMahato\Esewa\Tests\RoutesDisabledTestCase;

uses(RoutesDisabledTestCase::class);

it('registers no routes when the application wants to own its own URLs', function () {
    $names = collect(app('router')->getRoutes())->map->getName()->filter()->values();

    expect($names)->not->toContain('esewa.callback')
        ->and($names)->not->toContain('esewa.relay');

    $this->post('/esewa/callback')->assertNotFound();
});

it('still builds a relay URL so payments can be started', function () {
    // Without the named route to resolve, fall back to the conventional path
    // rather than throwing halfway through a checkout.
    expect(Esewa::relayUrl('ORDER-1'))->toBe('http://localhost/esewa/relay/ORDER-1');
});

it('still verifies callbacks through the facade', function () {
    // This is the whole point of switching the routes off: the application
    // handles the HTTP and calls the package for the logic.
    $payment = esewaPayment(['transaction_uuid' => 'ORDER-OWN']);

    $updated = Esewa::handleCallback(esewaCallbackPayload([
        'transaction_uuid' => 'ORDER-OWN',
        'total_amount' => $payment->total_amount,
    ]));

    expect($updated->status->isComplete())->toBeTrue();
});
