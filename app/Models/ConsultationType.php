<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsultationType extends Model
{
    use HasFactory;

    protected $fillable = [
        'application', 'name', 'slug', 'description', 'duration_minutes', 'price_cents',
        'currency', 'max_participants', 'allows_split_payment', 'allows_phone',
        'allows_online', 'allows_offline', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'allows_split_payment' => 'boolean',
            'allows_phone' => 'boolean',
            'allows_online' => 'boolean',
            'allows_offline' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }
}
