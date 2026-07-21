<?php

namespace App\Services\Integrations;

use App\Models\Consultation;
use App\Models\ExternalCalendarEvent;
use Carbon\CarbonImmutable;

class OutlookCalendarClient
{
    public function syncCurrentWindow(): int
    {
        $start = CarbonImmutable::now(config('app.booking_timezone'))->startOfMonth();
        $end = $start->addMonths(3)->endOfMonth();

        ExternalCalendarEvent::updateOrCreate([
            'provider' => 'outlook',
            'external_id' => 'manual-offline-placeholder',
        ], [
            'title' => 'Offline consultation placeholder',
            'starts_at' => $start->addWeek()->setTime(10, 30),
            'ends_at' => $start->addWeek()->setTime(11, 30),
            'is_busy' => true,
            'metadata' => ['synced_window_end' => $end->toDateString(), 'source' => 'local-placeholder'],
        ]);

        return 1;
    }

    public function syncConsultation(Consultation $consultation): ExternalCalendarEvent
    {
        if ($consultation->starts_at === null || $consultation->ends_at === null) {
            throw new \DomainException('Consultation must be scheduled before syncing to Outlook.');
        }

        return ExternalCalendarEvent::updateOrCreate([
            'provider' => 'outlook',
            'external_id' => 'consultation-'.$consultation->uuid,
        ], [
            'professional_id' => $consultation->professional_id,
            'application' => $consultation->application,
            'title' => $consultation->booking_number.' - '.$consultation->type->name,
            'starts_at' => $consultation->starts_at,
            'ends_at' => $consultation->ends_at,
            'is_busy' => true,
            'metadata' => [
                'consultation_uuid' => $consultation->uuid,
                'source' => 'admin_manual_sync',
                'synced_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
