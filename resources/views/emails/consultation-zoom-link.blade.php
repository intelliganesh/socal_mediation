@php
    $clientName = trim($participant->first_name.' '.($participant->last_name ?? '')) ?: 'Client';
    $professional = $consultation->professional?->name ?: 'our team';
@endphp

@include('emails.partials.consultation-card', [
    'consultation' => $consultation,
    'title' => 'Consultation Confirmed',
    'intro' => "Hello <strong>" . e($clientName) . "</strong>, Thank you for your booking. Your consultation with <strong>" . e($professional) . "</strong> has been confirmed and scheduled successfully. Please find the consultation details below. A calendar invitation and meeting information have been sent to your email for your convenience.",
    'statusLabel' => 'Payment Successful',
    'amountCents' => $consultation->total_amount_cents,
    'zoomUrl' => $consultation->zoom_join_url,
    'buttonUrl' => $consultation->zoom_join_url,
    'buttonLabel' => 'Join Zoom Meeting',
    'rescheduleButtonUrl' => rtrim(config('app.payment_redirect_urls.' . $consultation->application), '/') . '/reschedule/' . $consultation->id,
    'rescheduleButtonLabel' => 'Reschedule Booking',
])
