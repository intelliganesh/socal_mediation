@php
    $clientName = trim($participant->first_name.' '.($participant->last_name ?? '')) ?: 'Client';
    $professional = $consultation->professional?->name ?: 'our team';
    $scheduledAt = $participant->scheduled_starts_at ?: $consultation->starts_at;
    $timezone = $participant->scheduled_timezone ?: $consultation->timezone ?: config('app.booking_timezone');
    $scheduledText = $scheduledAt ? $scheduledAt->timezone($timezone)->format('M d, Y - g:i A').' '.$timezone : 'the selected date and time';
    $applicationName = $consultation->application === 'legal' ? 'Law Office' : 'SoCal Mediation Center';
    $phoneNumber = config('app.consultation_contact.phone.'.($consultation->application ?? 'socal')) ?: 'the provided phone number';
    $phoneIntro = 'Hello <strong>'.e($clientName).'</strong>, your consultation with <strong>'.e($professional).'</strong> has been '.($isReschedule ? 'rescheduled' : 'confirmed').'. <strong>'.e($professional).'</strong> from '.e($applicationName).' will contact you on '.e($scheduledText).' using '.e($phoneNumber).'. Please find your consultation details below.';
@endphp

@include('emails.partials.consultation-card', [
    'consultation' => $consultation,
    'title' => $isReschedule ? 'Consultation Rescheduled' : 'Consultation Confirmed',
    'intro' => $consultation->consultation_mode === 'phone'
        ? $phoneIntro
        : ($isReschedule
            ? 'Hello <strong>'.e($clientName).'</strong>, your consultation with <strong>'.e($professional).'</strong> has been rescheduled. Please find your updated consultation details below.'
            : 'Hello <strong>'.e($clientName).'</strong>, your consultation with <strong>'.e($professional).'</strong> has been confirmed. Please find your consultation details below.'),
    'statusLabel' => $isReschedule ? 'Rescheduled' : 'Confirmed',
    'amountCents' => $participant->share_amount_cents ?: $consultation->total_amount_cents,
    'scheduledAt' => $scheduledAt,
])
