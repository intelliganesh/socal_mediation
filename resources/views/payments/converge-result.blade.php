@php
    $consultation = $paymentRequest->consultation;
    $isLegal = $consultation?->application === 'legal';
    $brand = $isLegal ? '#75172E' : '#082BC3';
    $success = in_array($state, ['paid', 'success'], true);
    $canRetryPayment = in_array($state, ['failed', 'error'], true);
    $redirectUrl = $success
        ? config('app.payment_redirect_urls.'.($isLegal ? 'legal' : 'socal'))
        : null;
    $redirectScheme = is_string($redirectUrl) ? parse_url($redirectUrl, PHP_URL_SCHEME) : null;
    $redirectUrl = filter_var($redirectUrl, FILTER_VALIDATE_URL)
        && in_array(strtolower((string) $redirectScheme), ['http', 'https'], true)
            ? $redirectUrl
            : null;
    $redirectLabel = $isLegal ? 'Continue to Legal Consultation' : 'Continue to SoCal Mediation Center';
    $questionnaireSubmission = $success && $paymentRequest->participant
        ? $paymentRequest->participant
            ->questionnaireSubmissions()
            ->where('consultation_id', $consultation?->id)
            ->first()
        : null;
    $questionnaireUrl = $questionnaireSubmission
        ? app(\App\Services\QuestionnaireWorkflowService::class)->questionnaireUrl($questionnaireSubmission)
        : null;
    $agreementUrl = $questionnaireSubmission && config('questionnaires.templates.'.$questionnaireSubmission->template_key.'.requires_agreement')
        ? app(\App\Services\QuestionnaireWorkflowService::class)->agreementUrl($questionnaireSubmission)
        : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Payment Status</title>
</head>
<body style="margin:0;background:#f3f4f7;color:#111827;font-family:Arial,sans-serif;">
    <main style="display:grid;min-height:100vh;place-items:center;padding:24px;">
        <section style="width:100%;max-width:620px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;overflow:hidden;">
            <div style="background:{{ $brand }};color:#fff;padding:18px 24px;font-weight:700;">{{ $consultation?->booking_number }}</div>
            <div style="padding:32px 24px;text-align:center;">
                <h1 style="margin:0 0 12px;font-size:26px;">{{ $success ? 'Payment Successful' : 'Payment Status' }}</h1>
                <p style="margin:0;color:#64748b;line-height:1.6;">{{ $message }}</p>
                @if(filled($errorReference ?? null))
                    <p style="margin:10px 0 0;color:#64748b;font-size:13px;">Support reference: <strong style="color:#111827;">{{ $errorReference }}</strong></p>
                @endif
                <p style="margin:22px 0 0;font-size:20px;font-weight:700;">${{ number_format($paymentRequest->amount_cents / 100, 2) }} {{ $paymentRequest->currency }}</p>
                @if($questionnaireSubmission && $questionnaireSubmission->status === 'pending' && filled($questionnaireUrl))
                    @if(filled($agreementUrl) && ! $questionnaireSubmission->agreement_accepted)
                        <a href="{{ $agreementUrl }}" style="display:inline-block;margin-top:24px;border-radius:6px;background:{{ $brand }};color:#fff;font-weight:700;padding:13px 22px;text-decoration:none;">Proceed to Agreement</a>
                    @endif
                    <a href="{{ $questionnaireUrl }}" style="display:inline-block;margin-top:{{ filled($agreementUrl) && ! $questionnaireSubmission->agreement_accepted ? '12px' : '24px' }};border-radius:6px;background:{{ filled($agreementUrl) && ! $questionnaireSubmission->agreement_accepted ? '#fff' : $brand }};color:{{ filled($agreementUrl) && ! $questionnaireSubmission->agreement_accepted ? $brand : '#fff' }};border:1px solid {{ $brand }};font-weight:700;padding:13px 22px;text-decoration:none;">Proceed to Questionnaire</a>
                @elseif($questionnaireSubmission && $questionnaireSubmission->status === 'submitted')
                    <p style="margin:18px 0 0;color:#64748b;font-size:14px;">Your questionnaire has already been submitted. Meeting details will be shared after all required questionnaires are complete.</p>
                    @if(filled($redirectUrl))
                        <a href="{{ $redirectUrl }}" style="display:inline-block;margin-top:18px;border-radius:6px;background:{{ $brand }};color:#fff;font-weight:700;padding:13px 22px;text-decoration:none;">{{ $redirectLabel }}</a>
                    @endif
                @elseif(filled($redirectUrl))
                    <a href="{{ $redirectUrl }}" style="display:inline-block;margin-top:24px;border-radius:6px;background:{{ $brand }};color:#fff;font-weight:700;padding:13px 22px;text-decoration:none;">{{ $redirectLabel }}</a>
                @endif
                @if($canRetryPayment && filled($paymentRequest->payment_url) && !in_array($consultation?->status, ['draft', 'cancelled'], true))
                    <a href="{{ $paymentRequest->payment_url }}" style="display:inline-block;margin-top:24px;border-radius:6px;background:{{ $brand }};color:#fff;font-weight:700;padding:13px 22px;text-decoration:none;">Try Payment Again</a>
                @endif
            </div>
        </section>
    </main>
</body>
</html>
