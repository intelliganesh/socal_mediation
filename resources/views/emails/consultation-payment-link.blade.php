@php
    $consultation = $paymentRequest->consultation;
    $participant = $paymentRequest->participant;
    $clientName = trim(($participant?->first_name ?? $consultation?->primary_first_name ?? '').' '.($participant?->last_name ?? $consultation?->primary_last_name ?? '')) ?: 'Client';
@endphp

@include('emails.partials.consultation-card', [
    'consultation' => $consultation,
    'title' => 'Consultation Payment',
    'intro' => 'Hello <strong>'.e($clientName).'</strong>, your consultation details are ready. Please complete payment using the secure link below.',
    'statusLabel' => 'Payment Pending',
    'amountCents' => $paymentRequest->amount_cents,
    'buttonUrl' => $paymentRequest->payment_url,
    'buttonLabel' => 'Pay Consultation Fee',
    'rescheduleButtonUrl' => rtrim(config('app.frontend_url'), '/').'/reschedule/'.$consultation->id,
    'rescheduleButtonLabel' => 'Reschedule Booking',
])
