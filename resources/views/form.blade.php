{{-- Auto-submitting bridge to the eSewa payment page. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="origin">
    <title>Redirecting to eSewa</title>
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
            max-width: 22rem; padding: 2rem; border-radius: 14px; text-align: center;
            background: #fff; border: 1px solid #e5e7eb;
            box-shadow: 0 20px 45px -28px rgba(15, 23, 42, .4);
        }
        .spinner {
            margin: 1.5rem auto; width: 44px; height: 44px; border-radius: 50%;
            border: 4px solid rgba(96, 189, 74, .25); border-top-color: #60bd4a;
            animation: spin .9s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .spinner { animation: none; } }
        button {
            font: inherit; padding: .7rem 1.4rem; border: 0; border-radius: 8px;
            background: #60bd4a; color: #fff; cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Taking you to eSewa</h1>
        <div class="spinner" aria-hidden="true"></div>
        <p>Do not close this window.</p>

        <form id="esewa-form" method="POST" action="{{ $endpoint }}">
            @foreach ($payload as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <noscript>
                <p>JavaScript is disabled, so please continue manually.</p>
                <button type="submit">Continue to eSewa</button>
            </noscript>
        </form>
    </div>

    <script>document.getElementById('esewa-form').submit();</script>
</body>
</html>
