<?php

namespace App\Services;

use App\Mail\ConsultationConfirmationMail;
use App\Models\Consultation;
use Illuminate\Support\Facades\Mail;

class RescheduleNotificationService
{
    public function __construct(
        private readonly AdminPaymentNotificationService $paymentNotifications,
        private readonly QuestionnaireWorkflowService $questionnaires,
    ) {}

    public function sendPendingNextSteps(Consultation $consultation, string $source): array
    {
        $consultation->loadMissing(['participants', 'paymentRequests.participant', 'questionnaireSubmissions']);
        $paymentLinks = 0;
        $questionnaireLinks = 0;

        if ($consultation->payment_status !== 'paid') {
            $paymentLinks = $this->paymentNotifications->sendPaymentLinks($consultation, $source.'_payment_link');
            $questionnaireLinks = $this->questionnaires->sendQuestionnairesForPaidParticipants(
                $consultation,
                true,
                $source.'_questionnaire_link'
            );

            return [
                'payment_links' => $paymentLinks,
                'questionnaire_links' => $questionnaireLinks,
            ];
        }

        if (! $this->questionnaires->isReadyForMeetingRelease($consultation)) {
            $questionnaireLinks = $this->questionnaires->sendQuestionnairesForPaidParticipants(
                $consultation,
                true,
                $source.'_questionnaire_link'
            );

            return [
                'payment_links' => $paymentLinks,
                'questionnaire_links' => $questionnaireLinks,
            ];
        }

        return [
            'payment_links' => $paymentLinks,
            'questionnaire_links' => $questionnaireLinks,
        ];
    }

    public function sendReadyConfirmation(Consultation $consultation, string $source): int
    {
        $consultation->loadMissing(['type', 'professional', 'participants']);
        $sent = 0;

        foreach ($consultation->participants as $participant) {
            if (blank($participant->email)) {
                continue;
            }

            try {
                Mail::to($participant->email)->send(new ConsultationConfirmationMail($consultation, $participant, true));
            } catch (\Throwable $exception) {
                $consultation->integrationLogs()->create([
                    'provider' => 'mail',
                    'action' => $source.'_confirmation',
                    'status' => 'failed',
                    'request_payload' => [
                        'recipient' => $participant->email,
                        'template' => ConsultationConfirmationMail::class,
                        'participant_id' => $participant->id,
                    ],
                    'message' => 'Reschedule confirmation email failed: '.$exception->getMessage(),
                ]);

                continue;
            }

            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => $source.'_confirmation',
                'status' => 'sent',
                'request_payload' => [
                    'recipient' => $participant->email,
                    'template' => ConsultationConfirmationMail::class,
                    'participant_id' => $participant->id,
                ],
                'message' => 'Updated consultation confirmation email sent after reschedule.',
            ]);

            $sent++;
        }

        return $sent;
    }
}
