@php
    $consultation = $paymentRequest->consultation;
    $participant = $paymentRequest->participant;
    $payerName = trim(($participant?->first_name ?? $consultation->primary_first_name ?? 'Client').' '.($participant?->last_name ?? $consultation->primary_last_name ?? ''));
    $isPaid = $paymentRequest->status === 'paid';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demo Payment - {{ $consultation->booking_number }}</title>
    <style>
        body { margin: 0; background: #f3f4f7; color: #111827; font-family: "Open Sans", Arial, sans-serif; }
        .page { min-height: 100vh; display: grid; place-items: center; padding: 24px; }
        .card { width: min(100%, 520px); border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; box-shadow: 0 18px 50px rgba(17, 24, 39, .08); overflow: hidden; }
        .header { padding: 28px; border-bottom: 1px solid #e5e7eb; }
        .eyebrow { color: #082bc3; font-size: 13px; font-weight: 700; text-transform: uppercase; }
        h1 { margin: 8px 0 0; font-size: 26px; line-height: 1.2; }
        .content { padding: 28px; }
        .row { display: flex; justify-content: space-between; gap: 16px; padding: 14px 0; border-bottom: 1px solid #f1f5f9; font-size: 15px; }
        .row span:first-child { color: #64748b; font-weight: 700; }
        .row span:last-child { text-align: right; font-weight: 700; }
        .amount { margin: 24px 0; padding: 18px; border-radius: 10px; background: #f1f6fe; color: #082bc3; font-size: 30px; font-weight: 700; text-align: center; }
        .button { width: 100%; height: 48px; border: 0; border-radius: 10px; background: #082bc3; color: #fff; font-size: 16px; font-weight: 700; cursor: pointer; }
        .button:disabled { background: #22c55e; cursor: default; }
        .notice { margin-bottom: 18px; border-radius: 10px; background: #bbf7d0; color: #166534; padding: 12px 14px; font-weight: 700; }
        .muted { margin-top: 16px; color: #64748b; font-size: 13px; line-height: 1.5; text-align: center; }
    </style>
</head>
<body>
    <main class="page">
        <section class="card">
            <div class="header">
                <div class="eyebrow">Sandbox Payment</div>
                <h1>{{ $consultation->type?->name ?? 'Consultation Payment' }}</h1>
            </div>
            <div class="content">
                @if(session('status'))
                    <div class="notice">{{ session('status') }}</div>
                @endif
                <div class="row"><span>Booking</span><span>{{ $consultation->booking_number }}</span></div>
                <div class="row"><span>Payer</span><span>{{ $payerName ?: 'Client' }}</span></div>
                <div class="row"><span>Status</span><span>{{ str_replace('_', ' ', ucfirst($paymentRequest->status)) }}</span></div>
                <div class="amount">{{ $paymentRequest->currency }} {{ number_format($paymentRequest->amount_cents / 100, 2) }}</div>
                <form method="post" action="{{ route('payments.demo.pay', $paymentRequest) }}">
                    @csrf
                    <button class="button" @disabled($isPaid)>{{ $isPaid ? 'Payment Completed' : 'Pay Demo Amount' }}</button>
                </form>
                <p class="muted">This page is for sandbox/demo testing only. It simulates a successful Converge return for this payment request.</p>
            </div>
        </section>
    </main>
</body>
</html>
