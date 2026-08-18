<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Questionnaire Summary</title>
    <style>
        body { color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.45; margin: 0; }
        .header { background: {{ $consultation->application === 'legal' ? '#75172E' : '#082BC3' }}; color: #fff; padding: 22px 28px; }
        .content { padding: 24px 28px; }
        h1, h2 { margin: 0; }
        h1 { font-size: 22px; }
        h2 { border-bottom: 1px solid #E5E7EB; font-size: 16px; margin-top: 22px; padding-bottom: 8px; }
        .muted { color: #64748b; }
        .grid { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .grid th, .grid td { border: 1px solid #E5E7EB; padding: 9px; text-align: left; vertical-align: top; }
        .grid th { background: #F7F8FC; font-weight: 700; width: 34%; }
        .answer { white-space: pre-wrap; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $template['label'] ?? 'Questionnaire Summary' }}</h1>
        <div>{{ $consultation->booking_number }} · {{ $consultation->application === 'legal' ? 'Legal Consultation' : 'SoCal Mediation Center' }}</div>
    </div>
    <div class="content">
        <h2>Booking Details</h2>
        <table class="grid">
            <tr><th>Consultation Type</th><td>{{ $consultation->type?->name }}</td></tr>
            <tr><th>Legal Service</th><td>{{ $consultation->legal_service_name ?: 'Not provided' }}</td></tr>
            <tr><th>Schedule</th><td>{{ $consultation->starts_at?->format('M d, Y g:i A') ?? 'Not scheduled' }}</td></tr>
            <tr><th>Professional</th><td>{{ $consultation->professional?->name ?: 'Not assigned' }}</td></tr>
        </table>

        <h2>Participant</h2>
        <table class="grid">
            <tr><th>Name</th><td>{{ trim($participant->first_name.' '.$participant->last_name) ?: 'Not provided' }}</td></tr>
            <tr><th>Email</th><td>{{ $participant->email ?: 'Not provided' }}</td></tr>
            <tr><th>Phone</th><td>{{ trim(($participant->phone_country ?? '').' '.($participant->phone ?? '')) ?: 'Not provided' }}</td></tr>
            <tr><th>Submitted</th><td>{{ $submission->submitted_at?->format('M d, Y g:i A') ?? 'Pending' }}</td></tr>
        </table>

        <h2>Answers</h2>
        <table class="grid">
            @foreach($fields as $field)
                @php($value = data_get($submission->answers ?? [], $field['name']))
                <tr>
                    <th>{{ $field['label'] }}</th>
                    <td class="answer">
                        @if(is_array($value))
                            {{ implode(', ', $value) }}
                        @elseif(is_bool($value))
                            {{ $value ? 'Yes' : 'No' }}
                        @elseif(filled($value))
                            {{ $value }}
                        @else
                            <span class="muted">Not answered</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>

        @if($template['requires_agreement'] ?? false)
            <h2>Agreement Acceptance</h2>
            <table class="grid">
                <tr><th>Accepted</th><td>{{ $submission->agreement_accepted ? 'Yes' : 'No' }}</td></tr>
                <tr><th>Accepted At</th><td>{{ $submission->agreement_accepted_at?->format('M d, Y g:i A') ?? 'Not accepted' }}</td></tr>
                <tr><th>Agreement Version</th><td>{{ $submission->agreement_version ?: config('questionnaires.agreement_version') }}</td></tr>
                <tr><th>IP Address</th><td>{{ $submission->ip_address ?: 'Not recorded' }}</td></tr>
                <tr><th>User Agent</th><td>{{ $submission->user_agent ?: 'Not recorded' }}</td></tr>
            </table>
        @endif
    </div>
</body>
</html>
