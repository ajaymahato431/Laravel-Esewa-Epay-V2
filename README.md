# Laravel eSewa

Laravel eSewa ePay v2 integration for Laravel 10/11. Generate HMAC signatures, post to the ePay form endpoint, verify callbacks, and record every attempt in your database with a single facade call.

## Features

- Drop-in facade: `return Esewa::pay([...]);` renders an auto-submit payment form
- HMAC-SHA256 (Base64) signing helper for requests and webhook payloads
- Callback verification + event dispatch (`EsewaPaymentVerified`) with DB persistence
- Status check client for reconciliation workflows
- Ships with migration, model, enum, controllers, routes, and Blade view

## Requirements

- PHP 8.1+
- Laravel 10 or 11 (or any app with `illuminate/support` 10/11)
- eSewa merchant credentials for UAT or Production

## Installation

1. Require the package
   ```bash
   composer require ajaymahato/laravel-esewa
   ```
2. Publish config + migration, then run migrations
   ```bash
   php artisan vendor:publish --tag=esewa-config
   php artisan migrate
   ```
3. Configure your `.env`

   ```dotenv
   ESEWA_MODE=uat                 # uat (sandbox) or production
   ESEWA_PRODUCT_CODE=EPAYTEST     # merchant code
   ESEWA_SECRET_KEY=8gBm/:&EnhH.1/q

   # Optional overrides
   ESEWA_SUCCESS_URL=https://your-app.com/esewa/success
   ESEWA_FAILURE_URL=https://your-app.com/esewa/failure
   ESEWA_ROUTE_PREFIX=             # set if you want /prefix/esewa/...
   ```

## Quick Start

1. Create your order/booking as usual.
2. From your controller, return the payment form:
   ```php
   return \Esewa::pay([
       'amount' => (int) $order->total,
       'total_amount' => (int) $order->total,
       'tax_amount' => 0,
       'product_service_charge' => 0,
       'product_delivery_charge' => 0,
       'meta' => [
           'payable' => ['type' => $order::class, 'id' => $order->id],
       ],
       // optional route overrides
       // 'success_url' => route('thank.you'),
       // 'failure_url' => route('payment.failed'),
   ]);
   ```
3. The response is an HTML page with a self-submitting form that posts to the proper eSewa endpoint.

## Package Routes

The service provider registers two POST routes (middleware + prefix pulled from config):

```
POST /esewa/pay       -> StartController@start (internal helper)
POST /esewa/callback  -> CallbackController@handle (eSewa webhook)
```

## Handling Verified Payments

`CallbackController` verifies the Base64 payload, stores the response, updates status, and fires `AjayMahato\Esewa\Events\EsewaPaymentVerified`.

Example listener:

```php
public function handle(\AjayMahato\Esewa\Events\EsewaPaymentVerified $event): void
{
    $payment = $event->payment;
    if (($payment->status?->value ?? $payment->status) !== 'COMPLETE') {
        return;
    }

    $meta = $payment->meta['payable'] ?? null;
    if (! $meta) {
        return;
    }

    $model = app($meta['type'])::find($meta['id']);
    if (! $model) {
        return;
    }

    $model->update([
        'payment_status' => 'PAID',
        'esewa_ref' => $payment->ref_id,
        'paid_at' => now(),
    ]);
}
```

## Status Check Workflow

Use the client if you need to reconcile manually or recover from missed callbacks:

```php
$payment = \AjayMahato\Esewa\Models\EsewaPayment::where('transaction_uuid', $uuid)->firstOrFail();

$response = \Esewa::statusCheck(
    $payment->product_code,
    (string) $payment->total_amount,
    $payment->transaction_uuid,
);

// Optionally persist $response and re-fire EsewaPaymentVerified when status turns COMPLETE.
```

## Security Notes

- Request signature order: `total_amount,transaction_uuid,product_code`
- Validate every callback with `signed_field_names` + signature comparison (handled for you)
- Never commit your secret key; keep it in `.env`

## Local Package Development

Want to test this package inside another Laravel app before publishing?

1. In the consuming app `composer.json` add the path repository:
   ```json
   "repositories": [
     { "type": "path", "url": "../laravel-esewa" }
   ]
   ```
2. Require the package from the path source:
   ```bash
   composer require ajaymahato/laravel-esewa:* --prefer-source
   php artisan vendor:publish --tag=esewa-config
   php artisan migrate
   ```
3. Set the same `.env` keys and call `\Esewa::pay([...])` from your controller.

## Production Launch Checklist

- Switch `ESEWA_MODE=production` and provide live product code + secret key
- Confirm your callback URL is publicly reachable over HTTPS (matching the value configured with eSewa)
- Register listeners for `EsewaPaymentVerified` to sync order status
- Tag a release: `git tag v0.1.0 && git push --tags`
- Submit the repository to Packagist or enable auto-sync webhooks

## License

Released under the MIT License.
