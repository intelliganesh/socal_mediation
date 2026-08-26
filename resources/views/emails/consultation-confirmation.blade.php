@php
    $clientName = trim($participant->first_name.' '.($participant->last_name ?? '')) ?: 'Client';
    $professional = $consultation->professional?->name ?: 'our team';
    $scheduledAt = $participant->scheduled_starts_at ?: $consultation->starts_at;
@endphp

@include('emails.partials.consultation-card', [
    'consultation' => $consultation,
    'title' => $isReschedule ? 'Consultation Rescheduled' : 'Consultation Confirmed',
    'intro' => $isReschedule
        ? 'Hello <strong>'.e($clientName).'</strong>, your consultation with <strong>'.e($professional).'</strong> has been rescheduled. Please find your updated consultation details below.'
        : 'Hello <strong>'.e($clientName).'</strong>, your consultation with <strong>'.e($professional).'</strong> has been confirmed. Please find your consultation details below.',
    'statusLabel' => $isReschedule ? 'Rescheduled' : 'Confirmed',
    'amountCents' => $participant->share_amount_cents ?: $consultation->total_amount_cents,
    'scheduledAt' => $scheduledAt,
])
