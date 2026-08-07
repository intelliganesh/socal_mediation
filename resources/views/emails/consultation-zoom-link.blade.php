@php
    $clientName = trim($participant->first_name.' '.($participant->last_name ?? '')) ?: 'Client';
    $professional = $consultation->professional?->name ?: 'our team';
@endphp

@include('emails.partials.consultation-card', [
    'consultation' => $consultation,
    'title' => 'Consultation Confirmed',
    'intro' => 'Hello <strong>'.e($clientName).'</strong>, your appointment with <strong>'.e($professional).'</strong> has been successfully scheduled. We have sent a calendar invitation to your email.',
    'statusLabel' => 'Payment Successful',
    'amountCents' => $consultation->total_amount_cents,
    'zoomUrl' => $consultation->zoom_join_url,
    'buttonUrl' => $consultation->zoom_join_url,
    'buttonLabel' => 'Join Zoom Meeting',
    'rescheduleButtonUrl' => rtrim(config('app.frontend_url'), '/').'/reschedule/'.$consultation->id,
    'rescheduleButtonLabel' => 'Reschedule Booking',
])
