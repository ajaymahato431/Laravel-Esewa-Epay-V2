# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 2.x | Yes |
| 1.x | No — upgrade to 2.0 |

1.x rejected valid eSewa callbacks, truncated amounts to whole rupees, exposed
an open redirect, and registered a public endpoint that created payments from
request input. It should not be used to take money.

## Reporting a vulnerability

Email **mahatoajay9988@gmail.com** with a description, reproduction steps and
the affected versions. Please do not open a public issue.

Expect an acknowledgement within 72 hours and a fix or a timeline within 14
days.

## Notes for integrators

- **Never fulfil an order on anything but `EsewaPaymentVerified`.** A customer
  reaching your success page is not proof of payment — anyone can type that URL.
- **Keep `ESEWA_SECRET_KEY` out of version control.** Anyone holding it can
  forge callbacks. `8gBm/:&EnhH.1/q` is eSewa's public sandbox key and is
  refused when `ESEWA_MODE=production`.
- **Set `APP_URL` to your real domain.** Redirect safety is checked against it.
- **Run the reconciliation schedule.** A lost callback otherwise means a paid
  order that never ships.
- **Do not exempt the callback route from CSRF.** It does not need it: the relay
  posts a token, and the GET path changes state only after verifying a signature
  it cannot forge.
- **Treat `AMBIGUOUS` as unpaid.** eSewa uses it for payments on hold.
