<?php

namespace App\Services;

use App\Mail\ConsultationConfirmationMail;
use App\Mail\ConsultationQuestionnaireMail;
use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use App\Models\QuestionnaireSubmission;
use App\Services\Integrations\OutlookCalendarClient;
use App\Services\Integrations\ZoomClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class QuestionnaireWorkflowService
{
    public function __construct(
        private readonly QuestionnaireTemplateService $templates,
        private readonly ZoomClient $zoom,
        private readonly AdminZoomNotificationService $zoomNotifications,
        private readonly OutlookCalendarClient $outlook,
    ) {}

    public function ensureSubmissions(Consultation $consultation): Collection
    {
        if (! $this->templates->requiresQuestionnaire($consultation)) {
            return collect();
        }

        $template = $this->templates->templateForConsultation($consultation);

        return $consultation->participants()
            ->get()
            ->map(fn (ConsultationParticipant $participant) => $this->ensureSubmission($consultation, $participant, $template));
    }

    public function ensureSubmission(Consultation $consultation, ConsultationParticipant $participant, ?array $template = null): QuestionnaireSubmission
    {
        $template ??= $this->templates->templateForConsultation($consultation);

        return QuestionnaireSubmission::firstOrCreate(
            ['participant_id' => $participant->id],
            [
                'consultation_id' => $consultation->id,
                'template_key' => $template['key'],
                'template_version' => (int) $template['version'],
                'token' => $this->newToken(),
                'status' => 'pending',
            ]
        );
    }

    public function sendQuestionnairesForPaidParticipants(Consultation $consultation): int
    {
        if (! $this->templates->requiresQuestionnaire($consultation)) {
            return 0;
        }

        $consultation->loadMissing(['participants', 'paymentRequests.participant']);
        $totalPayments = $consultation->paymentRequests->count();
        $paidPayments = $consultation->paymentRequests->where('status', 'paid');
        $isFullyPaid = $totalPayments > 0 && $paidPayments->count() === $totalPayments;

        $participants = $isFullyPaid
            ? $consultation->participants
            : $paidPayments->pluck('participant')->filter()->unique('id')->values();

        return $this->sendQuestionnaireLinks($consultation, $participants);
    }

    public function sendQuestionnaireLinks(Consultation $consultation, Collection $participants): int
    {
        $sent = 0;
        $template = $this->templates->templateForConsultation($consultation);

        foreach ($participants as $participant) {
            if (blank($participant->email)) {
                continue;
            }

            $submission = $this->ensureSubmission($consultation, $participant, $template);

            if ($submission->status === 'submitted' || $submission->invited_at !== null) {
                continue;
            }

            Mail::to($participant->email)->send(new ConsultationQuestionnaireMail($submission));

            $submission->update(['invited_at' => now()]);
            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => 'questionnaire_link',
                'status' => 'sent',
                'request_payload' => [
                    'recipient' => $participant->email,
                    'template' => ConsultationQuestionnaireMail::class,
                    'questionnaire_submission_id' => $submission->id,
                    'template_key' => $submission->template_key,
                ],
                'message' => 'Questionnaire link email sent.',
            ]);
            $sent++;
        }

        return $sent;
    }

    public function submitQuestionnaire(QuestionnaireSubmission $submission, array $answers): Consultation
    {
        if ($submission->status === 'submitted') {
            throw new \DomainException('This questionnaire has already been submitted.');
        }

        $submission->loadMissing(['consultation.type', 'consultation.professional', 'participant']);

        $submission->update([
            'answers' => $this->templates->normalizeAnswers($answers),
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $submission->consultation->integrationLogs()->create([
            'provider' => 'questionnaire',
            'action' => 'submission',
            'status' => 'submitted',
            'request_payload' => [
                'participant_id' => $submission->participant_id,
                'template_key' => $submission->template_key,
            ],
            'message' => 'Participant questionnaire submitted.',
        ]);

        $this->releaseIfComplete($submission->consultation->refresh());

        return $submission->consultation->refresh()->load(['type', 'professional', 'participants', 'paymentRequests', 'questionnaireSubmissions']);
    }

    public function acceptAgreement(QuestionnaireSubmission $submission, ?string $ipAddress, ?string $userAgent): Consultation
    {
        $submission->loadMissing(['consultation.type', 'consultation.professional', 'participant']);
        $template = $this->templates->templateForConsultation($submission->consultation);

        if (! ($template['requires_agreement'] ?? false)) {
            throw new \DomainException('Agreement acceptance is not required for this questionnaire.');
        }

        if (! $submission->agreement_accepted) {
            $submission->update([
                'agreement_accepted' => true,
                'agreement_accepted_at' => now(),
                'agreement_version' => QuestionnaireTemplateService::AGREEMENT_VERSION,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);

            $submission->consultation->integrationLogs()->create([
                'provider' => 'questionnaire',
                'action' => 'agreement_acceptance',
                'status' => 'accepted',
                'request_payload' => [
                    'participant_id' => $submission->participant_id,
                    'template_key' => $submission->template_key,
                    'agreement_version' => QuestionnaireTemplateService::AGREEMENT_VERSION,
                ],
                'message' => 'Participant agreement accepted.',
            ]);
        }

        $this->releaseIfComplete($submission->consultation->refresh());

        return $submission->consultation->refresh()->load(['type', 'professional', 'participants', 'paymentRequests', 'questionnaireSubmissions']);
    }

    public function releaseIfComplete(Consultation $consultation): void
    {
        if (! $this->templates->requiresQuestionnaire($consultation)) {
            $this->releaseConsultation($consultation);

            return;
        }

        if ($consultation->payment_status !== 'paid') {
            return;
        }

        $submissions = $this->ensureSubmissions($consultation);

        if ($submissions->isEmpty() || $submissions->contains(function (QuestionnaireSubmission $submission) {
            return $submission->status !== 'submitted'
                || ($this->templates->requiresAgreement($submission->template_key) && ! $submission->agreement_accepted);
        })) {
            return;
        }

        $this->releaseConsultation($consultation);
    }

    public function progress(Consultation $consultation): array
    {
        $submissions = $consultation->relationLoaded('questionnaireSubmissions')
            ? $consultation->questionnaireSubmissions
            : $consultation->questionnaireSubmissions()->get();

        $total = $submissions->count();
        $submitted = $submissions->where('status', 'submitted')->count();
        $agreementRequired = $submissions->contains(fn (QuestionnaireSubmission $submission) => $this->templates->requiresAgreement($submission->template_key));
        $agreementAccepted = $submissions->where('agreement_accepted', true)->count();

        return [
            'required' => $this->templates->requiresQuestionnaire($consultation),
            'total' => $total,
            'submitted' => $submitted,
            'pending' => max(0, $total - $submitted),
            'complete' => $total > 0 && $submitted === $total && (! $agreementRequired || $agreementAccepted === $total),
            'agreement_required' => $agreementRequired,
            'agreement_accepted' => $agreementAccepted,
        ];
    }

    public function questionnaireUrl(QuestionnaireSubmission $submission): ?string
    {
        $application = $submission->consultation?->application;
        $baseUrl = config('app.payment_redirect_urls.'.$application);

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            return null;
        }

        $path = $this->templates->frontendPath($submission->template_key);

        return rtrim($baseUrl, '/').'/'.trim($path, '/').'?token='.$submission->token;
    }

    public function agreementUrl(QuestionnaireSubmission $submission): ?string
    {
        $application = $submission->consultation?->application;
        $baseUrl = config('app.payment_redirect_urls.'.$application);

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            return null;
        }

        return rtrim($baseUrl, '/').'/agreement?token='.$submission->token;
    }

    public function frontendUrl(QuestionnaireSubmission $submission): ?string
    {
        return $this->questionnaireUrl($submission);
    }

    private function releaseConsultation(Consultation $consultation): void
    {
        $metadata = $consultation->metadata ?? [];

        if (isset($metadata['questionnaire_released_at'])) {
            return;
        }

        if ($consultation->consultation_mode === 'online') {
            $this->sendAutomaticZoomLink($consultation);
        } else {
            $this->sendConfirmationEmails($consultation);
            $consultation->update(['status' => 'scheduled']);
        }

        $this->syncPaidConsultationToOutlook($consultation->refresh());
        $metadata = $consultation->refresh()->metadata ?? [];
        $metadata['questionnaire_released_at'] = now()->toIso8601String();
        $consultation->update(['metadata' => $metadata]);
    }

    private function sendAutomaticZoomLink(Consultation $consultation): void
    {
        try {
            if (blank($consultation->zoom_join_url)) {
                $meeting = $this->zoom->createMeeting($consultation);
                $consultation->update([
                    'zoom_meeting_id' => $meeting['id'],
                    'zoom_join_url' => $meeting['join_url'],
                ]);
            }

            $this->zoomNotifications->sendZoomLink($consultation->refresh(), 'automatic_zoom_link');
            $consultation->update(['status' => 'scheduled']);
        } catch (\Throwable $exception) {
            $consultation->integrationLogs()->create([
                'provider' => 'zoom',
                'action' => 'automatic_zoom_link',
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function sendConfirmationEmails(Consultation $consultation): void
    {
        $consultation->loadMissing(['type', 'professional', 'participants']);

        foreach ($consultation->participants as $participant) {
            if (blank($participant->email)) {
                continue;
            }

            Mail::to($participant->email)->send(new ConsultationConfirmationMail($consultation, $participant));
            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => 'automatic_confirmation',
                'status' => 'sent',
                'request_payload' => [
                    'recipient' => $participant->email,
                    'template' => ConsultationConfirmationMail::class,
                ],
                'message' => 'Confirmation email sent after all questionnaires were completed.',
            ]);
        }
    }

    private function syncPaidConsultationToOutlook(Consultation $consultation): void
    {
        if (! config('services.outlook.enabled')) {
            $consultation->integrationLogs()->create([
                'provider' => 'outlook',
                'action' => 'automatic_payment_sync',
                'status' => 'skipped',
                'message' => 'Outlook sync is disabled.',
            ]);

            return;
        }

        try {
            $syncedConsultation = $consultation->refresh()->load(['type', 'professional']);
            $outlookEvent = $this->outlook->syncConsultation($syncedConsultation, 'automatic_payment_sync');

            $consultation->integrationLogs()->create([
                'provider' => 'outlook',
                'action' => 'automatic_payment_sync',
                'status' => 'synced',
                'request_payload' => $this->outlook->consultationEventPayload($syncedConsultation),
                'response_payload' => $outlookEvent->metadata['outlook_response'] ?? $outlookEvent->metadata,
                'message' => 'Booking synced to Outlook after all questionnaires were completed.',
            ]);
        } catch (\Throwable $exception) {
            $consultation->integrationLogs()->create([
                'provider' => 'outlook',
                'action' => 'automatic_payment_sync',
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function newToken(): string
    {
        do {
            $token = Str::random(64);
        } while (QuestionnaireSubmission::where('token', $token)->exists());

        return $token;
    }
}
