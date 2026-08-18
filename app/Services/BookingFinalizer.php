<?php

namespace App\Services;

use App\Models\Consultation;

class BookingFinalizer
{
    public function __construct(
        private readonly QuestionnaireWorkflowService $questionnaires,
    ) {}

    public function syncPaymentStatus(Consultation $consultation, bool $deferExternalSync = false): Consultation
    {
        $wasFullyPaid = $consultation->payment_status === 'paid';
        $total = $consultation->paymentRequests()->count();
        $paid = $consultation->paymentRequests()->where('status', 'paid')->count();

        if ($total > 0 && $paid === $total) {
            $updates = ['payment_status' => 'paid', 'status' => 'paid', 'confirmed_at' => now()];

            $consultation->update($updates);
            $this->questionnaires->sendQuestionnairesForPaidParticipants($consultation->refresh()->load(['participants', 'paymentRequests.participant']));

            if (! $wasFullyPaid) {
                $consultation = $consultation->refresh()->load(['type', 'professional', 'participants']);
                if ($deferExternalSync) {
                    $consultationId = $consultation->id;
                    app()->terminating(function () use ($consultationId) {
                        $consultation = Consultation::with(['type', 'professional', 'participants'])->find($consultationId);

                        if ($consultation) {
                            $this->questionnaires->releaseIfComplete($consultation);
                        }
                    });
                } else {
                    $this->questionnaires->releaseIfComplete($consultation);
                }
            }
        } elseif ($paid > 0) {
            $consultation->update(['payment_status' => 'partially_paid', 'status' => 'payment_pending']);
            $this->questionnaires->sendQuestionnairesForPaidParticipants($consultation->refresh()->load(['participants', 'paymentRequests.participant']));
        }

        return $consultation->refresh();
    }
}
