@php
    $clientName = trim($participant->first_name.' '.($participant->last_name ?? '')) ?: 'Client';
    $professional = $consultation->professional?->name ?: 'our team';
@endphp

@include('emails.partials.consultation-card', [
    'consultation' => $consultation,
    'title' => 'Consultation Cancelled',
    'intro' => 'Hello <strong>'.e($clientName).'</strong>, your consultation with <strong>'.e($professional).'</strong> has been cancelled. No further action is required for this booking. If you have any questions, please contact our team.',
    'statusLabel' => 'Cancelled',
    'amountCents' => $participant->share_amount_cents ?: $consultation->total_amount_cents,
])
