<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'consultation_id', 'participant_id', 'provider', 'status', 'amount_cents',
        'currency', 'payment_method', 'provider_reference', 'payment_url', 'sent_at',
        'paid_at', 'metadata',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'paid_at' => 'datetime', 'metadata' => 'array'];
    }

    public function getUuidAttribute(): string
    {
        return $this->id;
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ConsultationParticipant::class, 'participant_id');
    }
}
