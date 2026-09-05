{{-- Shown when no redirect target is configured, so the customer always sees
     what happened rather than a blank page. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>eSewa payment status</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0; padding: 1rem; background: #f4f6f8; color: #111827;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #0f172a; color: #e2e8f0; }
            .card { background: #1e293b; border-color: #334155; }
            dt { color: #94a3b8; }
        }
        .card {
            max-width: 30rem; margin: 6vh auto; padding: 2.25rem; border-radius: 12px;
            background: #fff; border: 1px solid #e5e7eb;
            box-shadow: 0 18px 45px -30px rgba(15, 23, 42, .45);
        }
        h1 { margin: 0 0 .5rem; font-size: 1.5rem; }
        .ok { color: #16a34a; }
        .fail { color: #dc2626; }
        dl { margin: 1.5rem 0 0; display: grid; grid-template-columns: auto 1fr; gap: .5rem 1rem; }
        dt { font-weight: 600; color: #6b7280; }
        dd { margin: 0; font-variant-numeric: tabular-nums; word-break: break-all; }
    </style>
</head>
<body>
    <div class="card">
        @php($ok = (bool) ($meta['ok'] ?? false))

        <h1 class="{{ $ok ? 'ok' : 'fail' }}">
            {{ $ok ? 'Payment successful' : 'Payment not confirmed' }}
        </h1>

        <p>{{ $meta['message'] ?? 'We could not determine the status of this payment.' }}</p>

        @if ($payment)
            <dl>
                <dt>Transaction</dt>
                <dd>{{ $payment->transaction_uuid }}</dd>

                <dt>Amount</dt>
                <dd>Rs. {{ $payment->total_amount }}</dd>

                <dt>Status</dt>
                <dd>{{ $payment->status->value }}</dd>

                @if ($payment->ref_id)
                    <dt>eSewa reference</dt>
                    <dd>{{ $payment->ref_id }}</dd>
                @endif
            </dl>
        @endif
    </div>
</body>
</html>
