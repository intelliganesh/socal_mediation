<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsultationTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application' => $this->application,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'max_participants' => $this->max_participants,
            'allows_split_payment' => $this->allows_split_payment,
            'allows_phone' => $this->allows_phone,
            'allows_online' => $this->allows_online,
            'allows_offline' => $this->allows_offline,
        ];
    }
}
