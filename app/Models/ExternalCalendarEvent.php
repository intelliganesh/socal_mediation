<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalCalendarEvent extends Model
{
    protected $fillable = [
        'professional_id', 'application', 'provider', 'external_id', 'title',
        'starts_at', 'ends_at', 'is_busy', 'metadata',
    ];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_busy' => 'boolean', 'metadata' => 'array'];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
