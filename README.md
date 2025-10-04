# Laravel eSewa (by Ajay Mahato)

Laravel integration for **eSewa ePay v2**:
- One-liner from your controller: `return Esewa::pay([...]);`
- Signature (HMAC-SHA256 Base64) generation
- Success callback verification using `signed_field_names`
- Status Check helper
- DB table `esewa_payments` included

## Install

```bash
composer require ajaymahato/laravel-esewa
php artisan vendor:publish --tag=esewa-config
php artisan migrate
```


.env:

```
ESEWA_MODE=uat
ESEWA_PRODUCT_CODE=EPAYTEST
ESEWA_SECRET_KEY=8gBm/:&EnhH.1/q

# optional
ESEWA_SUCCESS_URL=https://your-app.com/esewa/success
ESEWA_FAILURE_URL=https://your-app.com/esewa/failure
ESEWA_ROUTE_PREFIX=
```

Usage (Controller one-liner)

Create your order/booking first. Then:

```
return \Esewa::pay([
  'amount' => (int) $order->total,
  'tax_amount' => 0,
  'product_service_charge' => 0,
  'product_delivery_charge' => 0,
  'total_amount' => (int) $order->total,
  'meta' => [
    'payable' => ['type' => $order::class, 'id' => $order->id],
  ],
  // optional overrides:
  // 'success_url' => route('thank.you'),
  // 'failure_url' => route('payment.failed'),
]);
```


The package’s routes:

```
POST /esewa/pay (internal use by the package’s start controller)

POST /esewa/callback (eSewa calls this; package verifies and updates DB)
```

After success, package dispatches:

```
AjayMahato\Esewa\Events\EsewaPaymentVerified
```


Register a listener to mark your order/booking/cart as paid:

```
public function handle(\AjayMahato\Esewa\Events\EsewaPaymentVerified $event)
{
  $payment = $event->payment;
  if (($payment->status?->value ?? $payment->status) !== 'COMPLETE') return;

  $p = $payment->meta['payable'] ?? null;
  if (!$p) return;

  $model = app($p['type'])::find($p['id']);
  if (!$model) return;

  $model->update([
    'payment_status' => 'PAID',
    'esewa_ref'      => $payment->ref_id,
    'paid_at'        => now(),
  ]);
}
```

Status Check (fallback)
```
$payment = \AjayMahato\Esewa\Models\EsewaPayment::where('transaction_uuid', $uuid)->firstOrFail();

$resp = \Esewa::statusCheck(
  $payment->product_code,
  (string) $payment->total_amount,
  $payment->transaction_uuid
);
// Optionally sync DB + dispatch the same event if COMPLETE.
```

Security

Request signature order: total_amount,transaction_uuid,product_code

Response: rebuild signature using provided signed_field_names

Keep secret key in .env, never commit it.

Production
```
ESEWA_MODE=production
ESEWA_PRODUCT_CODE=YOUR_REAL_CODE
ESEWA_SECRET_KEY=YOUR_REAL_SECRET
```


MIT License.


---

# 14) Local test in a fresh Laravel app

1) In your Laravel app `composer.json`, add path repo to test locally:

```json
"repositories": [
  { "type": "path", "url": "../laravel-esewa" }
]
```


Require it:

```
composer require ajaymahato/laravel-esewa:* --prefer-source
php artisan vendor:publish --tag=esewa-config
php artisan migrate
```


Add .env keys (UAT by default).

In your controller:

```
public function payOrder(\App\Models\Order $order)
{
    return \Esewa::pay([
        'amount' => (int) $order->total,
        'total_amount' => (int) $order->total,
        'meta' => ['payable' => ['type' => $order::class, 'id' => $order->id]],
    ]);
}
```


Register a listener for EsewaPaymentVerified and update the order.

15) Ship it
```
git init
git add .
git commit -m "feat: initial release of ajaymahato/laravel-esewa"
git branch -M main
git remote add origin git@github.com:ajaymahato431/laravel-esewa.git
git push -u origin main

git tag v0.1.0
git push --tags
```


Go to Packagist.org → Submit → paste GitHub URL.

Enable Packagist auto-hooks (or GitHub Packagist integration) so new tags sync.
