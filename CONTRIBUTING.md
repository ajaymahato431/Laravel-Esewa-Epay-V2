# Contributing

## Getting set up

```bash
git clone https://github.com/ajaymahato/laravel-esewa-epay-v2
cd laravel-esewa-epay-v2
composer install
composer test
```

## Before opening a pull request

```bash
composer check     # style, static analysis and tests
```

Or individually:

```bash
composer test      # pest
composer lint      # pint
composer analyse   # phpstan, level 5
```

## Testing another Laravel version

Testbench pins `laravel/framework`, which replaces the `illuminate/*` components
this package depends on — so pinning testbench is what actually selects the
Laravel version. Pest has to move with it, because each testbench major targets
a different PHPUnit.

| Laravel | Testbench | Pest |
|---|---|---|
| 10 | `8.*` | `^2.36` |
| 11 | `9.*` | `^3.0` |
| 12 | `10.*` | `^3.0` |
| 13 | `11.*` | `^5.0` |

```bash
composer require --dev --no-update "orchestra/testbench:9.*" "pestphp/pest:^3.0"
composer update --prefer-stable
vendor/bin/pest
```

Reset with `git checkout composer.json && composer update`.

If Laravel 10 fails to resolve locally, it is usually Composer refusing old
`laravel/framework` patch releases that carry security advisories. Updating
Composer, or letting it pick `laravel/framework` 10.50+, resolves it.

## Guidelines

- **Every gateway behaviour needs a test.** This package moves money; a
  regression here costs somebody real cash.
- **Never sign a value that was cast rather than read.** The signature covers
  eSewa's own text, not our interpretation of it. `Support\CallbackPayload`
  exists for exactly this, and the suite is pinned to the vectors eSewa
  publishes — if those tests fail, the package is signing something the gateway
  will reject.
- **Amounts go through `Support\Amount`.** No floats, no integer casts.
- **Redirect targets go through `Support\RedirectGuard`.** These routes are
  public.
- **Anything that changes payment state goes through `PaymentManager`,** so it
  inherits the row lock and the fire-once event semantics.
- Follow the existing style; Pint enforces it.

## Supporting a new Laravel release

1. The weekly `future` CI job resolves against `dev-master` and is allowed to
   fail. A red run there is the early warning.
2. When the major ships, add its caret range to every `illuminate/*` constraint
   in `composer.json`.
3. Add the matching testbench major to `require-dev` and a matrix row to
   `.github/workflows/tests.yml`.
4. Note it in the README requirements table and in `CHANGELOG.md`.

No constraint can pre-authorise an unreleased major, so this is a deliberate
step each cycle rather than an open-ended version range.

## Releasing

1. Update `CHANGELOG.md`.
2. Tag with semver. Anything that changes the config shape, the schema or the
   public API is a major.
3. Packagist updates from the webhook.
