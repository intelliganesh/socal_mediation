<?php

namespace App\Services;

use App\Mail\ConsultationPaymentLinkMail;
use App\Mail\ConsultationPaymentReminderMail;
use App\Models\Consultation;
use App\Models\PaymentRequest;
use Illuminate\Support\Facades\Mail;

class AdminPaymentNotificationService
{
    public function sendPaymentLinks(Consultation $consultation): int
    {
        return $this->sendToUnpaidRequests(
            $consultation,
            ConsultationPaymentLinkMail::class,
            'manual_payment_link'
        );
    }

    public function sendPaymentReminders(Consultation $consultation): int
    {
        return $this->sendToUnpaidRequests(
            $consultation,
            ConsultationPaymentReminderMail::class,
            'manual_payment_reminder'
        );
    }

    private function sendToUnpaidRequests(Consultation $consultation, string $mailable, string $action): int
    {
        $paymentRequests = $consultation->paymentRequests()
            ->with('participant')
            ->whereNot('status', 'paid')
            ->whereNotNull('payment_url')
            ->get();

        $sent = 0;

        foreach ($paymentRequests as $paymentRequest) {
            $recipient = $this->recipientEmail($paymentRequest);

            if ($recipient === null) {
                continue;
            }

            Mail::to($recipient)->send(new $mailable($paymentRequest));

            $paymentRequest->forceFill(['sent_at' => now()])->save();
            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => $action,
                'status' => 'sent',
                'request_payload' => [
                    'recipient' => $recipient,
                    'template' => $mailable,
                    'payment_request_uuid' => $paymentRequest->uuid,
                ],
                'message' => $action === 'manual_payment_reminder'
                    ? 'Manual reminder email sent to unpaid payer.'
                    : 'Payment link email sent to unpaid payer.',
            ]);
            $sent++;
        }

        if ($sent === 0) {
            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => $action,
                'status' => 'skipped',
                'message' => 'No unpaid payment requests with email recipients were found.',
            ]);
        }

        return $sent;
    }

    private function recipientEmail(PaymentRequest $paymentRequest): ?string
    {
        return $paymentRequest->participant?->email
            ?: $paymentRequest->consultation?->primary_email;
    }
}
