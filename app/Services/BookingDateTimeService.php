<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class BookingDateTimeService
{
    public function startsAtFromRequest(string $startsAt): array
    {
        $timezone = config('app.booking_timezone');
        $wallTime = $this->wallTime($startsAt);

        return [
            CarbonImmutable::parse($wallTime, $timezone),
            $timezone,
        ];
    }

    private function wallTime(string $startsAt): string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T\s](\d{2}:\d{2}(?::\d{2})?)/', $startsAt, $matches)) {
            return $matches[1].' '.$matches[2];
        }

        return $startsAt;
    }
}
