# Laravel eSewa ePay v2

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
   composer require ajaymahato/laravel-esewa-epay-v2
   ```
2. Publish config + migration, then run migrations
   ```bash
   php artisan vendor:publish --tag=esewa-config
   php artisan migrate
   ```
3. Configure your `.env`

   ```dotenv
   ESEWA_MODE=uat                 # uat (testing) or production
   ESEWA_PRODUCT_CODE=EPAYTEST     # merchant code
   ESEWA_SECRET_KEY=8gBm/:&EnhH.1/q

   # Optional overrides
   ESEWA_SUCCESS_URL=https://your-app.com/esewa/success
   ESEWA_FAILURE_URL=https://your-app.com/esewa/failure
   ESEWA_ROUTE_PREFIX=             # set if you want /prefix/esewa/...
   ```

## Quick Start

Create your order/booking as usual. In your controller, generate the UUID, queue the delayed reconciliation job, then return the payment form using the same UUID.

```php
use Illuminate\Support\Str;
use App\Jobs\ReconcileEsewaPaymentJob;

public function payOrder(\App\Models\Order $order)
{
    // Generate a UUID you control (so jobs/admin tools can reference it)
    $uuid = now()->format('ymd-His').'-'.Str::upper(Str::random(4));

    // Schedule a safety-net reconcile in case the browser callback never arrives
    ReconcileEsewaPaymentJob::dispatch($uuid)->delay(now()->addMinutes(8));

    // Return the auto-submitting eSewa form
    return \Esewa::pay([
        'transaction_uuid'        => $uuid,                 // use the same UUID
        'amount'                  => (int) $order->total,
        'total_amount'            => (int) $order->total,
        'tax_amount'              => 0,
        'product_service_charge'  => 0,
        'product_delivery_charge' => 0,
        'meta' => [
            'payable' => ['type' => $order::class, 'id' => $order->id],
        ],
        // Optional overrides:
        // 'success_url' => route('thank.you'),
        // 'failure_url' => route('payment.failed'),
    ]);
}
```

The response is an HTML page with a self-submitting form that posts to the correct eSewa endpoint.

## Handling Verified Payments

When eSewa redirects back, the package verifies the Base64 payload, stores the response in esewa_payments, updates status/ref_id/verified_at, and fires `AjayMahato\Esewa\Events\EsewaPaymentVerified`.

Hook one listener to flip your own record (booking/order/cart) to PAID.

1. Make the listener

```php
php artisan make:listener SetPayablePaid
```

app/Listeners/SetPayablePaid.php

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

Tip: add a tiny helper on your models:

```php
public function isPaid(): bool
{
return $this->payment_status === 'PAID';
}
```

## Reconciliation Safety Nets

Delayed jobs, scheduled sweeps, and manual tools ensure you update stale payments even if callbacks fail.

### A) Delayed job fallback

1. Create the job
   ```bash
   php artisan make:job ReconcileEsewaPaymentJob
   ```
2. Implement the job (`app/Jobs/ReconcileEsewaPaymentJob.php`):

   ```php
   <?php

   namespace App\Jobs;

   use AjayMahato\Esewa\Models\EsewaPayment;
   use AjayMahato\Esewa\Events\EsewaPaymentVerified;
   use Illuminate\Bus\Queueable;
   use Illuminate\Contracts\Queue\ShouldQueue;

   class ReconcileEsewaPaymentJob implements ShouldQueue
   {
       use Queueable;

       public function __construct(public string $uuid) {}

       public function handle(): void
       {
           $payment = EsewaPayment::where('transaction_uuid', $this->uuid)->first();

           if (! $payment || ($payment->status?->value ?? $payment->status) === 'COMPLETE') {
               return; // nothing to do
           }

           $resp = \Esewa::statusCheck(
               $payment->product_code,
               (string) $payment->total_amount,
               $payment->transaction_uuid
           );

           $payment->update([
               'raw_response' => $resp,
               'ref_id'       => $resp['ref_id'] ?? $payment->ref_id,
               'status'       => $resp['status'] ?? $payment->status,
           ]);

           if (($resp['status'] ?? null) === 'COMPLETE') {
               event(new EsewaPaymentVerified($payment->fresh()));
           }
       }
   }
   ```

3. Dispatch it when you start the payment (already shown above). The job should run ~8–10 minutes later and only act if the row is still `PENDING`.

### B) Scheduled sweep (belt-and-suspenders)

1. Generate the command
   ```bash
   php artisan make:command EsewaReconcileCommand
   ```
2. Implement the command (`app/Console/Commands/EsewaReconcileCommand.php`):

   ```php
   <?php

   namespace App\Console\Commands;

   use Illuminate\Console\Command;
   use AjayMahato\Esewa\Models\EsewaPayment;
   use AjayMahato\Esewa\Events\EsewaPaymentVerified;

   class EsewaReconcileCommand extends Command
   {
       protected $signature = 'esewa:reconcile {uuid?}';
       protected $description = 'Reconcile pending eSewa payments (or a single UUID)';

       public function handle(): int
       {
           $query = EsewaPayment::query()->where('status', 'PENDING');

           if ($uuid = $this->argument('uuid')) {
               $query->where('transaction_uuid', $uuid);
           }

           $query->chunkById(100, function ($payments) {
               foreach ($payments as $payment) {
                   $resp = \Esewa::statusCheck(
                       $payment->product_code,
                       (string) $payment->total_amount,
                       $payment->transaction_uuid
                   );

                   $payment->update([
                       'raw_response' => $resp,
                       'ref_id'       => $resp['ref_id'] ?? $payment->ref_id,
                       'status'       => $resp['status'] ?? $payment->status,
                   ]);

                   if (($resp['status'] ?? null) === 'COMPLETE') {
                       event(new EsewaPaymentVerified($payment->fresh()));
                   }
               }
           });

           $this->info('Reconciliation run complete.');
           return self::SUCCESS;
       }
   }
   ```

3. Schedule it (e.g. hourly) in `app/Console/Kernel.php`:
   ```php
   protected function schedule(\Illuminate\Console\Scheduling\Schedule $schedule): void
   {
       $schedule->command('esewa:reconcile')->hourly(); // or everyTenMinutes()
   }
   ```

### C) Manual admin action

Provide customer support with a button to reconcile a single payment on demand.

```php
public function reconcile(string $uuid)
{
    $payment = \AjayMahato\Esewa\Models\EsewaPayment::where('transaction_uuid', $uuid)->firstOrFail();

    $resp = \Esewa::statusCheck(
        $payment->product_code,
        (string) $payment->total_amount,
        $payment->transaction_uuid
    );

    $payment->update([
        'raw_response' => $resp,
        'ref_id'       => $resp['ref_id'] ?? $payment->ref_id,
        'status'       => $resp['status'] ?? $payment->status,
    ]);

    if (($resp['status'] ?? null) === 'COMPLETE') {
        event(new \AjayMahato\Esewa\Events\EsewaPaymentVerified($payment->fresh()));
    }

    return back()->with('status', 'Reconciled.');
}
```

**Recommended setup:** dispatch the delayed job for every payment, keep the scheduled sweep as a backstop, and expose the manual action for support/admin tooling.

## Security Notes

- Request signature order: `total_amount,transaction_uuid,product_code`
- Validate every callback with `signed_field_names` + signature comparison (handled for you)
- Never commit your secret key; keep it in `.env`

## License

Released under the MIT License.
