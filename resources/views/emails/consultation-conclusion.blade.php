@php
    $clientName = trim($participant->first_name.' '.($participant->last_name ?? '')) ?: 'Client';
    $professional = $consultation->professional?->name ?: 'our team';
@endphp

@include('emails.partials.consultation-card', [
    'consultation' => $consultation,
    'title' => 'Consultation Completed',
    'intro' => 'Hello <strong>'.e($clientName).'</strong>, your consultation with <strong>'.e($professional).'</strong> has been marked completed. Thank you for working with us.',
    'statusLabel' => 'Completed',
    'amountCents' => $participant->share_amount_cents ?: $consultation->total_amount_cents,
])
