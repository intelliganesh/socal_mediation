<?php

namespace App\Mail\Concerns;

use App\Models\Consultation;
use Illuminate\Mail\Mailables\Address;

trait UsesApplicationMailFrom
{
    private function applicationFrom(?Consultation $consultation): Address
    {
        $application = $consultation?->application;
        $name = $application ? config('mail.application_from_names.'.$application) : null;

        return new Address(
            config('mail.from.address'),
            $name ?: config('mail.from.name')
        );
    }
}
