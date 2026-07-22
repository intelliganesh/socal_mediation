<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Consultation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'booking_number', 'consultation_type_id', 'legal_service_name', 'professional_id',
        'application', 'status', 'payment_status', 'consultation_mode', 'timezone', 'starts_at',
        'ends_at', 'description', 'referral_source', 'primary_first_name', 'primary_last_name',
        'primary_email', 'primary_phone_country', 'primary_phone', 'total_amount_cents', 'currency',
        'payment_mode', 'zoom_meeting_id', 'zoom_join_url', 'confirmed_at', 'metadata',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ConsultationType::class, 'consultation_type_id');
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConsultationParticipant::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function integrationLogs(): MorphMany
    {
        return $this->morphMany(IntegrationLog::class, 'loggable');
    }
}
