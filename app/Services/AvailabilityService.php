<?php
namespace App\Services;

use App\Models\Consultation;
use App\Models\ConsultationType;
use App\Models\ExternalCalendarEvent;
use Carbon\CarbonImmutable;

class AvailabilityService
{
    public function monthAvailability(ConsultationType $type, string $month, ?int $professionalId = null): array
    {
        $timezone = config('app.booking_timezone');
        $start    = CarbonImmutable::parse($month . '-01', $timezone)->startOfMonth();
        $end      = $start->endOfMonth();

        return collect(range(0, $start->diffInDays($end)))
            ->map(fn(int $offset) => $this->daySlots($type, $start->addDays($offset), $professionalId))
            ->values()
            ->all();
    }

    public function dateAvailability(ConsultationType $type, string $date, ?int $professionalId = null): array
    {
        $timezone = config('app.booking_timezone');
        $day      = CarbonImmutable::parse($date, $timezone);

        return $this->daySlots($type, $day, $professionalId);
    }

    public function assertAvailable(ConsultationType $type, CarbonImmutable $startsAt, ?int $professionalId = null, ?string $ignoreConsultationId = null): void
    {
        $endsAt = $startsAt->addMinutes($type->duration_minutes);

        if (! $this->isConfiguredSlot($type, $startsAt, $endsAt)) {
            throw new \DomainException('Selected slot is outside the configured booking hours.');
        }

        if ($this->hasOverlap($startsAt, $endsAt, $professionalId, $ignoreConsultationId)) {
            throw new \DomainException('Selected slot overlaps with an existing booking or Outlook event.');
        }
    }

    private function daySlots(ConsultationType $type, CarbonImmutable $day, ?int $professionalId): array
    {
        if ($day->isWeekend()) {
            return ['date' => $day->toDateString(), 'slots' => []];
        }

        $slots = collect($this->configuredSlotStarts($type))
            ->map(function (string $time) use ($type, $day, $professionalId) {
                $startsAt   = CarbonImmutable::parse($day->toDateString() . ' ' . $time, $day->timezone);
                $endsAt     = $startsAt->addMinutes($type->duration_minutes);
                $workdayEnd = CarbonImmutable::parse(
                    $day->toDateString() . ' ' . config('app.booking_day_end', '17:00'),
                    $day->timezone
                );

                if ($endsAt->greaterThan($workdayEnd)) {
                    return null;
                }

                return [
                    'time'      => $startsAt->format('H:i'),
                    'starts_at' => $startsAt->toIso8601String(),
                    'ends_at'   => $endsAt->toIso8601String(),
                    'available' => ! $this->hasOverlap($startsAt, $endsAt, $professionalId),
                ];
            })
            ->filter()
            ->values();

        return ['date' => $day->toDateString(), 'slots' => $slots->all()];
    }

    private function configuredSlotStarts(ConsultationType $type): array
    {
        $start    = CarbonImmutable::parse(config('app.booking_day_start', '09:00'));
        $end      = CarbonImmutable::parse(config('app.booking_day_end', '17:00'));
        $interval = max(5, $type->duration_minutes);
        $slots    = [];

        for ($slot = $start; $slot->lessThan($end); $slot = $slot->addMinutes($interval)) {
            $slots[] = $slot->format('H:i');
        }

        return $slots;
    }

    private function isConfiguredSlot(ConsultationType $type, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        $workdayEnd = CarbonImmutable::parse(
            $startsAt->toDateString() . ' ' . config('app.booking_day_end', '17:00'),
            $startsAt->timezone
        );

        return $endsAt->lessThanOrEqualTo($workdayEnd)
        && in_array($startsAt->format('H:i'), $this->configuredSlotStarts($type), true);
    }

    private function hasOverlap(CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?int $professionalId, ?string $ignoreConsultationId = null): bool
    {
        $startsAtDatabase = $this->databaseDateTime($startsAt);
        $endsAtDatabase   = $this->databaseDateTime($endsAt);

        $booked = Consultation::query()
            ->whereIn('status', ['payment_pending', 'paid', 'scheduled'])
            ->when($ignoreConsultationId, fn($query) => $query->whereKeyNot($ignoreConsultationId))
            ->where('starts_at', '<', $endsAtDatabase)
            ->where('ends_at', '>', $startsAtDatabase)
            ->exists();

        if ($booked) {
            return true;
        }

        $result = ExternalCalendarEvent::query()
            ->where('is_busy', true)
            ->where('starts_at', '<', $endsAtDatabase)
            ->where('ends_at', '>', $startsAtDatabase)
            ->exists();
        return $result;
    }

    private function databaseDateTime(CarbonImmutable $dateTime): string
    {
        return $dateTime->timezone(config('app.booking_timezone'))->format('Y-m-d H:i:s');
    }
}
