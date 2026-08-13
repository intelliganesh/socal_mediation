<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->id,
            'provider' => $this->provider,
            'status' => $this->status,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'provider_reference' => $this->provider_reference,
            'payment_url' => $this->payment_url,
            'checkout_method' => data_get($this->metadata, 'checkout_method'),
            'participant_id' => $this->participant_id,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
