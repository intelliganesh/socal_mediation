@php
    $clientName = trim(($consultation->primary_first_name ?? '').' '.($consultation->primary_last_name ?? '')) ?: 'Client';
    $timezone = $consultation->timezone ?: config('app.booking_timezone');
    $oldSchedule = $oldStartsAt ? $oldStartsAt->timezone($timezone)->format('M d, Y g:i A') : 'Not scheduled';
    $newSchedule = ($newStartsAt ?: $consultation->starts_at)?->timezone($timezone)->format('M d, Y g:i A') ?? 'Not scheduled';
    $adminUrl = route('admin.consultations.show', $consultation);
@endphp

@include('emails.partials.consultation-card', [
    'consultation' => $consultation,
    'title' => 'Consultation Rescheduled',
    'intro' => 'A consultation request for <strong>'.e($clientName).'</strong> has been rescheduled from <strong>'.e($oldSchedule).'</strong> to <strong>'.e($newSchedule).'</strong>.',
    'statusLabel' => 'Rescheduled',
    'amountCents' => $consultation->total_amount_cents,
    'buttonUrl' => $adminUrl,
    'buttonLabel' => 'Review Consultation',
])
