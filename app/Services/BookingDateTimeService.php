<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class BookingDateTimeService
{
    public function startsAtFromRequest(string $startsAt): array
    {
        $timezone = config('app.booking_timezone');

        return [
            CarbonImmutable::parse($startsAt, $timezone)->timezone($timezone),
            $timezone,
        ];
    }
}
