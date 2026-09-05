{{-- eSewa returns the customer here with a GET; forward the signed payload to
     the callback route as a CSRF-protected POST so it can be verified. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirming your payment</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; margin: 0; background: #f4f6f8; color: #111827;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #0f172a; color: #e2e8f0; }
            .card { background: #1e293b; border-color: #334155; }
        }
        .card {
            max-width: 24rem; padding: 2rem; border-radius: 14px; text-align: center;
            background: #fff; border: 1px solid #e5e7eb;
            box-shadow: 0 20px 45px -28px rgba(15, 23, 42, .4);
        }
        .spinner {
            margin: 1.5rem auto; width: 44px; height: 44px; border-radius: 50%;
            border: 4px solid rgba(16, 185, 129, .2); border-top-color: #10b981;
            animation: spin .9s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .spinner { animation: none; } }
        button {
            font: inherit; padding: .7rem 1.4rem; border: 0; border-radius: 8px;
            background: #10b981; color: #fff; cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Confirming your payment</h1>
        <div class="spinner" aria-hidden="true"></div>
        <p>We are checking this with eSewa. You will be redirected in a moment.</p>

        <form id="esewa-relay" method="POST" action="{{ $action }}">
            @csrf
            @if (! empty($data))
                <input type="hidden" name="data" value="{{ $data }}">
            @endif
            @if (! empty($transactionUuid))
                <input type="hidden" name="transaction_uuid" value="{{ $transactionUuid }}">
            @endif
            @if (! empty($redirect))
                <input type="hidden" name="redirect" value="{{ $redirect }}">
            @endif
            <noscript>
                <p>JavaScript is disabled, so please continue manually.</p>
                <button type="submit">Confirm payment</button>
            </noscript>
        </form>
    </div>

    <script>document.getElementById('esewa-relay').submit();</script>
</body>
</html>
