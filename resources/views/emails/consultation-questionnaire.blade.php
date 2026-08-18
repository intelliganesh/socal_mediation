@php
    $consultation = $submission->consultation;
    $participant = $submission->participant;
    $clientName = trim($participant->first_name.' '.($participant->last_name ?? '')) ?: 'Client';
    $template = config('questionnaires.templates.'.$submission->template_key);
@endphp

@include('emails.partials.consultation-card', [
    'consultation' => $consultation,
    'title' => 'Consultation Questionnaire',
    'intro' => 'Hello <strong>'.e($clientName).'</strong>, your payment has been received. Please complete the <strong>'.e($template['label'] ?? 'consultation questionnaire').'</strong> before your meeting details are released.',
    'statusLabel' => 'Questionnaire Required',
    'amountCents' => $participant->share_amount_cents ?: $consultation->total_amount_cents,
    'buttonUrl' => $agreementUrl,
    'buttonLabel' => $agreementUrl ? 'Accept Agreement' : null,
    'secondaryButtonUrl' => $questionnaireUrl,
    'secondaryButtonLabel' => 'Complete Questionnaire',
    'rescheduleButtonUrl' => rtrim(config('app.payment_redirect_urls.' . $consultation->application), '/') . '/reschedule/' . $consultation->id,
    'rescheduleButtonLabel' => 'Reschedule Booking',
])
