<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verifying eSewa Payment</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            background: #f4f6f8;
            color: #111827;
        }
        .card {
            max-width: 420px;
            padding: 2rem;
            border-radius: 14px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            box-shadow: 0 20px 45px -28px rgba(15, 23, 42, 0.4);
            text-align: center;
        }
        .spinner {
            margin: 1.5rem auto;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            border: 4px solid rgba(16, 185, 129, 0.2);
            border-top-color: #10b981;
            animation: spin 0.9s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="card">
        <h1>Finishing up…</h1>
        <p>We received the response from eSewa and are finalising your payment.</p>
        <div class="spinner" aria-hidden="true"></div>
        <p>Please wait a moment. You will be redirected automatically.</p>
        <form id="esewa-relay" method="{{ $method ?? 'POST' }}" action="{{ $action }}">
            @csrf
            @if(!empty($data))
                <input type="hidden" name="data" value="{{ $data }}">
            @endif
            @if(!empty($transactionUuid))
                <input type="hidden" name="transaction_uuid" value="{{ $transactionUuid }}">
            @endif
            @if($redirect ?? false)
                <input type="hidden" name="redirect" value="{{ $redirect }}">
            @endif
        </form>
    </div>
    <script>
        (function () {
            document.getElementById('esewa-relay').submit();
        })();
    </script>
</body>
</html>
