<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class IntegrationLog extends Model
{
    protected $fillable = [
        'provider', 'action', 'status', 'request_payload', 'response_payload', 'message',
    ];

    protected function casts(): array
    {
        return ['request_payload' => 'array', 'response_payload' => 'array'];
    }

    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }
}
