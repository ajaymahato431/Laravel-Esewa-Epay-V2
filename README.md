# Laravel eSewa ePay v2

[![Tests](https://github.com/ajaymahato431/Laravel-Esewa-Epay-V2/actions/workflows/tests.yml/badge.svg)](https://github.com/ajaymahato431/Laravel-Esewa-Epay-V2/actions)
[![Packagist](https://img.shields.io/packagist/v/ajaymahato/laravel-esewa-epay-v2.svg)](https://packagist.org/packages/ajaymahato/laravel-esewa-epay-v2)
[![License](https://img.shields.io/packagist/l/ajaymahato/laravel-esewa-epay-v2.svg)](LICENSE)

Take payments with [eSewa ePay v2](https://developer.esewa.com.np/pages/Epay) in Laravel.

Two lines of your code, and the package handles signing, verification, storage
and reconciliation:

```php
// Send the customer to eSewa
return Esewa::pay(['amount' => $order->total, 'payable' => $order]);

// When they come back
$payment = Esewa::handleCallback($request);
```

**Works on Laravel 10, 11, 12 and 13, on PHP 8.1 through 8.4.**

---

## Why this package

Getting eSewa right is mostly about the ways it can quietly go wrong:

- **Signatures are taken over the gateway's own text.** eSewa sends
  `"total_amount": 1000.0` as a JSON number; decoding it and casting back gives
  `1000`, a different HMAC, and a rejected payment. This package signs the raw
  literal, and its test suite is pinned to the signature eSewa publishes.
- **Amounts carry paisa.** Money is stored as `decimal(12,2)`, so `1234.50` is
  still `1234.50` after the round trip.
- **Callbacks get lost.** Customers close tabs. A reconciliation job and an
  `esewa:reconcile` command ask eSewa what actually happened.
- **Payments must settle exactly once.** State changes are applied under a row
  lock, so a browser callback racing a reconcile job cannot fulfil an order
  twice.
- **The callback URL is public.** Redirect targets are validated, so nobody can
  use your payment flow to bounce customers to their own page.

---

## Installation

```bash
composer require ajaymahato/laravel-esewa-epay-v2
php artisan vendor:publish --tag=esewa-config
php artisan migrate
```

Add your credentials to `.env`:

```dotenv
ESEWA_MODE=uat
ESEWA_PRODUCT_CODE=EPAYTEST
ESEWA_SECRET_KEY="8gBm/:&EnhH.1/q"
```

Those are eSewa's public sandbox values. Replace all three with your merchant
credentials before going live — the package refuses to sign production traffic
with the sandbox key.

> **Sandbox logins:** eSewa ID `9806800001` (through `...05`), password
> `Nepal@123`, MPIN `1122`, token `123456`.

---

## Taking a payment

Call `Esewa::pay()` from your own controller, behind your own auth. It records
the attempt and returns a page that posts straight to eSewa.

```php
use AjayMahato\Esewa\Facades\Esewa;

class CheckoutController extends Controller
{
    public function pay(Order $order)
    {
        $this->authorize('pay', $order);

        return Esewa::pay([
            'amount'  => $order->total,   // 1250, 1250.50 or "1,250.50"
            'payable' => $order,          // links the payment to this order
        ]);
    }
}
```

That is the whole integration. The package generates the transaction id, signs
the request, stores the row and brings the customer back verified.

<details>
<summary>All accepted options</summary>

| Key | Default | Notes |
|---|---|---|
| `amount` | — | Required. Int, float or string. |
| `tax_amount` | `0` | |
| `product_service_charge` | `0` | |
| `product_delivery_charge` | `0` | |
| `total_amount` | sum of the above | Rejected if it does not equal the parts. |
| `transaction_uuid` | generated | Letters, digits and hyphens only. |
| `payable` | `null` | Any Eloquent model. |
| `success_url` | config | Where to land after a verified payment. |
| `failure_url` | config | Where to land otherwise. |
| `meta` | `[]` | Anything else you want stored. |

</details>

### Rendering the form yourself

For a SPA or mobile client, `prepare()` returns the signed fields without a page:

```php
['payment' => $payment, 'endpoint' => $url, 'payload' => $fields] = Esewa::prepare([
    'amount' => $order->total,
]);
```

---

## Reacting to payment

Listen for the event. It fires **once** per payment, whether the news arrived by
browser callback or by reconciliation.

```php
// app/Listeners/FulfilOrder.php
use AjayMahato\Esewa\Events\EsewaPaymentVerified;

class FulfilOrder
{
    public function handle(EsewaPaymentVerified $event): void
    {
        $order = $event->payment->payable;   // the model you passed to pay()

        $order?->update([
            'status'     => 'paid',
            'esewa_ref'  => $event->payment->ref_id,
            'paid_at'    => now(),
        ]);
    }
}
```

Laravel 11+ discovers listeners automatically. On Laravel 10, register it in
`EventServiceProvider`.

| Event | When |
|---|---|
| `EsewaPaymentInitiated` | A payment row was created; nothing is paid yet. |
| `EsewaPaymentVerified` | Confirmed `COMPLETE`. **Fulfil orders here.** |
| `EsewaPaymentFailed` | Cancelled, or the session expired. Release stock here. |
| `EsewaPaymentStatusUpdated` | Any transition, including refunds. |

---

## Where customers come back to

By default eSewa returns to a package route that verifies the payload and then
forwards the customer to your page. Set where that is:

```dotenv
ESEWA_SUCCESS_URL=/orders/thank-you
ESEWA_FAILURE_URL=/orders/failed
```

Or per payment, via `success_url` / `failure_url`.

Only relative paths and your own host are accepted. To allow another domain, add
it to `esewa.redirect.allowed_hosts`.

### Handling the callback in your own route

If you would rather own the URL, point eSewa at your route and call the package:

```php
Route::get('/payment/esewa/return', function (Request $request) {
    $payment = Esewa::handleCallback($request);   // verifies, stores, fires events

    return $payment->status->isComplete()
        ? redirect()->route('orders.show', $payment->payable)
        : redirect()->route('checkout')->withErrors($payment->status->label());
});
```

Set `ESEWA_ROUTES_ENABLED=false` to unregister the package routes entirely.

---

## Reconciliation

A customer who closes the tab never triggers the callback. eSewa's status
endpoint is the authority, so ask it.

**Scheduled sweep** — recommended for everyone:

```php
// routes/console.php (Laravel 11+) or app/Console/Kernel.php
Schedule::command('esewa:reconcile')->everyTenMinutes();
```

It only looks at payments that are still unresolved and at least 15 minutes old,
so it never disturbs a customer who is still paying.

**Per-payment job** — set `ESEWA_AUTO_RECONCILE=true` to queue a delayed check
for every payment. This needs a real queue worker: the `sync` driver ignores
delays and would run the check inline, mid-checkout.

**On demand**, for support staff:

```php
$payment = Esewa::reconcileTransaction('250610-162413');
```

```bash
php artisan esewa:reconcile 250610-162413
```

---

## Querying payments

Add the trait to whatever you charge for:

```php
use AjayMahato\Esewa\Concerns\HasEsewaPayments;

class Order extends Model
{
    use HasEsewaPayments;
}
```

```php
$order->hasCompletedEsewaPayment();   // bool
$order->latestEsewaPayment;           // most recent attempt
$order->esewaPayments;                // every attempt
```

On the payment itself:

```php
$payment->status->isComplete();   // fulfil
$payment->status->isPending();    // PENDING or AMBIGUOUS - check again later
$payment->status->isFailed();     // CANCELED or NOT_FOUND
$payment->status->isRefunded();
$payment->status->isTerminal();   // no further change expected
$payment->status->label();        // customer-facing message
```

Statuses are the seven eSewa documents: `PENDING`, `COMPLETE`, `FULL_REFUND`,
`PARTIAL_REFUND`, `AMBIGUOUS`, `NOT_FOUND`, `CANCELED`. Anything unrecognised
resolves to `PENDING` — never to paid.

> `AMBIGUOUS` means eSewa has the payment on hold. It is deliberately not
> terminal, so reconciliation keeps checking it. Do not fulfil on it.

---

## Testing your integration

Generate a correctly signed callback instead of hand-building an HMAC:

```php
it('marks the order paid', function () {
    $order = Order::factory()->create();

    $this->actingAs($order->user)->post("/checkout/{$order->id}/pay");

    $payment = $order->latestEsewaPayment;

    $this->post('/esewa/callback', [
        'data' => Esewa::signedCallbackPayload([
            'transaction_uuid' => $payment->transaction_uuid,
            'total_amount'     => $payment->total_amount,
            'status'           => 'COMPLETE',
        ]),
    ]);

    expect($order->refresh()->status)->toBe('paid');
});
```

Fake the status endpoint with Laravel's HTTP client:

```php
Http::fake(['*' => Http::response(['status' => 'COMPLETE', 'ref_id' => '0007G36'])]);
```

---

## Configuration

Every option lives in `config/esewa.php` with an explanatory comment. The env
vars:

| Variable | Default | Purpose |
|---|---|---|
| `ESEWA_MODE` | `uat` | `uat` or `production`. |
| `ESEWA_PRODUCT_CODE` | `EPAYTEST` | Merchant code. |
| `ESEWA_SECRET_KEY` | *none* | Required. Signing key. |
| `ESEWA_SUCCESS_URL` | *none* | Default success redirect. |
| `ESEWA_FAILURE_URL` | *none* | Default failure redirect. |
| `ESEWA_ALLOWED_REDIRECT_HOSTS` | *none* | Comma-separated extra hosts. |
| `ESEWA_ROUTES_ENABLED` | `true` | Register package routes. |
| `ESEWA_ROUTE_PREFIX` | `esewa` | URL prefix. |
| `ESEWA_DB_CONNECTION` | default | Connection for the payments table. |
| `ESEWA_DB_TABLE` | `esewa_payments` | Table name. |
| `ESEWA_AUTO_RECONCILE` | `false` | Queue a check per payment. |
| `ESEWA_RECONCILE_DELAY` | `10` | Minutes before that check. |
| `ESEWA_HTTP_TIMEOUT` | `30` | Status-check timeout. |

### Going live

1. `ESEWA_MODE=production`
2. Replace `ESEWA_PRODUCT_CODE` and `ESEWA_SECRET_KEY` with your merchant values.
3. Make sure `APP_URL` is your real domain — redirect safety depends on it.
4. Run a queue worker and schedule `esewa:reconcile`.

---

## Design notes

**There is no route that starts a payment.** Deciding what a customer owes is
your application's job, behind your authorisation. A public endpoint that
accepted an amount would let anyone create payments for any figure.

**eSewa is always sent the package relay, not your success page.** The relay
verifies the signed payload and only then forwards the customer, so nobody
reaches a "thank you" page for a payment that did not complete — including by
typing the URL.

**Payments must be started with `Esewa::pay()`.** A callback is only checked
against an expected amount because that amount was recorded first.

---

## Requirements

| Laravel | PHP |
|---|---|
| 10.x | 8.1 – 8.4 |
| 11.x | 8.2 – 8.4 |
| 12.x | 8.2 – 8.4 |
| 13.x | 8.3 – 8.4 |

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: [SECURITY.md](SECURITY.md).
Upgrading from 1.x: [UPGRADE.md](UPGRADE.md).

## License

[MIT](LICENSE).
