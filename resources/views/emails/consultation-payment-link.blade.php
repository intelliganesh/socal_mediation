@php
    $consultation = $paymentRequest->consultation;
    $participant = $paymentRequest->participant;
    $clientName = trim(($participant?->first_name ?? $consultation?->primary_first_name ?? '').' '.($participant?->last_name ?? $consultation?->primary_last_name ?? '')) ?: 'Client';
@endphp

@include('emails.partials.consultation-card', [
    'consultation' => $consultation,
    'title' => $isReschedule ? 'Consultation Rescheduled' : 'Consultation Payment',
    'intro' => $isReschedule
        ? 'Hello <strong>'.e($clientName).'</strong>, your consultation has been rescheduled. Payment is still pending, so please complete payment using the secure link below.'
        : 'Hello <strong>'.e($clientName).'</strong>, your consultation details are ready. Please complete payment using the secure link below.',
    'statusLabel' => $isReschedule ? 'Rescheduled' : 'Payment Pending',
    'amountCents' => $paymentRequest->amount_cents,
    'buttonUrl' => $paymentRequest->payment_url,
    'buttonLabel' => 'Pay Consultation Fee',
    'rescheduleButtonUrl' => rtrim(config('app.payment_redirect_urls.' . $consultation->application), '/') . '/reschedule/' . $consultation->id,
    'rescheduleButtonLabel' => 'Reschedule Booking',
])
