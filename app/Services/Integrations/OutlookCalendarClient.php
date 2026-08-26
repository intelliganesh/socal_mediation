<?php

namespace App\Services\Integrations;

use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use App\Models\ExternalCalendarEvent;
use App\Models\QuestionnaireSubmission;
use App\Services\QuestionnaireTemplateService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OutlookCalendarClient
{
    private const SYNC_WINDOW_MONTHS = 4;

    private const CONSULTATION_MARKER = 'SMC-CONSULTATION:';

    private const PARTICIPANT_MARKER = 'SMC-CONSULTATION-PARTICIPANT:';

    private const CALENDAR_APPLICATIONS = ['socal', 'legal'];

    private const ACTIVE_CONSULTATION_STATUSES = ['scheduled'];

    private const OUTLOOK_TIMEZONES = [
        'UTC' => 'UTC',
        'Asia/Kolkata' => 'India Standard Time',
        'America/Los_Angeles' => 'Pacific Standard Time',
        'America/New_York' => 'Eastern Standard Time',
        'America/Chicago' => 'Central Standard Time',
        'America/Denver' => 'Mountain Standard Time',
        'America/Phoenix' => 'US Mountain Standard Time',
    ];

    public function __construct(private readonly QuestionnaireTemplateService $questionnaireTemplates) {}

    public function syncAllConsultations(): array
    {
        $busy = $this->syncCurrentWindow();
        $future = $this->syncFutureConsultations();

        return [
            'busy' => $busy,
            'synced' => $future['synced'],
            'deleted' => $future['deleted'],
        ];
    }

    public function syncCurrentWindow(): int
    {
        $this->assertEnabled();

        [$start, $end] = $this->rollingSyncWindow();

        return $this->syncWindow($start, $end);
    }

    public function syncFutureConsultations(?CarbonImmutable $from = null, ?CarbonImmutable $until = null): array
    {
        $this->assertEnabled();

        [$windowStart, $windowEnd] = $this->rollingSyncWindow();
        $from ??= $windowStart;
        $until ??= $windowEnd;
        $fromDatabase = $from->timezone(config('app.booking_timezone'))->format('Y-m-d H:i:s');
        $untilDatabase = $until->timezone(config('app.booking_timezone'))->format('Y-m-d H:i:s');
        $consultations = Consultation::query()
            ->with(['type', 'professional', 'questionnaireSubmissions'])
            ->whereNotNull('starts_at')
            ->whereIn('status', self::ACTIVE_CONSULTATION_STATUSES)
            ->where('payment_status', 'paid')
            ->where('starts_at', '>=', $fromDatabase)
            ->where('starts_at', '<=', $untilDatabase)
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Consultation $consultation) => $this->isReadyForOutlook($consultation));

        $synced = 0;
        foreach ($consultations as $consultation) {
            $this->syncConsultation($consultation, 'future_consultation_sync');
            $synced++;
        }

        $participants = ConsultationParticipant::query()
            ->with(['consultation.type', 'consultation.professional'])
            ->where('scheduling_status', 'scheduled')
            ->whereNotNull('scheduled_starts_at')
            ->where('scheduled_starts_at', '>=', $fromDatabase)
            ->where('scheduled_starts_at', '<=', $untilDatabase)
            ->whereHas('consultation', fn ($query) => $query
                ->whereIn('status', ['paid', 'scheduled'])
                ->where('payment_status', 'paid'))
            ->whereHas('consultation.type', fn ($query) => $query->where('slug', 'socal-free-intro-call'))
            ->get();

        foreach ($participants as $participant) {
            $this->syncParticipantSlot($participant, 'future_free_intro_participant_sync');
            $synced++;
        }

        $deleted = 0;
        ExternalCalendarEvent::query()
            ->where('provider', 'outlook')
            ->where(function ($query) {
                $query->where('external_id', 'like', 'consultation-%')
                    ->orWhere('external_id', 'like', 'free-intro-participant-%');
            })
            ->get()
            ->each(function (ExternalCalendarEvent $event) use (&$deleted) {
                $stillExists = str_starts_with($event->external_id, 'free-intro-participant-')
                    ? $this->trackedParticipantSlotStillExists($event)
                    : $this->trackedConsultationStillExists($event);

                if ($stillExists) {
                    return;
                }

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

        foreach (self::CALENDAR_APPLICATIONS as $application) {
            $activeExternalIds = [];
            $trackedEvents = ExternalCalendarEvent::query()
                ->where('provider', 'outlook')
                ->where('application', $application)
                ->where(function ($query) {
                    $query->where('external_id', 'like', 'consultation-%')
                        ->orWhere('external_id', 'like', 'free-intro-participant-%');
                })
                ->get()
                ->filter(fn (ExternalCalendarEvent $event) => filled($event->metadata['outlook_event_id'] ?? null))
                ->keyBy(fn (ExternalCalendarEvent $event) => $event->metadata['outlook_event_id']);

            foreach ($this->calendarView($application, $start, $end) as $event) {
                if (($event['isCancelled'] ?? false) || ! ($event['showAs'] ?? null) || ($event['showAs'] ?? 'busy') === 'free') {
                    continue;
                }

                $trackedEvent = $trackedEvents->get($event['id'] ?? '');
                $consultationId = $this->consultationIdFromOutlookEvent($event)
                    ?? ($trackedEvent?->metadata['consultation_uuid'] ?? null);
                $participantId = $this->participantIdFromOutlookEvent($event)
                    ?? ($trackedEvent?->metadata['participant_id'] ?? null);

                if ($consultationId !== null) {
                    $stillExists = $this->consultationStillExists($consultationId);

                    if (! $stillExists) {
                        $this->deleteEvent($application, $event['id']);
                        $trackedEvent?->delete();
                    }

                    continue;
                }

                if ($participantId !== null) {
                    $stillExists = $this->participantSlotStillExists($participantId);

                    if (! $stillExists) {
                        $this->deleteEvent($application, $event['id']);
                        $trackedEvent?->delete();
                    }

                    continue;
                }

                $activeExternalIds[] = $event['id'];

                ExternalCalendarEvent::updateOrCreate([
                    'provider' => 'outlook',
                    'external_id' => $event['id'],
                    'application' => $application,
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

            ExternalCalendarEvent::query()
                ->where('provider', 'outlook')
                ->where('application', $application)
                ->where('external_id', 'not like', 'consultation-%')
                ->where('external_id', 'not like', 'free-intro-participant-%')
                ->where('starts_at', '<', $end->format('Y-m-d H:i:s'))
                ->where('ends_at', '>', $start->format('Y-m-d H:i:s'))
                ->when($activeExternalIds !== [], fn ($query) => $query->whereNotIn('external_id', $activeExternalIds))
                ->delete();
        }

        $this->deleteOutlookBusyRowsOutsideWindow($start, $end);

        return $count;
    }

    private function rollingSyncWindow(): array
    {
        $start = CarbonImmutable::now(config('app.booking_timezone'))->startOfDay();

        return [$start, $start->addMonths(self::SYNC_WINDOW_MONTHS)->endOfDay()];
    }

    private function deleteOutlookBusyRowsOutsideWindow(CarbonImmutable $start, CarbonImmutable $end): void
    {
        ExternalCalendarEvent::query()
            ->where('provider', 'outlook')
            ->where('external_id', 'not like', 'consultation-%')
            ->where('external_id', 'not like', 'free-intro-participant-%')
            ->where(function ($query) use ($start, $end) {
                $query->where('ends_at', '<=', $start->format('Y-m-d H:i:s'))
                    ->orWhere('starts_at', '>', $end->format('Y-m-d H:i:s'));
            })
            ->delete();
    }

    public function syncConsultation(Consultation $consultation, string $source = 'admin_manual_sync'): ?ExternalCalendarEvent
    {
        $this->assertEnabled();

        $consultation->loadMissing('questionnaireSubmissions');

        if (! $this->isReadyForOutlook($consultation)) {
            $this->deleteConsultationEvent($consultation);

            return null;
        }

        if ($consultation->starts_at === null || $consultation->ends_at === null) {
            throw new \DomainException('Consultation must be scheduled before syncing to Outlook.');
        }

        $syncedEvents = collect();
        $errors = [];

        foreach (self::CALENDAR_APPLICATIONS as $calendarApplication) {
            try {
                $syncedEvents->push($this->syncConsultationToCalendar($consultation, $calendarApplication, $source));
            } catch (\Throwable $exception) {
                $errors[] = Str::headline($calendarApplication).': '.$exception->getMessage();
            }
        }

        if ($errors !== []) {
            throw new \RuntimeException('Outlook consultation sync failed for '.implode(' | ', $errors));
        }

        return $syncedEvents->firstWhere('application', $consultation->application)
            ?? $syncedEvents->first();
    }

    public function recreateConsultationEvent(Consultation $consultation, string $source = 'reschedule_sync'): ExternalCalendarEvent
    {
        $this->assertEnabled();

        return $this->syncConsultation($consultation, $source);
    }

    public function syncParticipantSlot(ConsultationParticipant $participant, string $source = 'free_intro_participant_sync'): ?ExternalCalendarEvent
    {
        $this->assertEnabled();
        $participant->loadMissing(['consultation.type', 'consultation.professional']);
        $consultation = $participant->consultation;

        if (! $this->isReadyForParticipantOutlook($participant)) {
            $this->deleteParticipantSlotEvent($participant);

            return null;
        }

        $syncedEvents = collect();
        $errors = [];

        foreach (self::CALENDAR_APPLICATIONS as $calendarApplication) {
            try {
                $syncedEvents->push($this->syncParticipantSlotToCalendar($participant, $calendarApplication, $source));
            } catch (\Throwable $exception) {
                $errors[] = Str::headline($calendarApplication).': '.$exception->getMessage();
            }
        }

        if ($errors !== []) {
            throw new \RuntimeException('Outlook participant slot sync failed for '.implode(' | ', $errors));
        }

        return $syncedEvents->firstWhere('application', $consultation->application)
            ?? $syncedEvents->first();
    }

    private function isReadyForOutlook(Consultation $consultation): bool
    {
        if (! in_array($consultation->status, self::ACTIVE_CONSULTATION_STATUSES, true)) {
            return false;
        }

        if ($consultation->payment_status !== 'paid') {
            return false;
        }

        if ($consultation->starts_at === null || $consultation->ends_at === null) {
            return false;
        }

        if (! $this->questionnaireTemplates->requiresQuestionnaire($consultation)) {
            return true;
        }

        $submissions = $consultation->relationLoaded('questionnaireSubmissions')
            ? $consultation->questionnaireSubmissions
            : $consultation->questionnaireSubmissions()->get();

        return $submissions->isNotEmpty()
            && ! $submissions->contains(function (QuestionnaireSubmission $submission) {
                return $submission->status !== 'submitted'
                    || ($this->questionnaireTemplates->requiresAgreement($submission->template_key) && ! $submission->agreement_accepted);
            });
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
                'timeZone' => $this->outlookTimezone(config('app.booking_timezone')),
            ],
            'end' => [
                'dateTime' => $start->addMinutes(15)->format('Y-m-d\TH:i:s'),
                'timeZone' => $this->outlookTimezone(config('app.booking_timezone')),
            ],
            'showAs' => 'busy',
        ]);

        return $event['id'];
    }

    public function deleteEvent(string $application, string $eventId): void
    {
        $this->assertEnabled();
        $calendarUrl = $this->calendarUrl($application);

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->delete($calendarUrl.'/events/'.$eventId);

        if ($response->failed() && $response->status() !== 404) {
            throw new \RuntimeException('Outlook event deletion failed: '.$response->body());
        }
    }

    public function deleteConsultationEvent(Consultation|ExternalCalendarEvent $consultationOrEvent): void
    {
        $this->assertEnabled();

        $events = $consultationOrEvent instanceof Consultation
            ? ExternalCalendarEvent::query()
                ->where('provider', 'outlook')
                ->where(function ($query) use ($consultationOrEvent) {
                    $legacyExternalId = 'consultation-'.$consultationOrEvent->id;

                    $query->where('external_id', $legacyExternalId)
                        ->orWhere('external_id', 'like', $legacyExternalId.'-%')
                        ->orWhere('metadata->consultation_uuid', $consultationOrEvent->id);
                })
                ->get()
            : collect([$consultationOrEvent]);

        $errors = [];
        foreach ($events as $event) {
            try {
                $this->deleteTrackedConsultationEvent($event);
            } catch (\Throwable $exception) {
                $errors[] = Str::headline((string) $event->application).': '.$exception->getMessage();
            }
        }

        if ($errors !== []) {
            throw new \RuntimeException('Outlook consultation deletion failed for '.implode(' | ', $errors));
        }
    }

    private function syncConsultationToCalendar(
        Consultation $consultation,
        string $calendarApplication,
        string $source,
    ): ExternalCalendarEvent {
        $payload = $this->consultationEventPayload($consultation);
        $externalId = $this->trackedConsultationExternalId($consultation, $calendarApplication);
        $existing = ExternalCalendarEvent::query()
            ->where('provider', 'outlook')
            ->where('external_id', $externalId)
            ->first();

        if (! $existing) {
            $legacyExternalId = 'consultation-'.$consultation->id;
            $existing = ExternalCalendarEvent::query()
                ->where('provider', 'outlook')
                ->where('external_id', $legacyExternalId)
                ->where('application', $calendarApplication)
                ->first();

            if ($existing) {
                $existing->update(['external_id' => $externalId]);
            }
        }

        if ($existing?->metadata['outlook_event_id'] ?? false) {
            try {
                $event = $this->patchEvent($calendarApplication, $existing->metadata['outlook_event_id'], $payload);
            } catch (\RuntimeException $exception) {
                if (! str_contains($exception->getMessage(), '"ErrorItemNotFound"')) {
                    throw $exception;
                }

                $event = $this->createEvent($calendarApplication, $payload);
            }
        } else {
            $event = $this->createEvent($calendarApplication, $payload);
        }

        return ExternalCalendarEvent::updateOrCreate([
            'provider' => 'outlook',
            'external_id' => $externalId,
        ], [
            'professional_id' => $consultation->professional_id,
            'application' => $calendarApplication,
            'title' => $payload['subject'],
            'starts_at' => $consultation->starts_at,
            'ends_at' => $consultation->ends_at,
            'is_busy' => true,
            'metadata' => [
                'consultation_uuid' => $consultation->id,
                'consultation_application' => $consultation->application,
                'calendar_application' => $calendarApplication,
                'outlook_event_id' => $event['id'] ?? null,
                'outlook_web_link' => $event['webLink'] ?? null,
                'outlook_response' => $this->eventResponsePayload($event),
                'source' => $source,
                'synced_at' => now()->toIso8601String(),
            ],
        ]);
    }

    private function syncParticipantSlotToCalendar(
        ConsultationParticipant $participant,
        string $calendarApplication,
        string $source,
    ): ExternalCalendarEvent {
        $payload = $this->participantSlotEventPayload($participant);
        $externalId = $this->trackedParticipantSlotExternalId($participant, $calendarApplication);
        $existing = ExternalCalendarEvent::query()
            ->where('provider', 'outlook')
            ->where('external_id', $externalId)
            ->first();

        if ($existing?->metadata['outlook_event_id'] ?? false) {
            try {
                $event = $this->patchEvent($calendarApplication, $existing->metadata['outlook_event_id'], $payload);
            } catch (\RuntimeException $exception) {
                if (! str_contains($exception->getMessage(), '"ErrorItemNotFound"')) {
                    throw $exception;
                }

                $event = $this->createEvent($calendarApplication, $payload);
            }
        } else {
            $event = $this->createEvent($calendarApplication, $payload);
        }

        $consultation = $participant->consultation;

        return ExternalCalendarEvent::updateOrCreate([
            'provider' => 'outlook',
            'external_id' => $externalId,
        ], [
            'professional_id' => $consultation->professional_id,
            'application' => $calendarApplication,
            'title' => $payload['subject'],
            'starts_at' => $participant->scheduled_starts_at,
            'ends_at' => $participant->scheduled_ends_at,
            'is_busy' => true,
            'metadata' => [
                'consultation_uuid' => $consultation->id,
                'participant_id' => $participant->id,
                'consultation_application' => $consultation->application,
                'calendar_application' => $calendarApplication,
                'outlook_event_id' => $event['id'] ?? null,
                'outlook_web_link' => $event['webLink'] ?? null,
                'outlook_response' => $this->eventResponsePayload($event),
                'source' => $source,
                'synced_at' => now()->toIso8601String(),
            ],
        ]);
    }

    private function deleteTrackedConsultationEvent(ExternalCalendarEvent $event): void
    {
        $outlookEventId = $event->metadata['outlook_event_id'] ?? null;
        if ($outlookEventId) {
            $this->deleteEvent($event->application, $outlookEventId);
        }

        $event->delete();
    }

    private function trackedConsultationExternalId(Consultation $consultation, string $calendarApplication): string
    {
        return 'consultation-'.$consultation->id.'-'.$calendarApplication;
    }

    private function trackedParticipantSlotExternalId(ConsultationParticipant $participant, string $calendarApplication): string
    {
        return 'free-intro-participant-'.$participant->id.'-'.$calendarApplication;
    }

    private function calendarView(string $application, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $calendarUrl = $this->calendarUrl($application);
        $events = [];
        $windowQuery = [
            'startDateTime' => $start->toIso8601String(),
            'endDateTime' => $end->toIso8601String(),
        ];
        $nextUrl = $this->calendarViewUrl($calendarUrl.'/calendarView', $windowQuery + ['$top' => 100]);

        do {
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->get($nextUrl);

            if ($response->failed()) {
                throw new \RuntimeException('Outlook calendar view sync failed: '.$response->body());
            }

            $payload = $response->json();
            $events = array_merge($events, $payload['value'] ?? []);
            $nextUrl = isset($payload['@odata.nextLink'])
                ? $this->calendarViewUrl($payload['@odata.nextLink'], $windowQuery)
                : null;
        } while (filled($nextUrl));

        return $events;
    }

    private function calendarViewUrl(string $url, array $query): string
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $existingQuery);

        foreach ($query as $key => $value) {
            $existingQuery[$key] ??= $value;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $scheme.$host.$port.$path.'?'.http_build_query($existingQuery, '', '&', PHP_QUERY_RFC3986).$fragment;
    }

    private function createEvent(string $application, array $payload): array
    {
        $calendarUrl = $this->calendarUrl($application);

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->post($calendarUrl.'/events', $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Outlook event creation failed: '.$response->body());
        }

        return $response->json();
    }

    private function patchEvent(string $application, string $eventId, array $payload): array
    {
        $calendarUrl = $this->calendarUrl($application);

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->patch($calendarUrl.'/events/'.$eventId, $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Outlook event update failed: '.$response->body());
        }

        return $response->json();
    }

    public function consultationEventPayload(Consultation $consultation): array
    {
        $timezone = $consultation->timezone ?: config('app.booking_timezone');
        $body = trim(($consultation->description ?: 'Consultation booking').' '.$consultation->primary_email);
        $outlookTimezone = $this->outlookTimezone($timezone);

        return [
            'subject' => $consultation->booking_number.' - '.$consultation->type->name,
            'body' => [
                'contentType' => 'Text',
                'content' => $body."\n\n".self::CONSULTATION_MARKER.$consultation->id,
            ],
            'start' => [
                'dateTime' => $this->storedLocalDateTime($consultation, 'starts_at', $timezone),
                'timeZone' => $outlookTimezone,
            ],
            'end' => [
                'dateTime' => $this->storedLocalDateTime($consultation, 'ends_at', $timezone),
                'timeZone' => $outlookTimezone,
            ],
            'showAs' => 'busy',
        ];
    }

    public function participantSlotEventPayload(ConsultationParticipant $participant): array
    {
        $participant->loadMissing(['consultation.type']);
        $consultation = $participant->consultation;
        $timezone = $participant->scheduled_timezone ?: $consultation->timezone ?: config('app.booking_timezone');
        $participantName = trim($participant->first_name.' '.$participant->last_name) ?: 'Participant';
        $body = trim(($consultation->description ?: 'Free intro call').' '.$participant->email);
        $outlookTimezone = $this->outlookTimezone($timezone);

        return [
            'subject' => $consultation->booking_number.' - '.$participantName.' - '.$consultation->type->name,
            'body' => [
                'contentType' => 'Text',
                'content' => $body."\n\n".self::PARTICIPANT_MARKER.$participant->id,
            ],
            'start' => [
                'dateTime' => $this->storedLocalDateTime($participant, 'scheduled_starts_at', $timezone),
                'timeZone' => $outlookTimezone,
            ],
            'end' => [
                'dateTime' => $this->storedLocalDateTime($participant, 'scheduled_ends_at', $timezone),
                'timeZone' => $outlookTimezone,
            ],
            'showAs' => 'busy',
        ];
    }

    private function storedLocalDateTime(Model $model, string $attribute, string $timezone): string
    {
        $rawValue = $model->getRawOriginal($attribute);

        if (filled($rawValue)) {
            return CarbonImmutable::parse($rawValue, config('app.booking_timezone'))->format('Y-m-d\TH:i:s');
        }

        return CarbonImmutable::parse($model->getAttribute($attribute), $timezone)->format('Y-m-d\TH:i:s');
    }

    private function outlookTimezone(string $timezone): string
    {
        return self::OUTLOOK_TIMEZONES[$timezone] ?? $timezone;
    }

    private function deleteParticipantSlotEvent(ConsultationParticipant $participant): void
    {
        ExternalCalendarEvent::query()
            ->where('provider', 'outlook')
            ->where(function ($query) use ($participant) {
                $query->where('external_id', 'like', 'free-intro-participant-'.$participant->id.'-%')
                    ->orWhere('metadata->participant_id', $participant->id);
            })
            ->get()
            ->each(fn (ExternalCalendarEvent $event) => $this->deleteTrackedConsultationEvent($event));
    }

    private function trackedConsultationStillExists(ExternalCalendarEvent $event): bool
    {
        $consultationId = $event->metadata['consultation_uuid']
            ?? str($event->external_id)->after('consultation-')->beforeLast('-')->toString();

        return $this->consultationStillExists($consultationId);
    }

    private function consultationStillExists(?string $consultationId): bool
    {
        if (blank($consultationId)) {
            return false;
        }

        return Consultation::query()
            ->with('questionnaireSubmissions')
            ->whereKey($consultationId)
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->whereIn('status', self::ACTIVE_CONSULTATION_STATUSES)
            ->where('payment_status', 'paid')
            ->get()
            ->contains(fn (Consultation $consultation) => $this->isReadyForOutlook($consultation));
    }

    private function trackedParticipantSlotStillExists(ExternalCalendarEvent $event): bool
    {
        $participantId = $event->metadata['participant_id']
            ?? str($event->external_id)->after('free-intro-participant-')->beforeLast('-')->toString();

        return $this->participantSlotStillExists($participantId);
    }

    private function participantSlotStillExists(null|int|string $participantId): bool
    {
        if (blank($participantId)) {
            return false;
        }

        return ConsultationParticipant::query()
            ->with(['consultation.type'])
            ->whereKey($participantId)
            ->where('scheduling_status', 'scheduled')
            ->whereNotNull('scheduled_starts_at')
            ->whereNotNull('scheduled_ends_at')
            ->get()
            ->contains(fn (ConsultationParticipant $participant) => $this->isReadyForParticipantOutlook($participant));
    }

    private function isReadyForParticipantOutlook(ConsultationParticipant $participant): bool
    {
        $consultation = $participant->consultation;

        return $consultation?->type?->slug === 'socal-free-intro-call'
            && in_array($consultation->status, ['paid', 'scheduled'], true)
            && $consultation->payment_status === 'paid'
            && $participant->scheduling_status === 'scheduled'
            && $participant->scheduled_starts_at !== null
            && $participant->scheduled_ends_at !== null;
    }

    private function consultationIdFromOutlookEvent(array $event): ?string
    {
        $body = (string) data_get($event, 'body.content', '');

        if (! preg_match('/'.preg_quote(self::CONSULTATION_MARKER, '/').'([0-9a-f-]{36})/i', $body, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function participantIdFromOutlookEvent(array $event): ?int
    {
        $body = (string) data_get($event, 'body.content', '');

        if (! preg_match('/'.preg_quote(self::PARTICIPANT_MARKER, '/').'([0-9]+)/', $body, $matches)) {
            return null;
        }

        return (int) $matches[1];
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
