<?php

namespace App\Services;

use App\Exceptions\ConsultationAlreadyCompletedException;
use App\Exceptions\OutlookAvailabilityUnavailableException;
use App\Models\Consultation;
use App\Services\Integrations\OutlookCalendarClient;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConsultationCompletionService
{
    public function __construct(
        private readonly ConsultationDraftService $drafts,
        private readonly AvailabilityService $availability,
        private readonly PaymentLinkService $payments,
        private readonly AdminPaymentNotificationService $notifications,
        private readonly AdminNewConsultationNotificationService $newRequestNotifications,
        private readonly PaymentSimulationService $simulation,
        private readonly OutlookCalendarClient $outlook,
        private readonly FreeIntroCallWorkflowService $freeIntroCalls,
        private readonly BookingDateTimeService $bookingDateTimes,
    ) {}

    public function complete(Consultation $consultation, array $data): Consultation
    {
        [$requestedStartsAt] = $this->bookingDateTimes->startsAtFromRequest($data['starts_at']);
        $lockKey = 'consultation-booking:'.$requestedStartsAt->format('Y-m-d');

        try {
            $consultation = Cache::lock($lockKey, 20)->block(
                5,
                fn () => $this->completeWithBookingLock($consultation, $data)
            );
        } catch (LockTimeoutException $exception) {
            throw new \DomainException('This booking time is currently being checked. Please try again.', previous: $exception);
        } catch (OutlookAvailabilityUnavailableException $exception) {
            $consultation->integrationLogs()->create([
                'provider' => 'outlook',
                'action' => 'automatic_booking_hold',
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $freeIntroHandled = $this->freeIntroCalls->afterCompletion($consultation);

        if (! $freeIntroHandled && $this->simulation->isActive()) {
            $consultation->integrationLogs()->create([
                'provider' => 'simulation',
                'action' => 'automatic_payment_link',
                'status' => 'skipped',
                'message' => 'Payment-link email skipped because payment simulation is enabled.',
            ]);
        } elseif (! $freeIntroHandled) {
            $this->notifications->sendPaymentLinks($consultation, 'automatic_payment_link');
        }

        $this->newRequestNotifications->sendForNewRequest($consultation);

        return $consultation->refresh()->load(['type', 'professional', 'participants', 'paymentRequests']);
    }

    private function completeWithBookingLock(Consultation $consultation, array $data): Consultation
    {
        return DB::transaction(function () use ($consultation, $data) {
            $consultation = Consultation::query()
                ->lockForUpdate()
                ->findOrFail($consultation->id);

            if ($consultation->status !== 'draft' || $consultation->paymentRequests()->exists()) {
                throw new ConsultationAlreadyCompletedException;
            }

            $data = $this->mergeDraftDetails($consultation->load(['participants']), $data);
            $this->assertRequiredDetailsPresent($data);

            $consultation = $this->drafts->updateDetails($consultation, $data);
            [$startsAt, $timezone] = $this->bookingDateTimes->startsAtFromRequest($data['starts_at']);
            $type = $consultation->type;
            $professionalId = $data['professional_id'] ?? $consultation->professional_id;

            $this->availability->assertAvailable(
                $type,
                $startsAt,
                $professionalId,
                checkLiveOutlook: true,
            );

            $consultation->update([
                'professional_id' => $professionalId,
                'timezone' => $timezone,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes($type->duration_minutes),
                'status' => 'payment_pending',
            ]);

            $paymentMode = $data['payment_mode'] ?? 'full';
            if ($type->price_cents > 0 && blank($data['payment_mode'] ?? null)) {
                throw new \DomainException('Payment mode is required before completing a paid consultation.');
            }

            $participantIds = $this->participantIdsForPayment(
                $consultation->refresh()->load(['type', 'participants']),
                $paymentMode,
                $data['payment_participant_emails'] ?? []
            );

            $completedConsultation = $this->payments->createRequests(
                $consultation,
                $paymentMode,
                $participantIds,
                $data['payment_method'] ?? null
            )->load(['type', 'professional', 'participants', 'paymentRequests']);

            if ($type->slug !== 'socal-free-intro-call' && config('services.outlook.enabled')) {
                $this->createOutlookHold($completedConsultation);
            }

            return $completedConsultation;
        });
    }

    private function createOutlookHold(Consultation $consultation): void
    {
        $syncedConsultation = $consultation->refresh()->load(['type', 'professional']);
        $outlookEvent = $this->outlook->syncBookingHold($syncedConsultation);

        $consultation->integrationLogs()->create([
            'provider' => 'outlook',
            'action' => 'automatic_booking_hold',
            'status' => 'synced',
            'request_payload' => $this->outlook->consultationEventPayload($syncedConsultation, true),
            'response_payload' => $outlookEvent->metadata['outlook_response'] ?? $outlookEvent->metadata,
            'message' => 'Busy Outlook hold created while the consultation awaits completion.',
        ]);
    }

    private function mergeDraftDetails(Consultation $consultation, array $data): array
    {
        $primary = $consultation->participants->firstWhere('is_primary', true);

        return array_replace_recursive([
            'legal_service_name' => $consultation->legal_service_name,
            'consultation_mode' => $consultation->consultation_mode,
            'description' => $consultation->description,
            'referral_source' => $consultation->referral_source,
            'referral_source_others' => $consultation->referral_source_others,
            'primary_client' => [
                'first_name' => $consultation->primary_first_name ?? $primary?->first_name,
                'last_name' => $consultation->primary_last_name ?? $primary?->last_name,
                'email' => $consultation->primary_email ?? $primary?->email,
                'phone_country' => $consultation->primary_phone_country ?? $primary?->phone_country,
                'phone' => $consultation->primary_phone ?? $primary?->phone,
            ],
            'participants' => $consultation->participants
                ->where('is_primary', false)
                ->map(fn ($participant) => [
                    'first_name' => $participant->first_name,
                    'last_name' => $participant->last_name,
                    'email' => $participant->email,
                    'phone_country' => $participant->phone_country,
                    'phone' => $participant->phone,
                ])
                ->values()
                ->all(),
        ], $data);
    }

    private function assertRequiredDetailsPresent(array $data): void
    {
        if (blank($data['consultation_mode'] ?? null)) {
            throw new \DomainException('Consultation mode is required before completing the consultation.');
        }

        if (blank($data['primary_client']['first_name'] ?? null)) {
            throw new \DomainException('Primary client first name is required before completing the consultation.');
        }

        if (blank($data['primary_client']['email'] ?? null)) {
            throw new \DomainException('Primary client email is required before completing the consultation.');
        }
    }

    private function participantIdsForPayment(Consultation $consultation, string $mode, array $emails): array
    {
        if ($mode === 'full') {
            return [];
        }

        if ($emails === []) {
            throw new \DomainException('Select at least one participant to make the split payment.');
        }

        $normalizedEmails = collect($emails)->map(fn (string $email) => strtolower($email))->all();
        $participantIds = $consultation->participants
            ->filter(fn ($participant) => $participant->email !== null && in_array(strtolower($participant->email), $normalizedEmails, true))
            ->pluck('id')
            ->all();

        if (count($participantIds) !== count(array_unique($normalizedEmails))) {
            throw new \DomainException('One or more payment participant emails do not match this consultation.');
        }

        return $participantIds;
    }
}
