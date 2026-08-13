<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\PaymentRequest;
use App\Services\Integrations\ConvergeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentLinkService
{
    public function __construct(
        private readonly ConvergeClient $converge,
        private readonly PaymentReconciliationService $payments,
        private readonly PaymentSimulationService $simulation,
    ) {}

    public function createRequests(Consultation $consultation, string $mode, array $participantIds = [], ?string $method = null): Consultation
    {
        if (! $this->simulation->isActive()) {
            $this->payments->syncLightweight();
        }

        return DB::transaction(function () use ($consultation, $mode, $participantIds, $method) {
            $consultation->paymentRequests()->delete();
            $consultation->participants()->update(['should_pay' => false, 'share_amount_cents' => 0]);

            if ($consultation->total_amount_cents === 0) {
                $consultation->update(['payment_status' => 'paid', 'status' => 'scheduled']);

                return $consultation->refresh();
            }

            $participants = $mode === 'split'
                ? $consultation->participants()->whereIn('id', $participantIds)->get()
                : $consultation->participants()->where('is_primary', true)->get();

            if ($participants->isEmpty()) {
                throw new \DomainException('At least one paying participant is required.');
            }

            if ($mode === 'split' && ! $consultation->type->allows_split_payment) {
                throw new \DomainException('Split payment is not available for this consultation type.');
            }

            $share = intdiv($consultation->total_amount_cents, $participants->count());
            $remainder = $consultation->total_amount_cents % $participants->count();

            $checkoutMethod = $mode === 'full' ? 'checkout_js' : 'hpp_link';

            foreach ($participants->values() as $index => $participant) {
                $amount = $share + ($index === 0 ? $remainder : 0);
                $participant->update(['should_pay' => true, 'share_amount_cents' => $amount]);
                $paymentRequestId = (string) Str::uuid();
                $provider = $this->simulation->isActive()
                    ? $this->simulation->pendingRequest($consultation, $participant, $amount, $method, $paymentRequestId)
                    : $this->converge->createPaymentLink($consultation, $participant, $amount, $method, $paymentRequestId, $checkoutMethod);

                PaymentRequest::create([
                    'id' => $paymentRequestId,
                    'consultation_id' => $consultation->id,
                    'participant_id' => $participant->id,
                    'provider' => $provider['provider'],
                    'amount_cents' => $amount,
                    'currency' => $consultation->currency,
                    'payment_method' => $method,
                    'provider_reference' => $provider['reference'],
                    'payment_url' => $provider['url'],
                    'metadata' => $provider,
                ]);
            }

            $consultation->update([
                'payment_mode' => $mode,
                'payment_status' => 'pending',
                'status' => 'payment_pending',
            ]);

            return $consultation->refresh()->load(['participants', 'paymentRequests']);
        });
    }
}
