<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionnaireSubmission extends Model
{
    protected $fillable = [
        'consultation_id',
        'participant_id',
        'template_key',
        'template_version',
        'token',
        'status',
        'answers',
        'agreement_accepted',
        'agreement_accepted_at',
        'agreement_version',
        'ip_address',
        'user_agent',
        'invited_at',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'agreement_accepted' => 'boolean',
            'agreement_accepted_at' => 'datetime',
            'invited_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
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
