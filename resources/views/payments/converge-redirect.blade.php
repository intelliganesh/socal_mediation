<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Secure Payment</title>
</head>
<body style="margin:0;background:#f3f4f7;color:#111827;font-family:Arial,sans-serif;">
    <main style="display:grid;min-height:100vh;place-items:center;padding:24px;">
        <section style="max-width:520px;text-align:center;">
            <h1 style="margin:0 0 12px;font-size:24px;">Opening secure payment</h1>
            <p style="margin:0 0 24px;color:#64748b;line-height:1.6;">You are being redirected to Elavon's secure payment page.</p>
            <form id="converge-payment-form" action="{{ $action }}" method="post" enctype="application/x-www-form-urlencoded">
                <input type="hidden" name="ssl_txn_auth_token" value="{{ $token }}">
                <button type="submit" style="border:0;border-radius:6px;background:#082BC3;color:#fff;cursor:pointer;font-size:16px;font-weight:700;padding:13px 22px;">Continue to secure payment</button>
            </form>
        </section>
    </main>
    <script>document.getElementById('converge-payment-form').submit();</script>
</body>
</html>
