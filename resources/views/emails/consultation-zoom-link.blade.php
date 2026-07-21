@php
    $clientName = trim($participant->first_name.' '.($participant->last_name ?? '')) ?: 'Client';
@endphp

<p>Hello {{ $clientName }},</p>

<p>Your consultation Zoom meeting link is below.</p>

<p>
    <strong>Booking:</strong> {{ $consultation->booking_number }}<br>
    <strong>Consultation:</strong> {{ $consultation->type?->name }}<br>
    @if($consultation->starts_at)
        <strong>Schedule:</strong> {{ $consultation->starts_at->timezone($consultation->timezone)->format('M d, Y g:i A') }} {{ $consultation->timezone }}<br>
    @endif
</p>

<p><a href="{{ $consultation->zoom_join_url }}">Join Zoom meeting</a></p>

<p>Thank you.</p>
