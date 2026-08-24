<?php

namespace App\Services;

use App\Mail\ConsultationConfirmationMail;
use App\Mail\FreeIntroParticipantScheduleMail;
use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use App\Services\Integrations\OutlookCalendarClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class FreeIntroCallWorkflowService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly OutlookCalendarClient $outlook,
    ) {}

    public function handles(Consultation $consultation): bool
    {
        return $consultation->type?->slug === 'socal-free-intro-call';
    }

    public function afterCompletion(Consultation $consultation): bool
    {
        $consultation = $consultation->refresh()->load(['type', 'professional', 'participants']);

        if (! $this->handles($consultation)) {
            return false;
        }

        $participants = $consultation->participants;
        $primary = $participants->firstWhere('is_primary', true);

        DB::transaction(function () use ($consultation, $participants, $primary) {
            if ($primary && $consultation->starts_at && $primary->scheduling_status !== 'scheduled') {
                $primary->update([
                    'scheduling_status' => 'scheduled',
                    'scheduled_starts_at' => $consultation->starts_at,
                    'scheduled_ends_at' => $consultation->ends_at,
                    'scheduled_timezone' => $consultation->timezone,
                    'confirmed_at' => now(),
                ]);
            }

            $participants
                ->where('is_primary', false)
                ->each(function (ConsultationParticipant $participant) {
                    if (blank($participant->scheduling_token)) {
                        $participant->update(['scheduling_token' => Str::random(48)]);
                    }
                });

            $consultation->update([
                'payment_status' => 'paid',
                'status' => $participants->where('is_primary', false)->isEmpty() ? 'scheduled' : 'paid',
                'confirmed_at' => $participants->where('is_primary', false)->isEmpty() ? now() : $consultation->confirmed_at,
            ]);
        });

        $consultation = $consultation->refresh()->load(['type', 'professional', 'participants']);
        $this->syncScheduledParticipantSlots($consultation, 'free_intro_primary_schedule_outlook_sync');

        if ($consultation->participants->where('is_primary', false)->isEmpty()) {
            $this->sendConfirmations($consultation);

            return true;
        }

        $this->sendSchedulingInvites($consultation);
        $this->sendConfirmationsIfComplete($consultation);

        return true;
    }

    public function scheduleParticipant(ConsultationParticipant $participant, array $data): Consultation
    {
        $participant = $participant->load('consultation.type');
        $consultation = $participant->consultation;

        if (! $this->handles($consultation)) {
            throw new \DomainException('Participant slot scheduling is only available for Free 15-Min Intro Call.');
        }

        if ($participant->is_primary) {
            throw new \DomainException('The primary participant slot is already reserved by the consultation booking.');
        }

        if (in_array($consultation->status, ['draft', 'cancelled'], true)) {
            throw new \DomainException('This consultation is not available for participant scheduling.');
        }

        if (! hash_equals((string) $participant->scheduling_token, (string) ($data['scheduling_token'] ?? ''))) {
            throw new \DomainException('The participant scheduling token is invalid.');
        }

        $timezone = $data['timezone'] ?? $consultation->timezone ?: config('app.booking_timezone');
        $startsAt = CarbonImmutable::parse($data['starts_at'], $timezone);

        $this->availability->assertAvailable(
            $consultation->type,
            $startsAt,
            $data['professional_id'] ?? $consultation->professional_id,
            ignoreParticipantId: $participant->id
        );

        $participant->update([
            'scheduling_status' => 'scheduled',
            'scheduled_starts_at' => $startsAt,
            'scheduled_ends_at' => $startsAt->addMinutes($consultation->type->duration_minutes),
            'scheduled_timezone' => $timezone,
            'confirmed_at' => now(),
        ]);

        $consultation->integrationLogs()->create([
            'provider' => 'api',
            'action' => 'free_intro_participant_schedule',
            'status' => 'scheduled',
            'request_payload' => [
                'participant_id' => $participant->id,
                'starts_at' => $startsAt->toIso8601String(),
                'timezone' => $timezone,
            ],
            'message' => 'Free intro participant selected a preferred slot.',
        ]);

        $this->syncParticipantToOutlook(
            $participant->refresh()->load(['consultation.type', 'consultation.professional']),
            'free_intro_participant_schedule_outlook_sync'
        );

        $this->sendConfirmationsIfComplete($consultation->refresh()->load(['type', 'professional', 'participants']));

        return $consultation->refresh()->load(['type', 'professional', 'participants', 'paymentRequests']);
    }

    public function scheduleParticipantByToken(string $schedulingToken, array $data): Consultation
    {
        $participant = ConsultationParticipant::query()
            ->where('scheduling_token', $schedulingToken)
            ->first();

        if (! $participant) {
            throw new \DomainException('The participant scheduling token is invalid.');
        }

        return $this->scheduleParticipant($participant, $data + ['scheduling_token' => $schedulingToken]);
    }

    private function sendSchedulingInvites(Consultation $consultation): void
    {
        $sent = 0;

        foreach ($consultation->participants->where('is_primary', false)->where('scheduling_status', 'pending') as $participant) {
            if (blank($participant->email)) {
                continue;
            }

            Mail::to($participant->email)->send(new FreeIntroParticipantScheduleMail($participant));
            $participant->update(['scheduling_invited_at' => now()]);
            $sent++;

            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => 'free_intro_schedule_invite',
                'status' => 'sent',
                'request_payload' => [
                    'recipient' => $participant->email,
                    'template' => FreeIntroParticipantScheduleMail::class,
                    'participant_id' => $participant->id,
                ],
                'message' => 'Free intro participant scheduling email sent.',
            ]);
        }

        if ($sent === 0) {
            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => 'free_intro_schedule_invite',
                'status' => 'skipped',
                'message' => 'No additional participant email recipients were found for free intro scheduling.',
            ]);
        }
    }

    private function sendConfirmationsIfComplete(Consultation $consultation): void
    {
        $consultation = $consultation->refresh()->load(['type', 'professional', 'participants']);

        if (! $this->handles($consultation)) {
            return;
        }

        $this->sendConfirmations($consultation);

        if ($consultation->participants->contains(fn (ConsultationParticipant $participant) => $participant->scheduling_status !== 'scheduled')) {
            return;
        }

        if ($consultation->status !== 'scheduled' || $consultation->confirmed_at === null) {
            $consultation->update([
                'status' => 'scheduled',
                'payment_status' => 'paid',
                'confirmed_at' => now(),
            ]);
        }

        $this->sendConfirmations($consultation->refresh()->load(['type', 'professional', 'participants']));
    }

    private function sendConfirmations(Consultation $consultation): void
    {
        foreach ($consultation->participants as $participant) {
            if (blank($participant->email) || $participant->confirmed_at === null) {
                continue;
            }

            $alreadySent = $consultation->integrationLogs()
                ->where('provider', 'mail')
                ->where('action', 'free_intro_confirmation')
                ->get()
                ->contains(fn ($log) => (int) data_get($log->request_payload, 'participant_id') === $participant->id);

            if ($alreadySent) {
                continue;
            }

            Mail::to($participant->email)->send(new ConsultationConfirmationMail($consultation, $participant));

            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => 'free_intro_confirmation',
                'status' => 'sent',
                'request_payload' => [
                    'recipient' => $participant->email,
                    'template' => ConsultationConfirmationMail::class,
                    'participant_id' => $participant->id,
                ],
                'message' => 'Free intro confirmation email sent.',
            ]);
        }
    }

    private function syncScheduledParticipantSlots(Consultation $consultation, string $source): void
    {
        if (! config('services.outlook.enabled')) {
            return;
        }

        foreach ($consultation->participants->where('scheduling_status', 'scheduled') as $participant) {
            $this->syncParticipantToOutlook(
                $participant->load(['consultation.type', 'consultation.professional']),
                $source
            );
        }
    }

    private function syncParticipantToOutlook(ConsultationParticipant $participant, string $source): void
    {
        if (! config('services.outlook.enabled')) {
            return;
        }

        $consultation = $participant->consultation;

        try {
            $outlookEvent = $this->outlook->syncParticipantSlot($participant, $source);

            $consultation->integrationLogs()->create([
                'provider' => 'outlook',
                'action' => $source,
                'status' => 'synced',
                'request_payload' => $this->outlook->participantSlotEventPayload($participant),
                'response_payload' => $outlookEvent?->metadata['outlook_response'] ?? $outlookEvent?->metadata,
                'message' => 'Free intro participant slot synced to both Outlook calendars.',
            ]);
        } catch (\Throwable $exception) {
            $consultation?->integrationLogs()->create([
                'provider' => 'outlook',
                'action' => $source,
                'status' => 'failed',
                'request_payload' => [
                    'participant_id' => $participant->id,
                    'scheduled_starts_at' => $participant->scheduled_starts_at?->toIso8601String(),
                    'scheduled_ends_at' => $participant->scheduled_ends_at?->toIso8601String(),
                    'scheduled_timezone' => $participant->scheduled_timezone,
                ],
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
