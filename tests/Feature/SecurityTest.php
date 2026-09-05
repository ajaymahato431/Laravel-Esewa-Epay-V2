<?php

use AjayMahato\Esewa\Facades\Esewa;
use AjayMahato\Esewa\Models\EsewaPayment;

/*
|--------------------------------------------------------------------------
| No public way to charge money
|--------------------------------------------------------------------------
*/

it('exposes no route that starts a payment', function () {
    // A public POST /esewa/pay taking request input would let anyone mint
    // payment records for any amount, with an attacker-chosen redirect.
    // Choosing an amount belongs to the application, behind its own auth.
    $names = collect(app('router')->getRoutes())->map->getName()->filter()->values();

    expect($names)->toContain('esewa.callback', 'esewa.relay')
        ->and($names)->not->toContain('esewa.pay');

    $this->post('/esewa/pay', ['amount' => 1])->assertNotFound();
    expect(EsewaPayment::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Open redirect
|--------------------------------------------------------------------------
*/

it('ignores a redirect parameter pointing off this application', function () {
    $payment = esewaPayment();

    $response = $this->post('/esewa/callback', [
        'data' => esewaCallbackPayload([
            'transaction_uuid' => $payment->transaction_uuid,
            'total_amount' => $payment->total_amount,
        ]),
        'redirect' => 'https://evil.example/phish',
    ]);

    // The payment still settles; the customer just never leaves the site.
    $response->assertStatus(200);

    expect($response->headers->get('Location'))->toBeNull()
        ->and($payment->refresh()->status->isComplete())->toBeTrue();
});

it('honours a redirect parameter that stays on this application', function () {
    $payment = esewaPayment();

    $this->post('/esewa/callback', [
        'data' => esewaCallbackPayload([
            'transaction_uuid' => $payment->transaction_uuid,
            'total_amount' => $payment->total_amount,
        ]),
        'redirect' => '/orders/thanks',
    ])->assertRedirect('/orders/thanks');
});

it('sends the customer to the redirect stored when the payment started', function () {
    $payment = esewaPayment(['meta' => ['success_redirect' => '/orders/9/complete']]);

    $this->post('/esewa/callback', [
        'data' => esewaCallbackPayload([
            'transaction_uuid' => $payment->transaction_uuid,
            'total_amount' => $payment->total_amount,
        ]),
    ])->assertRedirect('/orders/9/complete');
});

it('uses the failure redirect when the payment did not succeed', function () {
    $payment = esewaPayment(['meta' => [
        'success_redirect' => '/orders/9/complete',
        'failure_redirect' => '/orders/9/failed',
    ]]);

    $this->post('/esewa/callback', [
        'data' => esewaCallbackPayload([
            'transaction_uuid' => $payment->transaction_uuid,
            'total_amount' => $payment->total_amount,
            'status' => 'CANCELED',
        ]),
    ])->assertRedirect('/orders/9/failed');
});

it('discards an off-site redirect supplied when the payment started', function () {
    Esewa::pay([
        'amount' => 100,
        'transaction_uuid' => 'ORDER-EVIL',
        'success_url' => 'https://evil.example/phish',
    ]);

    expect(EsewaPayment::query()->forTransaction('ORDER-EVIL')->firstOrFail()->meta)
        ->not->toHaveKey('success_redirect');
});

it('strips an off-site redirect from the relay page', function () {
    $this->get('/esewa/relay/ORDER-1?redirect=https://evil.example')
        ->assertOk()
        ->assertDontSee('evil.example');
});

/*
|--------------------------------------------------------------------------
| Relay
|--------------------------------------------------------------------------
*/

it('forwards the signed payload from the relay to the callback', function () {
    $data = esewaCallbackPayload(['transaction_uuid' => 'RELAY-1']);

    $this->get("/esewa/relay/RELAY-1?data={$data}")
        ->assertOk()
        ->assertSee('id="esewa-relay"', false)
        ->assertSee('action="'.route('esewa.callback').'"', false)
        ->assertSee('name="data"', false)
        ->assertSee('_token', false);
});

it('unpicks the payload when eSewa appends it to an existing query string', function () {
    // eSewa blindly appends "?data=..." to whatever URL it was given, so a
    // redirect that already had a query string arrives as one mangled value.
    $data = esewaCallbackPayload(['transaction_uuid' => 'RELAY-2']);

    $this->get("/esewa/relay/RELAY-2?redirect=/orders/complete?data={$data}")
        ->assertOk()
        ->assertSee('value="/orders/complete"', false)
        ->assertSee("value=\"{$data}\"", false);
});

it('tells the customer plainly when eSewa sent nothing back', function () {
    $this->get('/esewa/relay')->assertStatus(422)->assertSee('cannot be confirmed');
});
