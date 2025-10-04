<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>eSewa Payment Status</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            padding: 0;
            background: #f4f6f8;
            color: #111827;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #0f172a; color: #e2e8f0; }
            .card { background: #1e293b; border-color: #334155; }
            a { color: #38bdf8; }
        }
        .card {
            max-width: 480px;
            margin: 6vh auto;
            padding: 2.25rem;
            border-radius: 12px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            box-shadow: 0 18px 45px -30px rgba(15, 23, 42, 0.45);
        }
        h1 {
            margin-top: 0;
            font-size: 1.75rem;
        }
        .status-ok { color: #16a34a; }
        .status-fail { color: #dc2626; }
        dl {
            margin: 1.5rem 0 0;
        }
        dt {
            font-weight: 600;
            margin-top: 0.75rem;
        }
        dd {
            margin: 0.25rem 0 0;
            word-break: break-all;
        }
        details {
            margin-top: 1.5rem;
            font-size: 0.875rem;
        }
        pre {
            max-height: 320px;
            overflow: auto;
            background: rgba(15, 23, 42, 0.85);
            color: #e2e8f0;
            padding: 1rem;
            border-radius: 8px;
        }
        .redirect-note {
            margin-top: 1.75rem;
            font-size: 0.95rem;
        }
        .muted {
            color: #6b7280;
        }
    </style>
</head>
<body>
    <main class="card">
        @php
            $ok = $meta['ok'] ?? false;
            $message = $meta['message'] ?? ($ok ? 'Payment reconciled successfully.' : 'Payment status could not be confirmed.');
        @endphp
        <h1 class="{{ $ok ? 'status-ok' : 'status-fail' }}">{{ $ok ? 'Payment Complete' : 'Payment Pending' }}</h1>
        <p>{{ $message }}</p>

        @if($payment)
            <dl>
                <dt>Transaction UUID</dt>
                <dd>{{ $payment->transaction_uuid }}</dd>

                <dt>Status</dt>
                <dd>{{ $payment->status?->value ?? $payment->status }}</dd>

                @if($payment->ref_id)
                    <dt>Reference ID</dt>
                    <dd>{{ $payment->ref_id }}</dd>
                @endif

                @if($payment->verified_at)
                    <dt>Verified At</dt>
                    <dd>{{ $payment->verified_at->toDateTimeString() }}</dd>
                @endif
            </dl>
        @endif

        @if($redirectUrl)
            <p class="redirect-note">You will be redirected shortly. If not, <a href="{{ $redirectUrl }}">continue to your site</a>.</p>
            <script>
                setTimeout(function () {
                    window.location.href = "{{ $redirectUrl }}";
                }, 1800);
            </script>
        @endif

        @if($raw)
            <details>
                <summary>Show raw response</summary>
                <pre>@json($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)</pre>
            </details>
        @endif
    </main>
</body>
</html>
