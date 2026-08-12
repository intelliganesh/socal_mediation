<?php

namespace App\Services;

use App\Models\Consultation;
use App\Services\Integrations\OutlookCalendarClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ConsultationCompletionService
{
    public function __construct(
        private readonly ConsultationDraftService $drafts,
        private readonly AvailabilityService $availability,
        private readonly PaymentLinkService $payments,
        private readonly AdminPaymentNotificationService $notifications,
        private readonly PaymentSimulationService $simulation,
        private readonly OutlookCalendarClient $outlook,
    ) {}

    public function complete(Consultation $consultation, array $data): Consultation
    {
        $consultation = DB::transaction(function () use ($consultation, $data) {
            $data = $this->mergeDraftDetails($consultation->load(['participants']), $data);
            $this->assertRequiredDetailsPresent($data);

            $consultation = $this->drafts->updateDetails($consultation, $data);
            $timezone = $data['timezone'] ?? $consultation->timezone;
            $startsAt = CarbonImmutable::parse($data['starts_at'], $timezone);
            $type = $consultation->type;
            $professionalId = $data['professional_id'] ?? $consultation->professional_id;

            $this->availability->assertAvailable($type, $startsAt, $professionalId);

            $consultation->update([
                'professional_id' => $professionalId,
                'timezone' => $timezone,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes($type->duration_minutes),
                'status' => 'payment_pending',
            ]);

            $participantIds = $this->participantIdsForPayment(
                $consultation->refresh()->load(['type', 'participants']),
                $data['payment_mode'],
                $data['payment_participant_emails'] ?? []
            );

            return $this->payments->createRequests(
                $consultation,
                $data['payment_mode'],
                $participantIds,
                $data['payment_method'] ?? null
            )->load(['type', 'professional', 'participants', 'paymentRequests']);
        });

        if ($this->simulation->isActive()) {
            $consultation->integrationLogs()->create([
                'provider' => 'simulation',
                'action' => 'automatic_payment_link',
                'status' => 'skipped',
                'message' => 'Payment-link email skipped because payment simulation is enabled.',
            ]);
        } else {
            $this->notifications->sendPaymentLinks($consultation, 'automatic_payment_link');
        }

        $this->syncToOutlook($consultation);

        return $consultation->refresh()->load(['type', 'professional', 'participants', 'paymentRequests']);
    }

    private function syncToOutlook(Consultation $consultation): void
    {
        if (! config('services.outlook.enabled')) {
            return;
        }

        try {
            $syncedConsultation = $consultation->refresh()->load(['type', 'professional']);
            $outlookEvent = $this->outlook->syncConsultation($syncedConsultation, 'automatic_completion_sync');

            $consultation->integrationLogs()->create([
                'provider' => 'outlook',
                'action' => 'automatic_completion_sync',
                'status' => 'synced',
                'request_payload' => $this->outlook->consultationEventPayload($syncedConsultation),
                'response_payload' => $outlookEvent?->metadata['outlook_response'] ?? $outlookEvent?->metadata,
                'message' => 'Booking synced to both Outlook calendars after consultation completion.',
            ]);
        } catch (\Throwable $exception) {
            $consultation->integrationLogs()->create([
                'provider' => 'outlook',
                'action' => 'automatic_completion_sync',
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function mergeDraftDetails(Consultation $consultation, array $data): array
    {
        $primary = $consultation->participants->firstWhere('is_primary', true);

        return array_replace_recursive([
            'legal_service_name' => $consultation->legal_service_name,
            'consultation_mode' => $consultation->consultation_mode,
            'description' => $consultation->description,
            'referral_source' => $consultation->referral_source,
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
            return $consultation->participants->pluck('id')->all();
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
