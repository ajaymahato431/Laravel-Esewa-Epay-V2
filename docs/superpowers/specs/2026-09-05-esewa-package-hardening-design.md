# Design: eSewa ePay v2 package hardening (v2.0)

Date: 2026-09-05
Status: Approved
Package: `ajaymahato/laravel-esewa-epay-v2`
Reference: https://developer.esewa.com.np/pages/Epay

## Goal

Make the package correct against the published eSewa ePay v2 spec, safe to expose
to the public internet, and installable on Laravel 10 through 13 and PHP 8.1
through 8.4. Ship it with the reconciliation and model-binding pieces the README
currently asks every integrator to hand-write.

This is a clean `v2.0`. No backwards-compatibility shims.

## Audit findings this design addresses

### Critical

1. **Callback signature verification rejects eSewa's own documented payload.**
   The spec's example callback carries `"total_amount": 1000.0` as a JSON
   *number*. `EsewaClient::buildSignatureForFields()` rebuilds the signed string
   by interpolating the `json_decode`d value, so PHP renders float `1000.0` as
   `"1000"`.

   Verified against the published vector with the public UAT key:

   | signed string | HMAC-SHA256 base64 |
   |---|---|
   | `...,total_amount=1000.0,...` | `62GcfZTmVkzhtUeh+QJ1AqiJrjoWWGof3U+eTPTZ7fA=` |
   | `...,total_amount=1000,...` (current code) | `L/qUIEhgWonoiBE5fPGBfSndglUjaapYTsNVWVS7PDA=` |

   eSewa publishes `62Gcf...`. Only the raw-literal form matches. String values
   round-trip correctly, which is why the widely reported `"1,000.0"` form
   already works and the numeric form does not.

2. **Money stored as integers.** The migration uses `unsignedBigInteger` and
   `PaymentManager::pay()` casts with `(int)`. `1234.50` becomes `1234`. The
   amount-mismatch guard in `CallbackController` then compares truncated values.

3. **UTF-8 BOMs before `<?php`** in `config/esewa.php`, the migration,
   `form.blade.php`, `callback-status.blade.php`, `tests/Pest.php` and
   `tests/Unit/SignatureTest.php`. These emit bytes before headers; they are
   visible corrupting the current Pest output.

### Security

4. **Open redirect.** `CallbackController::resolveRedirectUrl()` and
   `RelayController` both trust an unvalidated `redirect` input.
5. **Unauthenticated payment creation.** `POST /esewa/pay` is registered on every
   install behind `web` middleware only and forwards `$request->all()` into
   `Esewa::pay()`, so anyone can mint rows with arbitrary amounts and an
   attacker-chosen `success_url`.
6. **Real UAT secret as the config default**, so a production deploy that forgets
   `ESEWA_SECRET_KEY` signs with a publicly known key instead of failing.
7. **Placeholder URLs that are actually used** (`https://your-app.com/...`).
8. **No idempotency guard.** The browser callback and a reconcile job racing can
   both fire `EsewaPaymentVerified`, double-fulfilling an order.

### Compatibility

9. `php: "^8.1|^8.2|^8.3"` blocks PHP 8.4.
10. `illuminate/support: ^10|^11|^12` — no Laravel 13.
11. `orchestra/testbench: ^9.0` and `pest: ^3.0` only — cannot test L10 or L13.
12. **`"minimum-stability": "dev"`** leaks dev-stability resolution into every
    consuming application.
13. Only `illuminate/support` is required, but the code uses Eloquent, Routing,
    the HTTP client, Blade and (now) Console.

### Correctness / DX

14. Production status endpoint is `epay.esewa.com.np`; the spec says
    `esewa.com.np`.
15. The facade resolves through an anonymous-class proxy keyed on the string
    `'ajaymahato.esewa.proxy'` with `method_exists` dispatch — no IDE support, no
    clean way to swap or mock.
16. `Blade::componentNamespace(...'View\Components')` is registered but the
    directory does not exist.
17. Callback route is POST-only, so pointing `ESEWA_SUCCESS_URL` at it yields 405.
18. Routes always registered, migrations not publishable, table name fixed.
19. No `payable` relation; the README hand-rolls `meta['payable']` plus a
    listener containing a syntax error (`'payment_id' =? $payment->id`).
20. README reconcile examples write `'status' => $resp['status']` raw and read
    `ref_id` where the callback sends `transaction_code`.
21. No reconcile job, command, or failure event — the README asks developers to
    write all three.
22. No CI, `phpunit.xml`, `pint.json`, CHANGELOG, UPGRADE, CONTRIBUTING or
    SECURITY. `composer.lock` is committed in a library.

## Architecture

`CallbackController` is currently ~290 lines mixing verification, persistence,
status resolution, redirect policy and view rendering. Responsibilities split as:

| Unit | Responsibility | Depends on |
|---|---|---|
| `Support\Amount` | Normalise `int\|float\|string` to `'1234.50'`; compare | — |
| `Support\CallbackPayload` | Decode base64/JSON preserving raw numeric literals | — |
| `Support\RedirectGuard` | Decide whether a redirect target is safe | config |
| `EsewaClient` | Protocol only: endpoints, sign, verify, status HTTP | the above |
| `PaymentManager` | `pay()`, `handleCallback()`, `reconcile()`, events, locking | client, model |
| `Esewa` | Facade root with real methods | client, manager |
| Controllers | HTTP shape only | manager |

Deleted: `Http/Controllers/StartController.php`.

## Component design

### Support\Amount

- `normalize(int|float|string $value): string` — trims, strips thousands
  separators and currency symbols, validates numeric, formats to exactly two
  decimals. Non-numeric input throws `EsewaException` rather than yielding `0`.
- `equals(mixed $a, mixed $b): bool` — normalises both, compares strings.

### Support\CallbackPayload

- `fromBase64(string $encoded): self` — strict base64 decode, JSON decode,
  retains the raw JSON text.
- `signedFieldNames(): array`, `get(string $field): mixed`, `all(): array`.
- `signatureSource(): string` — builds `field=value,field=value` where the value
  for any field whose decoded type is `int|float|bool|null` is re-read as the
  **literal token** from the raw JSON. Numeric and keyword literals cannot
  contain quotes or escapes, so a targeted pattern match is exact. String values
  use the decoded value, which already round-trips.

### EsewaClient

- `formEndpoint()`, `statusEndpoint()` — mode-aware; unknown mode throws
  `EsewaConfigurationException`.
- `secret()` — throws `EsewaConfigurationException` when the key is empty, or
  when `mode=production` and the key equals the public UAT key.
- `buildRequestSignature(string $totalAmount, string $uuid): string`
- `buildFormPayload(array $params): array` — all amounts through `Amount`,
  `signed_field_names` fixed at `total_amount,transaction_uuid,product_code`.
- `verifyCallback(string $base64): array` — decodes, requires the signed set to
  cover `transaction_uuid`, `total_amount`, `status`, `product_code`
  (anti-downgrade: without it, a captured `status=COMPLETE` signature over a
  trivial field set could be replayed against any transaction), enforces
  `product_code` matches config, then `hash_equals`. Failures throw
  `SignatureVerificationException`.
- `statusCheck(string $productCode, string $totalAmount, string $uuid): array` —
  configurable timeout and retry; non-array or error-shaped responses throw.

### Support\RedirectGuard

`safe(?string $target): ?string`. Relative paths beginning `/` but not `//` are
allowed. Absolute URLs are allowed only when the host matches `app.url` or
appears in `esewa.redirect.allowed_hosts`. Anything else returns `null` and logs
a warning. Applied to the `redirect` input, stored `meta` redirects, and the
`success_url`/`failure_url` given to `pay()`.

### PaymentManager

- `pay(array $params): Response` — validates and normalises amounts, resolves the
  payable, persists the row, fires `EsewaPaymentInitiated`, optionally dispatches
  the delayed reconcile job, renders the auto-submit form.
- `handleCallback(Request|string $data): EsewaPayment` — verify, then persist
  under a transaction with `lockForUpdate()`.
- `reconcile(EsewaPayment $payment): EsewaPayment` — status check, then the same
  locked persistence path. Single source of truth for the controller fallback,
  the job and the command.
- Status transitions fire `EsewaPaymentStatusUpdated` always,
  `EsewaPaymentVerified` on the first entry into `COMPLETE`, and
  `EsewaPaymentFailed` on the first entry into a terminal non-success state.
  Idempotency is enforced by the row lock plus a `verified_at` guard.

### Data

`esewa_payments`:

- `id`, `transaction_uuid` (unique), `product_code`
- `amount`, `tax_amount`, `service_charge`, `delivery_charge`, `total_amount` —
  all `decimal(12, 2)`
- `status` (string, default `PENDING`), `ref_id`, `verified_at`
- `payable_type` (string, nullable), `payable_id` (string, nullable — string so
  UUID primary keys work), indexed together
- `raw_response`, `meta` (json), timestamps
- indexes on `status` and `[payable_type, payable_id]`

Model casts amounts `decimal:2` (string-returning, so no float drift), `status`
to the enum, `verified_at` to datetime, JSON columns to array. Table and
connection come from config.

`Concerns\HasEsewaPayments` gives app models `esewaPayments()`,
`latestEsewaPayment()`, `hasCompletedEsewaPayment()`.

### Config

```php
'mode', 'product_code', 'secret_key',                       // secret has no default
'redirect'       => ['success', 'failure', 'allowed_hosts' => []],
'routes'         => ['enabled' => true, 'prefix' => 'esewa', 'middleware' => ['web']],
'database'       => ['connection' => null, 'table' => 'esewa_payments'],
'reconciliation' => ['auto_dispatch' => false, 'delay' => 10],
'endpoints'      => [...],                                  // production status -> esewa.com.np
'http'           => ['timeout' => 30, 'retry' => ['times' => 2, 'sleep' => 250]],
```

`auto_dispatch` defaults off: Laravel's `sync` queue driver ignores `delay`, so
enabling it by default would run the reconcile inline inside `pay()`.

### Routes

Registered only when `routes.enabled`. Default URIs are unchanged.

- `GET|POST {prefix}/callback` → `esewa.callback`. Accepting GET removes the 405
  a developer hits when pointing `ESEWA_SUCCESS_URL` straight at the callback.
- `GET|POST {prefix}/relay/{transaction?}` → `esewa.relay`

### Developer surface

For developers who point `ESEWA_SUCCESS_URL` at their own route:

```php
$payment = Esewa::handleCallback($request);   // verify + persist + events
```

Also: `Esewa::pay(['payable' => $order, ...])`, `PaymentStatus::isComplete()`,
`isFailed()`, `isTerminal()`, `label()`, four events, `ReconcileEsewaPayment`
job, `esewa:reconcile` command, and `Esewa::signedCallbackPayload([...])` so
integrators can test their own listeners.

## Compatibility

| Laravel | PHP | Testbench |
|---|---|---|
| 10 | ^8.1 | 8.x |
| 11 | ^8.2 | 9.x |
| 12 | ^8.2 | 10.x |
| 13 | ^8.3 | 11.x |

- `php: ^8.1`
- `illuminate/{console,contracts,database,http,routing,support,view}: ^10.0|^11.0|^12.0|^13.0`
- `orchestra/testbench: ^8.0|^9.0|^10.0|^11.0`, `pestphp/pest: ^2.36|^3.0|^4.0|^5.0`
- `minimum-stability: dev` removed; `composer.lock` deleted and gitignored

No constraint can pre-authorise an unreleased major. Forward compatibility is
handled by broad caret ranges plus a scheduled allow-failure CI job resolving
against `dev-master`, which surfaces a Laravel 14 break before users hit it. The
release policy is documented in CONTRIBUTING.

## Testing

Unit: both published eSewa vectors; float, comma-string and integer literal
handling; signed-field downgrade rejection; product-code mismatch; `Amount` edge
cases including `"1,234.50"` and non-numeric rejection; `RedirectGuard`
allow/deny table; enum helpers; configuration exceptions.

Feature: callback over GET, POST and JSON; status-check fallback; event fired
exactly once when callback and reconcile both run; payable morph round-trip;
reconcile job and command; `routes.enabled = false`; decimal round-trip through
the migration.

## Out of scope

Refunds (no public eSewa API), multi-merchant credentials, and a full
`Esewa::fake()` test double. `signedCallbackPayload()` covers the actual testing
need at a fraction of the surface.
