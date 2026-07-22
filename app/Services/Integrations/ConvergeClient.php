<?php

namespace App\Services\Integrations;

use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use Illuminate\Support\Str;

class ConvergeClient
{
    public function createPaymentLink(Consultation $consultation, ConsultationParticipant $participant, int $amountCents, ?string $method): array
    {
        $reference = 'conv_'.Str::lower(Str::random(16));
        $baseUrl = rtrim(config('services.converge.payment_base_url'), '/');

        return [
            'reference' => $reference,
            'url' => $baseUrl.'/pay/'.$reference,
            'mode' => config('services.converge.mode'),
            'gateway_enabled' => (bool) config('services.converge.enabled'),
            'amount_cents' => $amountCents,
            'method' => $method,
            'participant_email' => $participant->email,
        ];
    }
}
