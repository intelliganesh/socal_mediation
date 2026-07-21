<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegalService extends Model
{
    protected $fillable = ['application', 'name', 'slug', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }
}
