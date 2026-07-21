<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Professional extends Model
{
    protected $fillable = ['name', 'title', 'email', 'timezone', 'outlook_calendar_id', 'applications', 'is_active'];

    protected function casts(): array
    {
        return ['applications' => 'array', 'is_active' => 'boolean'];
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }
}
