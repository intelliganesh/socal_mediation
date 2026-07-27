<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsultationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $paymentRequests = $this->whenLoaded('paymentRequests', fn () => $this->paymentRequests);
        $paidCount = $this->relationLoaded('paymentRequests') ? $this->paymentRequests->where('status', 'paid')->count() : 0;
        $paymentCount = $this->relationLoaded('paymentRequests') ? $this->paymentRequests->count() : 0;

        return [
            'uuid' => $this->id,
            'booking_number' => $this->booking_number,
            'application' => $this->application,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'consultation_mode' => $this->consultation_mode,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'timezone' => $this->timezone,
            'legal_service_name' => $this->legal_service_name,
            'description' => $this->description,
            'referral_source' => $this->referral_source,
            'primary_client' => [
                'first_name' => $this->primary_first_name,
                'last_name' => $this->primary_last_name,
                'email' => $this->primary_email,
                'phone_country' => $this->primary_phone_country,
                'phone' => $this->primary_phone,
            ],
            'total_amount_cents' => $this->total_amount_cents,
            'currency' => $this->currency,
            'payment_mode' => $this->payment_mode,
            'zoom_join_url' => $this->zoom_join_url,
            'type' => new ConsultationTypeResource($this->whenLoaded('type')),
            'professional' => $this->whenLoaded('professional', fn () => $this->professional),
            'participants' => $this->whenLoaded('participants', fn () => $this->participants),
            'payment_requests' => $paymentRequests,
            'payment_progress' => [
                'total' => $paymentCount,
                'paid' => $paidCount,
                'pending' => max(0, $paymentCount - $paidCount),
            ],
        ];
    }
}
