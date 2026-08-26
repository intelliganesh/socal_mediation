@php
    $clientName = trim(($consultation->primary_first_name ?? '').' '.($consultation->primary_last_name ?? '')) ?: 'Client';
    $adminUrl = route('admin.consultations.show', $consultation);
@endphp

@include('emails.partials.consultation-card', [
    'consultation' => $consultation,
    'title' => 'New Consultation Request',
    'intro' => 'A new consultation request has been submitted by <strong>'.e($clientName).'</strong>.',
    'statusLabel' => Str::headline($consultation->status),
    'amountCents' => $consultation->total_amount_cents,
    'buttonUrl' => $adminUrl,
    'buttonLabel' => 'Review Consultation',
])
