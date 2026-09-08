@php
    $logoPath = public_path('admin-icons/socal.png');
    $logoDataUri = file_exists($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : null;
    $participantName = trim(($participant->first_name ?? '').' '.($participant->last_name ?? '')) ?: 'Participant';
    $checked = (bool) $submission->agreement_accepted;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Agreement To Mediate/Confidentiality Statement</title>
    <style>
        body { color: #111827; font-family: DejaVu Serif, serif; font-size: 12px; line-height: 1.5; margin: 0; }
        .page { padding: 28px 34px; }
        .logo { margin: 0 auto 14px; text-align: center; }
        .logo img { max-height: 68px; max-width: 270px; }
        h1, h2 { margin: 0; text-align: center; }
        h1 { font-size: 20px; }
        h2 { font-size: 16px; margin-top: 4px; }
        .meta { border-collapse: collapse; margin: 20px 0; width: 100%; }
        .meta th, .meta td { border: 1px solid #D1D5DB; padding: 8px; text-align: left; vertical-align: top; }
        .meta th { background: #F3F6FC; font-family: DejaVu Sans, sans-serif; font-size: 11px; width: 28%; }
        .agreement-list { margin: 16px 0 0; padding: 0; }
        .agreement-row { clear: both; margin-bottom: 10px; page-break-inside: avoid; }
        .number { float: left; font-weight: bold; width: 26px; }
        .text { margin-left: 34px; }
        .confidentiality { border-top: 1px solid #D1D5DB; margin-top: 18px; padding-top: 14px; page-break-inside: avoid; }
        .acceptance { background: #F3F6FC; border: 1px solid #C7D2FE; border-radius: 6px; margin-top: 14px; padding: 12px; page-break-inside: avoid; }
        .checkbox { border: 1.5px solid #111827; display: inline-block; font-family: DejaVu Sans, sans-serif; font-size: 13px; font-weight: bold; height: 16px; line-height: 15px; margin-right: 9px; text-align: center; vertical-align: top; width: 16px; }
        .acceptance-text { display: inline-block; font-weight: bold; width: 92%; }
        .accepted { color: #166534; font-family: DejaVu Sans, sans-serif; font-weight: bold; }
        .pending { color: #92400E; font-family: DejaVu Sans, sans-serif; font-weight: bold; }
        .small { color: #4B5563; font-family: DejaVu Sans, sans-serif; font-size: 10px; }
    </style>
</head>
<body>
    <div class="page">
        <div class="logo">
            @if($logoDataUri)
            <img src="{{ $logoDataUri }}" alt="SoCal Mediation Center">
            @else
            <strong>SoCal Mediation Center</strong>
            @endif
        </div>

        <h1>SoCal Mediation Center (SCMC)</h1>
        <h2>Agreement To Mediate/Confidentiality Statement</h2>

        <table class="meta">
            <tr><th>Booking ID</th><td>{{ $consultation->booking_number }}</td></tr>
            <tr><th>Participant</th><td>{{ $participantName }}</td></tr>
            <tr><th>Email</th><td>{{ $participant->email ?: 'Not provided' }}</td></tr>
            <tr><th>Phone</th><td>{{ trim(($participant->phone_country ?? '').' '.($participant->phone ?? '')) ?: 'Not provided' }}</td></tr>
            {{-- <tr><th>Acceptance Status</th><td><span class="{{ $checked ? 'accepted' : 'pending' }}">{{ $checked ? 'Checked - Agreement accepted' : 'Not accepted' }}</span></td></tr> --}}
            <tr><th>Accepted At</th><td>{{ $submission->agreement_accepted_at?->format('M d, Y g:i A') ?? 'Not recorded' }}</td></tr>
            <tr><th>Agreement Version</th><td>{{ $submission->agreement_version ?: \App\Services\QuestionnaireTemplateService::AGREEMENT_VERSION }}</td></tr>
        </table>

        <div class="agreement-list">
            <div class="agreement-row"><div class="number">1.</div><div class="text">The parties consent to the appointment of a SoCal Mediation Center to act as mediator in this matter.</div></div>
            <div class="agreement-row"><div class="number">2.</div><div class="text">Participation in this dispute resolution process is voluntary and may be terminated by any party or by the mediator at any time.</div></div>
            <div class="agreement-row"><div class="number">3.</div><div class="text">Mediation is a VOLUNTARY process for settlement negotiation. In this context, mediators act as impartial third parties exclusively and the mediator's statements do not constitute legal advice. Each party agrees and acknowledges that no attorney-client third party relationship is created between the party and SCMC or any of the mediators or any other person associated with SCMC.</div></div>
            <div class="agreement-row"><div class="number">4.</div><div class="text">In order to preserve the confidentiality of this mediation, SCMC and the parties to this mediation agree that the provisions of California Evidence Code Sections 703.5 and 1115 through 1128, except for section 1125(a)(5), applies to this mediation. For purposes of confidentiality, this mediation does not end until all Parties and the mediator(s) agree that it has ended. This means that all communications, negotiations, or settlement discussions by and between participants in the course of this mediation shall remain confidential. No writings, evidence of anything said, or admission made for the purpose of, in the course of, or pursuant to this mediation is admissible or subject to discovery, and disclosure of the evidence shall not be compelled, in any arbitration, administrative adjudication, civil action, or other non-criminal proceedings in which, pursuant to law, testimony can be compelled to be given. However, each party further understands and acknowledges that evidence presented during this mediation may be verified outside of the mediation process and used as evidence in subsequent legal proceedings.</div></div>
            <div class="agreement-row"><div class="number">5.</div><div class="text">The mediator shall act as an advocate for the resolution of this dispute and shall use his/her best good faith efforts to assist the parties in reaching a mutually acceptable agreement. Each party agrees that the mediator and SCMC have no liability for any act or omission in connection with this mediation.</div></div>
            <div class="agreement-row"><div class="number">6.</div><div class="text">This Mediation/Confidentiality Agreement shall be admissible in any subsequent proceeding to prove the existence of the agreement and/or enforce said agreement.</div></div>
            <div class="agreement-row"><div class="number">7.</div><div class="text">The neutral person (mediator) has no conflict of interest in this case.</div></div>
            <div class="agreement-row"><div class="number">8.</div><div class="text">For certifiable low-income disputants and per contractor's fee policy, fees may not be accessed to certain disputants. If fees are charged, a copy of the sliding scale is available.</div></div>
            <div class="agreement-row"><div class="number">9.</div><div class="text">Disputants may elect to make their written settlement agreements enforceable or admissible at law.</div></div>
            <div class="agreement-row"><div class="number">10.</div><div class="text">Disputants may offer the testimony of witnesses.</div></div>
            <div class="agreement-row"><div class="number">11.</div><div class="text">Disputants may be represented by counsel during the proceedings, subject to the grantees policy and court rules governing such representation.</div></div>
            <div class="agreement-row"><div class="number">12.</div><div class="text">The mediator has the authority to terminate the dispute resolution proceeding if at any time he/she concludes that any disputant is uninformed or does not understand his/her rights or potential obligations; and it is the mediator's duty to encourage such a disputant to seek qualified legal, financial, or other professional advice.</div></div>
            <div class="agreement-row"><div class="number">13.</div><div class="text">Because of the sensitive nature of confidential mediation discussions, all participants in the mediation, including Parties and their attorneys, agree not to call the mediator(s) or other Center Staff to testify about anything with respect to the mediation, or to subpoena The Center's record, in a later civil or criminal proceeding.</div></div>
            <div class="agreement-row"><div class="number">14.</div><div class="text">The Center for Conflict Resolution shall not reveal information that is provided by participants to third parties without the consent of all participants. However, without disclosing participants' names or other identifying information, the mediator may consult with colleagues about this matter and may describe this matter in publications or in a training/clinical class about mediation.</div></div>
        </div>

        <div class="confidentiality">
            <h2>Confidentiality Statement</h2>
            <p><strong>By signing this agreement, the parties acknowledge that they have read and understand the information contained herein, acknowledge that California Evidence Code Sections 703.5 and 1115 through 1128, excluding 1125(a)(5) applies to this mediation, and acknowledge that it is the intention of the parties that any written settlement agreement prepared in the course of or pursuant to this mediation be admissible, once signed by the settling parties, as provided in the California Evidence Code.</strong></p>
            <div class="acceptance">
                <span class="checkbox">{{ $checked ? '✓' : '' }}</span>
                <span class="acceptance-text">By Accepting this agreement, the parties acknowledge that they have read and understand the information contained herein.</span>
            </div>
            {{-- <p class="small">Acceptance metadata: IP {{ $submission->ip_address ?: 'not recorded' }}; user agent {{ $submission->user_agent ?: 'not recorded' }}.</p> --}}
        </div>
    </div>
</body>
</html>
