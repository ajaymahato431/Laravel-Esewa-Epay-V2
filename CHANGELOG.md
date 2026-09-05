# Changelog

All notable changes to this project are documented here. This project follows
[Semantic Versioning](https://semver.org).

## [2.0.0] - 2026-09-05

A correctness and security release. See [UPGRADE.md](UPGRADE.md).

### Fixed

- **Callback signature verification rejected valid payments.** eSewa sends
  `"total_amount": 1000.0` as a JSON number, and the signature is taken over the
  literal `1000.0`. Decoding to a float and casting it back produced `1000`, a
  different HMAC, and a rejected payment. Signed fields are now read from the
  raw JSON, and the test suite is pinned to the signatures eSewa publishes.
- **Amounts were truncated to whole rupees.** Integer columns and `(int)` casts
  turned `1234.50` into `1234`. Money is now `decimal(12,2)` throughout.
- **UTF-8 BOMs** in six files emitted bytes before headers.
- **Production status endpoint** corrected to `esewa.com.np`.
- Amounts formatted as `1,000.0` or `1000` now compare equal to `1000.00`.
- `guzzlehttp/guzzle` is declared. Without it every status check fatals, since
  `illuminate/http` only suggests it.

### Security

- **Closed an open redirect.** The public callback and relay routes forwarded to
  any `?redirect=` target. Targets are now validated against the application
  host and an allow list.
- **Removed `POST /esewa/pay`,** which let anyone create a payment for any
  amount with an attacker-chosen redirect. Call `Esewa::pay()` from your own
  authorised controller instead.
- **`secret_key` no longer defaults to the published sandbox key,** and is
  refused outright in production mode.
- **Callbacks that sign too few fields are rejected,** closing a replay in which
  a captured `COMPLETE` signature over a trivial field set could be reused
  against another transaction.
- **State changes apply under a row lock,** so a browser callback racing a
  reconciliation job cannot fulfil an order twice.
- Callbacks are verified against the recorded amount and product code.

### Added

- `Esewa::handleCallback()` — verify, store and dispatch in one call, for
  applications that own their own return route.
- `payable` relation on payments, a `HasEsewaPayments` trait, and
  `Esewa::pay(['payable' => $order])`.
- `EsewaPaymentInitiated`, `EsewaPaymentFailed` and `EsewaPaymentStatusUpdated`
  events alongside `EsewaPaymentVerified`.
- `ReconcileEsewaPayment` job and an `esewa:reconcile` command.
- `Esewa::prepare()` for SPA and mobile clients.
- `Esewa::signedCallbackPayload()` for testing integrations.
- `PaymentStatus` helpers: `isComplete()`, `isPending()`, `isFailed()`,
  `isRefunded()`, `isTerminal()` and `label()`.
- Configurable table, connection, route prefix, middleware and route toggle.
- Publishable migrations and views.
- CI across Laravel 10-13 and PHP 8.1-8.4, plus Pint and PHPStan level 5.

### Changed

- **Laravel 13 and PHP 8.4 are supported.** The constraints were previously
  `^8.1|^8.2|^8.3` and `^10.0|^11.0|^12.0`.
- **Removed `"minimum-stability": "dev"`,** which leaked dev-stability
  resolution into every consuming application.
- Declared the `illuminate/*` components actually used, rather than only
  `illuminate/support`.
- The callback route accepts `GET` as well as `POST`, so pointing
  `ESEWA_SUCCESS_URL` at it no longer returns 405.
- The facade resolves to a real class instead of an anonymous `__call` proxy, so
  editors and static analysis can see its methods.
- Config restructured; see the mapping table in UPGRADE.md.
- eSewa is sent the package relay rather than the merchant page, so the payload
  is verified before the customer reaches a success screen.
- Payments must be started with `Esewa::pay()`; callbacks for unknown
  transactions are rejected rather than trusted.
- `vendor/` and `composer.lock` untracked, and a `.gitattributes` keeps tests
  and CI out of the published archive.

### Removed

- `POST /esewa/pay` and `StartController`.
- The dead `Blade::componentNamespace()` registration, which pointed at a
  directory that did not exist.

## [1.x]

Initial releases. Not recommended for production; see Fixed and Security above.
