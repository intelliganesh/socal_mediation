<?php

namespace App\Services\Integrations;

use App\Models\Consultation;
use App\Models\ExternalCalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

class OutlookCalendarClient
{
    public function syncCurrentWindow(): int
    {
        $this->assertEnabled();

        $start = CarbonImmutable::now(config('app.booking_timezone'))->startOfMonth();
        $end = $start->addMonths(3)->endOfMonth();

        return $this->syncWindow($start, $end);
    }

    public function syncFutureConsultations(?CarbonImmutable $from = null): array
    {
        $this->assertEnabled();

        $from ??= CarbonImmutable::now(config('app.booking_timezone'))->startOfDay();
        $activeStatuses = ['scheduled', 'paid', 'payment_pending', 'pending_payment', 'partially_paid'];
        $fromDatabase = $from->timezone(config('app.booking_timezone'))->format('Y-m-d H:i:s');
        $consultations = Consultation::query()
            ->with(['type', 'professional'])
            ->whereNotNull('starts_at')
            ->whereIn('status', $activeStatuses)
            ->where('starts_at', '>=', $fromDatabase)
            ->orderBy('starts_at')
            ->get();

        $synced = 0;
        foreach ($consultations as $consultation) {
            $this->syncConsultation($consultation, 'future_consultation_sync');
            $synced++;
        }

        $activeExternalIds = $consultations
            ->map(fn (Consultation $consultation) => 'consultation-'.$consultation->id)
            ->all();

        $deleted = 0;
        ExternalCalendarEvent::query()
            ->where('provider', 'outlook')
            ->where('external_id', 'like', 'consultation-%')
            ->where('starts_at', '>=', $fromDatabase)
            ->when($activeExternalIds !== [], fn ($query) => $query->whereNotIn('external_id', $activeExternalIds))
            ->get()
            ->each(function (ExternalCalendarEvent $event) use (&$deleted) {
                $this->deleteConsultationEvent($event);
                $deleted++;
            });

        return ['synced' => $synced, 'deleted' => $deleted];
    }

    public function syncMonth(string $month): int
    {
        $this->assertEnabled();

        $start = CarbonImmutable::parse($month.'-01', config('app.booking_timezone'))->startOfMonth();
        $end = $start->endOfMonth();

        return $this->syncWindow($start, $end);
    }

    private function syncWindow(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $count = 0;

        foreach (['socal', 'legal'] as $application) {
            foreach ($this->calendarView($application, $start, $end) as $event) {
                if (($event['isCancelled'] ?? false) || ! ($event['showAs'] ?? null) || ($event['showAs'] ?? 'busy') === 'free') {
                    continue;
                }

                ExternalCalendarEvent::updateOrCreate([
                    'provider' => 'outlook',
                    'external_id' => $event['id'],
                ], [
                    'application' => $application,
                    'title' => $event['subject'] ?? 'Outlook busy event',
                    'starts_at' => $this->graphDateTime(data_get($event, 'start.dateTime'), data_get($event, 'start.timeZone')),
                    'ends_at' => $this->graphDateTime(data_get($event, 'end.dateTime'), data_get($event, 'end.timeZone')),
                    'is_busy' => true,
                    'metadata' => [
                        'outlook_web_link' => $event['webLink'] ?? null,
                        'synced_window_start' => $start->toDateString(),
                        'synced_window_end' => $end->toDateString(),
                    ],
                ]);

                $count++;
            }
        }

        return $count;
    }

    public function syncConsultation(Consultation $consultation, string $source = 'admin_manual_sync'): ExternalCalendarEvent
    {
        $this->assertEnabled();

        if ($consultation->starts_at === null || $consultation->ends_at === null) {
            throw new \DomainException('Consultation must be scheduled before syncing to Outlook.');
        }

        $payload = $this->consultationEventPayload($consultation);
        $existing = ExternalCalendarEvent::where('provider', 'outlook')
            ->where('external_id', 'consultation-'.$consultation->id)
            ->first();

        if ($existing?->metadata['outlook_event_id'] ?? false) {
            try {
                $event = $this->patchEvent($consultation->application, $existing->metadata['outlook_event_id'], $payload);
            } catch (\RuntimeException $exception) {
                if (! str_contains($exception->getMessage(), '"ErrorItemNotFound"')) {
                    throw $exception;
                }

                $event = $this->createEvent($consultation->application, $payload);
            }
        } else {
            $event = $this->createEvent($consultation->application, $payload);
        }

        return ExternalCalendarEvent::updateOrCreate([
            'provider' => 'outlook',
            'external_id' => 'consultation-'.$consultation->id,
        ], [
            'professional_id' => $consultation->professional_id,
            'application' => $consultation->application,
            'title' => $payload['subject'],
            'starts_at' => $consultation->starts_at,
            'ends_at' => $consultation->ends_at,
            'is_busy' => true,
            'metadata' => [
                'consultation_uuid' => $consultation->id,
                'outlook_event_id' => $event['id'] ?? null,
                'outlook_web_link' => $event['webLink'] ?? null,
                'outlook_response' => $this->eventResponsePayload($event),
                'source' => $source,
                'synced_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function recreateConsultationEvent(Consultation $consultation, string $source = 'reschedule_sync'): ExternalCalendarEvent
    {
        $this->assertEnabled();

        $this->deleteConsultationEvent($consultation);

        return $this->syncConsultation($consultation, $source);
    }

    public function createSmokeTestEvent(string $application = 'socal'): string
    {
        $this->assertEnabled();

        $start = CarbonImmutable::now(config('app.booking_timezone'))->addDays(7)->setTime(8, 0);
        $event = $this->createEvent($application, [
            'subject' => 'Codex Outlook Sync Smoke Test',
            'body' => [
                'contentType' => 'Text',
                'content' => 'Temporary event created by Codex to verify Outlook sync credentials. Delete immediately.',
            ],
            'start' => [
                'dateTime' => $start->format('Y-m-d\TH:i:s'),
                'timeZone' => config('app.booking_timezone'),
            ],
            'end' => [
                'dateTime' => $start->addMinutes(15)->format('Y-m-d\TH:i:s'),
                'timeZone' => config('app.booking_timezone'),
            ],
            'showAs' => 'busy',
        ]);

        return $event['id'];
    }

    public function deleteEvent(string $application, string $eventId): void
    {
        $this->assertEnabled();

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->delete($this->calendarUrl($application).'/events/'.$eventId);

        if ($response->failed() && $response->status() !== 404) {
            throw new \RuntimeException('Outlook event deletion failed: '.$response->body());
        }
    }

    public function deleteConsultationEvent(Consultation|ExternalCalendarEvent $consultationOrEvent): void
    {
        $this->assertEnabled();

        $event = $consultationOrEvent instanceof Consultation
            ? ExternalCalendarEvent::where('provider', 'outlook')
                ->where('external_id', 'consultation-'.$consultationOrEvent->id)
                ->first()
            : $consultationOrEvent;

        if (! $event) {
            return;
        }

        $outlookEventId = $event->metadata['outlook_event_id'] ?? null;
        if ($outlookEventId) {
            $this->deleteEvent($event->application, $outlookEventId);
        }

        $event->delete();
    }

    private function calendarView(string $application, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->get($this->calendarUrl($application).'/calendarView', [
                'startDateTime' => $start->toIso8601String(),
                'endDateTime' => $end->toIso8601String(),
                '$top' => 100,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Outlook calendar view sync failed: '.$response->body());
        }

        return $response->json('value', []);
    }

    private function createEvent(string $application, array $payload): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->post($this->calendarUrl($application).'/events', $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Outlook event creation failed: '.$response->body());
        }

        return $response->json();
    }

    private function patchEvent(string $application, string $eventId, array $payload): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->patch($this->calendarUrl($application).'/events/'.$eventId, $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Outlook event update failed: '.$response->body());
        }

        return $response->json();
    }

    public function consultationEventPayload(Consultation $consultation): array
    {
        $timezone = $consultation->timezone ?: config('app.booking_timezone');

        return [
            'subject' => $consultation->booking_number.' - '.$consultation->type->name,
            'body' => [
                'contentType' => 'Text',
                'content' => trim(($consultation->description ?: 'Consultation booking').' '.$consultation->primary_email),
            ],
            'start' => [
                'dateTime' => $consultation->starts_at->format('Y-m-d\TH:i:s'),
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $consultation->ends_at->format('Y-m-d\TH:i:s'),
                'timeZone' => $timezone,
            ],
            'showAs' => 'busy',
        ];
    }

    private function eventResponsePayload(array $event): array
    {
        return [
            'id' => $event['id'] ?? null,
            'webLink' => $event['webLink'] ?? null,
            'subject' => $event['subject'] ?? null,
            'showAs' => $event['showAs'] ?? null,
            'start' => $event['start'] ?? null,
            'end' => $event['end'] ?? null,
            'createdDateTime' => $event['createdDateTime'] ?? null,
            'lastModifiedDateTime' => $event['lastModifiedDateTime'] ?? null,
            'changeKey' => $event['changeKey'] ?? null,
        ];
    }

    private function calendarUrl(string $application): string
    {
        $userId = config('services.outlook.'.$application.'_user_id');
        $calendarId = config('services.outlook.'.$application.'_calendar_id');

        if (blank($userId)) {
            throw new \DomainException("Outlook {$application} user id is not configured.");
        }

        $baseUrl = rtrim(config('services.outlook.base_url'), '/');
        $userPath = $baseUrl.'/users/'.rawurlencode($userId);

        if (blank($calendarId)) {
            return $userPath.'/calendar';
        }

        return $userPath.'/calendars/'.rawurlencode($calendarId);
    }

    private function graphDateTime(?string $dateTime, ?string $timezone): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($dateTime, $timezone ?: config('app.booking_timezone'))
                ->timezone(config('app.booking_timezone'));
        } catch (\Throwable) {
            return CarbonImmutable::parse($dateTime, config('app.booking_timezone'));
        }
    }

    private function assertEnabled(): void
    {
        if (! config('services.outlook.enabled')) {
            throw new \DomainException('Outlook sync is disabled.');
        }
    }

    private function accessToken(): string
    {
        foreach (['tenant_id', 'client_id', 'client_secret'] as $key) {
            if (blank(config('services.outlook.'.$key))) {
                throw new \RuntimeException('Outlook is enabled but OUTLOOK_'.strtoupper($key).' is not configured.');
            }
        }

        $response = Http::asForm()
            ->acceptJson()
            ->post(rtrim(config('services.outlook.login_base_url'), '/').'/'.config('services.outlook.tenant_id').'/oauth2/v2.0/token', [
                'client_id' => config('services.outlook.client_id'),
                'client_secret' => config('services.outlook.client_secret'),
                'grant_type' => 'client_credentials',
                'scope' => 'https://graph.microsoft.com/.default',
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Outlook access token request failed: '.$response->body());
        }

        return $response->json('access_token');
    }
}
