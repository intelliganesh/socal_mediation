<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\PaymentRequest;

class BookingFinalizer
{
    public function __construct(
        private readonly QuestionnaireWorkflowService $questionnaires,
    ) {}

    public function markPaidByAdmin(Consultation $consultation, bool $triggerWorkflow = true): Consultation
    {
        $consultation->paymentRequests()
            ->where('status', '!=', 'paid')
            ->get()
            ->each(function (PaymentRequest $payment): void {
                $metadata = array_merge($payment->metadata ?? [], [
                    'manual_payment_status_update' => [
                        'marked_paid_at' => now()->toIso8601String(),
                        'admin_user_id' => auth()->id(),
                    ],
                ]);

                $payment->update([
                    'status' => 'paid',
                    'paid_at' => $payment->paid_at ?? now(),
                    'metadata' => $metadata,
                ]);

                $payment->integrationLogs()->create([
                    'provider' => 'admin',
                    'action' => 'manual_payment_status_update',
                    'status' => 'paid',
                    'request_payload' => [
                        'admin_user_id' => auth()->id(),
                    ],
                    'message' => 'Payment request marked paid from the admin panel.',
                ]);
            });

        if (! $triggerWorkflow) {
            return $consultation->refresh();
        }

        if ($consultation->paymentRequests()->exists()) {
            return $this->syncPaymentStatus($consultation->refresh());
        }

        $consultation->update([
            'payment_status' => 'paid',
            'status' => 'paid',
            'confirmed_at' => $consultation->confirmed_at ?? now(),
        ]);

        $consultation = $consultation->refresh()->load(['participants', 'paymentRequests.participant']);
        $this->questionnaires->sendQuestionnairesAfterManualPayment($consultation);
        $this->questionnaires->releaseIfComplete($consultation);

        return $consultation->refresh();
    }

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
