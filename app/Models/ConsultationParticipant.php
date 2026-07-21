<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsultationParticipant extends Model
{
    protected $fillable = [
        'consultation_id', 'first_name', 'last_name', 'email', 'phone_country', 'phone',
        'is_primary', 'should_pay', 'share_amount_cents',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'should_pay' => 'boolean'];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class, 'participant_id');
    }
}
