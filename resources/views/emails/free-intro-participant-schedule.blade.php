@php
    $consultation = $participant->consultation;
    $clientName = trim($participant->first_name.' '.($participant->last_name ?? '')) ?: 'Client';
    $baseUrl = rtrim(config('app.frontend_url'), '/');
    $scheduleUrl = $baseUrl.'/free-intro/participants/'.$participant->id.'/schedule?token='.$participant->scheduling_token;
@endphp

@include('emails.partials.consultation-card', [
    'consultation' => $consultation,
    'title' => 'Select Your Intro Call Slot',
    'intro' => 'Hello <strong>'.e($clientName).'</strong>, you have been added to a Free 15-Min Intro Call. Please choose your preferred available 15-minute slot using the secure link below.',
    'statusLabel' => 'Slot Pending',
    'amountCents' => 0,
    'buttonUrl' => $scheduleUrl,
    'buttonLabel' => 'Select Preferred Slot',
])
