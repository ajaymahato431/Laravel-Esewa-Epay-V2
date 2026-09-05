# Upgrading from 1.x to 2.0

Version 2.0 fixes bugs that silently lost money, so some of the changes are not
backwards compatible. Budget about fifteen minutes.

## Why upgrade

- **Callbacks now verify.** eSewa sends `"total_amount": 1000.0` as a JSON
  number. 1.x decoded it and cast it back to `"1000"`, which produced a
  different HMAC and rejected genuine payments.
- **Paisa is no longer lost.** 1.x stored money in integer columns, so
  `1234.50` became `1234`.
- **The open redirect is closed.** In 1.x, `/esewa/callback?redirect=...`
  forwarded anywhere.
- **`POST /esewa/pay` is gone.** In 1.x it was public and took the amount from
  the request.

## 1. Update the requirement

```bash
composer require ajaymahato/laravel-esewa-epay-v2:^2.0
```

## 2. Migrate the table

The amount columns change from integer to `decimal(12,2)`, and a payable
relation is added. If you have existing rows, add a migration:

```php
Schema::table('esewa_payments', function (Blueprint $table) {
    $table->decimal('amount', 12, 2)->change();
    $table->decimal('tax_amount', 12, 2)->default(0)->change();
    $table->decimal('service_charge', 12, 2)->default(0)->change();
    $table->decimal('delivery_charge', 12, 2)->default(0)->change();
    $table->decimal('total_amount', 12, 2)->change();

    $table->string('payable_type')->nullable()->after('verified_at');
    $table->string('payable_id')->nullable()->after('payable_type');
    $table->index(['payable_type', 'payable_id'], 'esewa_payments_payable_index');
});
```

Existing whole-rupee values convert cleanly; no data is lost.

On a fresh install, just publish and migrate:

```bash
php artisan vendor:publish --tag=esewa-migrations
php artisan migrate
```

## 3. Republish the config

The structure changed. Republish and re-enter your values:

```bash
php artisan vendor:publish --tag=esewa-config --force
```

| 1.x | 2.0 |
|---|---|
| `esewa.success_url` | `esewa.redirect.success` |
| `esewa.failure_url` | `esewa.redirect.failure` |
| `esewa.route_prefix` | `esewa.routes.prefix` |
| `esewa.middleware` | `esewa.routes.middleware` |
| — | `esewa.redirect.allowed_hosts` |
| — | `esewa.routes.enabled` |
| — | `esewa.database.*`, `esewa.reconciliation.*` |

`ESEWA_SUCCESS_URL` and `ESEWA_FAILURE_URL` keep their names.

Note that `route_prefix` used to be empty with `/esewa/` hardcoded into each
URI. It now defaults to `esewa`, so the URLs are unchanged.

## 4. Set your secret key

`secret_key` no longer defaults to the published sandbox key. If
`ESEWA_SECRET_KEY` is unset, the package throws instead of signing with a key
everyone knows.

```dotenv
ESEWA_SECRET_KEY="8gBm/:&EnhH.1/q"
```

That is the sandbox value. Use your merchant key in production — it is refused
outright when `ESEWA_MODE=production`.

## 5. Replace `POST /esewa/pay`

Removed. Call the facade from your own authenticated controller:

```php
public function pay(Order $order)
{
    $this->authorize('pay', $order);

    return Esewa::pay(['amount' => $order->total, 'payable' => $order]);
}
```

## 6. Simplify your listener

`meta['payable']` is replaced by a real relation:

```php
// Before
$meta  = $payment->meta['payable'] ?? null;
$model = app($meta['type'])::find($meta['id']);

// After
$order = $event->payment->payable;
```

Add the trait to that model:

```php
use AjayMahato\Esewa\Concerns\HasEsewaPayments;
```

Rows created by 1.x keep their `meta['payable']`; read it directly for those, or
backfill `payable_type` and `payable_id` from it.

## 7. Delete your reconciliation code

The 1.x README asked you to write a job and a command by hand. Both now ship:

```php
Schedule::command('esewa:reconcile')->everyTenMinutes();
```

Delete your `ReconcileEsewaPaymentJob` and `EsewaReconcileCommand`. The shipped
versions also fix two bugs the documented examples had: they wrote the raw
gateway string into the enum column, and read `ref_id` where the callback sends
`transaction_code`.

## 8. Check your redirect targets

Redirects to another domain are now discarded and logged. Add legitimate ones:

```dotenv
ESEWA_ALLOWED_REDIRECT_HOSTS=checkout.example.com,*.example.com
```

Make sure `APP_URL` is correct — the check depends on it.

## Amounts are strings now

`decimal:2` casts return strings, which is what stops paisa drifting.

```php
$payment->total_amount;                                // "1234.50", not 1234
(float) $payment->total_amount;                        // if you need a number
bccomp($payment->total_amount, '1234.50', 2) === 0;    // exact comparison
```

## Other changes

- Payments must be started with `Esewa::pay()`. A callback for an unknown
  transaction is rejected rather than creating a record, because its amount
  could not otherwise be verified.
- The callback route accepts `GET` as well as `POST`.
- Production status endpoint corrected to `esewa.com.np`.
- `Esewa::statusCheck()` throws on a gateway error response instead of returning
  it as if it were a status.
- The facade resolves to a real class, so `Esewa::` autocompletes.
