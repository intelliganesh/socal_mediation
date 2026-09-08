@php
    $consultation = $submission->consultation;
    $participant = $submission->participant;
    $clientName = trim($participant->first_name.' '.($participant->last_name ?? '')) ?: 'Client';
    $isLegalApplication = $consultation->application === 'legal';
    $participantCount = $consultation->relationLoaded('participants')
        ? $consultation->participants->count()
        : $consultation->participants()->count();
    $multiParticipantNote = $participantCount > 1
        ? ' Your booking will be confirmed once all required participants complete their forms.'
        : '';
    $formDescription = $isLegalApplication
        ? 'the required intake form'
        : (filled($agreementUrl) ? 'the required agreement and mediation questionnaire' : 'the required mediation questionnaire');
    $pendingFormDescription = $isLegalApplication
        ? 'any pending intake form'
        : (filled($agreementUrl) ? 'any pending agreement or mediation questionnaire' : 'any pending mediation questionnaire');
@endphp

@include('emails.partials.consultation-card', [
    'consultation' => $consultation,
    'title' => $isReschedule ? 'Consultation Rescheduled' : ($isLegalApplication ? 'Consultation Intake Form' : 'Consultation Forms'),
    'intro' => $isReschedule
        ? 'Hello <strong>'.e($clientName).'</strong>, your consultation has been rescheduled. Please complete <strong>'.e($pendingFormDescription).'</strong>.'.$multiParticipantNote
        : 'Hello <strong>'.e($clientName).'</strong>, your payment has been received. Please complete <strong>'.e($formDescription).'</strong>.'.$multiParticipantNote,
    'statusLabel' => $isReschedule ? 'Rescheduled' : ($isLegalApplication ? 'Intake Required' : 'Forms Required'),
    'amountCents' => $participant->share_amount_cents ?: $consultation->total_amount_cents,
    'buttonUrl' => $agreementUrl,
    'buttonLabel' => $agreementUrl ? 'Accept Agreement' : null,
    'secondaryButtonUrl' => $questionnaireUrl,
    'secondaryButtonLabel' => $isLegalApplication ? 'Complete Intake Form' : 'Complete Questionnaire',
    'rescheduleButtonUrl' => rtrim(config('app.payment_redirect_urls.' . $consultation->application), '/') . '/reschedule/' . $consultation->id,
    'rescheduleButtonLabel' => 'Reschedule Booking',
])
