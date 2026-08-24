<?php

namespace App\Services;

use App\Models\Consultation;

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
}
