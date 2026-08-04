<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use App\Models\PaymentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentSimulationService
{
    public function __construct(private readonly BookingFinalizer $finalizer) {}

    public function isEnabled(): bool
    {
        return (bool) config('services.payment_simulation.enabled')
            && ! app()->environment('production')
            && config('app.env') !== 'production';
    }

    public function isActive(): bool
    {
        return $this->isEnabled() && ! config('services.converge.enabled');
    }

    public function pendingRequest(
        Consultation $consultation,
        ConsultationParticipant $participant,
        int $amountCents,
        ?string $method,
        string $paymentRequestId,
    ): array {
        return [
            'provider' => 'simulation',
            'reference' => 'sim_'.Str::lower(Str::random(16)),
            'url' => null,
            'mode' => 'simulation',
            'gateway_enabled' => false,
            'amount_cents' => $amountCents,
            'method' => $method,
            'invoice_number' => $paymentRequestId,
            'booking_number' => $consultation->booking_number,
            'participant_email' => $participant->email,
        ];
    }

    public function complete(PaymentRequest $payment): Consultation
    {
        $changed = DB::transaction(function () use ($payment): bool {
            $payment = PaymentRequest::query()->lockForUpdate()->findOrFail($payment->id);
            $consultation = $payment->consultation()->lockForUpdate()->firstOrFail();

            if (in_array($consultation->status, ['draft', 'cancelled'], true)) {
                throw new \DomainException('Draft or cancelled consultations cannot receive simulated payments.');
            }

            if ($payment->status === 'paid') {
                return false;
            }

            $completedAt = now()->toIso8601String();
            $metadata = array_merge($payment->metadata ?? [], [
                'simulation_confirmation' => ['completed_at' => $completedAt],
            ]);

            $payment->update([
                'status' => 'paid',
                'paid_at' => now(),
                'metadata' => $metadata,
            ]);

            $payment->integrationLogs()->create([
                'provider' => 'simulation',
                'action' => 'payment_confirmation',
                'status' => 'paid',
                'response_payload' => [
                    'payment_request_uuid' => $payment->id,
                    'completed_at' => $completedAt,
                ],
                'message' => 'Payment marked paid through the non-production simulation API.',
            ]);

            return true;
        });

        $consultation = $payment->consultation()->firstOrFail();

        if ($changed) {
            $consultation = $this->finalizer->syncPaymentStatus($consultation);
        }

        return $consultation->refresh()->load(['type', 'professional', 'participants', 'paymentRequests']);
    }
}
