<?php

namespace App\Services\Integrations;

use App\Models\Consultation;
use Illuminate\Support\Str;

class ZoomClient
{
    public function createMeeting(Consultation $consultation): array
    {
        $id = (string) random_int(1000000000, 9999999999);

        return [
            'id' => $id,
            'join_url' => rtrim(config('services.zoom.join_base_url'), '/').'/j/'.$id.'?pwd='.Str::random(12),
        ];
    }
}
