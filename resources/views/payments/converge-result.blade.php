@php
    $consultation = $paymentRequest->consultation;
    $isLegal = $consultation?->application === 'legal';
    $brand = $isLegal ? '#75172E' : '#082BC3';
    $success = $state === 'paid';
    $canRetryPayment = $state === 'failed';
    $canRecheckStatus = in_array($state, ['pending', 'error'], true);
    $verificationAttempt = max(0, (int) request()->query('verification_attempt', 0));
    $recheckUrl = route('payments.converge.return.payment', array_filter([
        'paymentRequest' => $paymentRequest,
        'ssl_txn_id' => request()->query('ssl_txn_id'),
        'verification_attempt' => $verificationAttempt + 1,
    ], fn ($value) => $value !== null && $value !== ''));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Payment Status</title>
</head>
<body style="margin:0;background:#f3f4f7;color:#111827;font-family:Arial,sans-serif;">
    <main style="display:grid;min-height:100vh;place-items:center;padding:24px;">
        <section style="width:100%;max-width:620px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;overflow:hidden;">
            <div style="background:{{ $brand }};color:#fff;padding:18px 24px;font-weight:700;">{{ $consultation?->booking_number }}</div>
            <div style="padding:32px 24px;text-align:center;">
                <h1 style="margin:0 0 12px;font-size:26px;">{{ $success ? 'Payment Successful' : 'Payment Status' }}</h1>
                <p style="margin:0;color:#64748b;line-height:1.6;">{{ $message }}</p>
                <p style="margin:22px 0 0;font-size:20px;font-weight:700;">${{ number_format($paymentRequest->amount_cents / 100, 2) }} {{ $paymentRequest->currency }}</p>
                @if($canRetryPayment && filled($paymentRequest->payment_url) && !in_array($consultation?->status, ['draft', 'cancelled'], true))
                    <a href="{{ $paymentRequest->payment_url }}" style="display:inline-block;margin-top:24px;border-radius:6px;background:{{ $brand }};color:#fff;font-weight:700;padding:13px 22px;text-decoration:none;">Try Payment Again</a>
                @elseif($canRecheckStatus)
                    <a href="{{ $recheckUrl }}" style="display:inline-block;margin-top:24px;border-radius:6px;background:{{ $brand }};color:#fff;font-weight:700;padding:13px 22px;text-decoration:none;">Check Payment Status</a>
                @endif
            </div>
        </section>
    </main>
    @if($state === 'pending' && $verificationAttempt < 5)
        <script>
            window.setTimeout(function () {
                window.location.replace(@json($recheckUrl));
            }, 10000);
        </script>
    @endif
</body>
</html>
