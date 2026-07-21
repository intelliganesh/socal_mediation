@php
    $consultation = $paymentRequest->consultation;
    $participant = $paymentRequest->participant;
    $clientName = trim(($participant?->first_name ?? $consultation?->primary_first_name ?? '').' '.($participant?->last_name ?? $consultation?->primary_last_name ?? '')) ?: 'Client';
@endphp

<p>Hello {{ $clientName }},</p>

<p>This is a reminder that payment is still pending for your consultation.</p>

<p>
    <strong>Booking:</strong> {{ $consultation?->booking_number }}<br>
    <strong>Amount due:</strong> {{ $paymentRequest->currency }} {{ number_format($paymentRequest->amount_cents / 100, 2) }}<br>
    @if($consultation?->starts_at)
        <strong>Schedule:</strong> {{ $consultation->starts_at->timezone($consultation->timezone)->format('M d, Y g:i A') }} {{ $consultation->timezone }}<br>
    @endif
</p>

<p><a href="{{ $paymentRequest->payment_url }}">Complete payment</a></p>

<p>Thank you.</p>
