<?php

namespace App\Services;

use App\Models\Consultation;
use App\Services\Integrations\OutlookCalendarClient;
use App\Services\Integrations\ZoomClient;
use Illuminate\Support\Facades\DB;

class ConsultationRescheduleService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly ZoomClient $zoom,
        private readonly AdminZoomNotificationService $zoomNotifications,
        private readonly OutlookCalendarClient $outlook,
        private readonly QuestionnaireWorkflowService $questionnaires,
        private readonly BookingDateTimeService $bookingDateTimes,
        private readonly RescheduleNotificationService $rescheduleNotifications,
        private readonly AdminNewConsultationNotificationService $adminNotifications,
    ) {}

    public function reschedule(Consultation $consultation, array $data, string $source = 'api_reschedule'): Consultation
    {
        if (in_array($consultation->status, ['draft', 'cancelled', 'completed'], true)) {
            throw new \DomainException('Only active bookings can be rescheduled.');
        }

        [$startsAt, $timezone] = $this->bookingDateTimes->startsAtFromRequest($data['starts_at']);
        $type = $consultation->type;
        $professionalId = $data['professional_id'] ?? $consultation->professional_id;

        $this->availability->assertAvailable($type, $startsAt, $professionalId, $consultation->id);

        return DB::transaction(function () use ($consultation, $startsAt, $timezone, $type, $professionalId, $source) {
            $oldStartsAt = $consultation->starts_at?->toIso8601String();
            $oldEndsAt = $consultation->ends_at?->toIso8601String();
            $oldStartsAtDate = $consultation->starts_at?->toImmutable();

            $consultation->update([
                'professional_id' => $professionalId,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes($type->duration_minutes),
                'timezone' => $timezone,
                'status' => $this->statusAfterReschedule($consultation),
            ]);

            $consultation->integrationLogs()->create([
                'provider' => 'api',
                'action' => $source,
                'status' => 'rescheduled',
                'request_payload' => [
                    'old_starts_at' => $oldStartsAt,
                    'old_ends_at' => $oldEndsAt,
                    'starts_at' => $startsAt->toIso8601String(),
                    'timezone' => $timezone,
                    'professional_id' => $professionalId,
                ],
                'message' => 'Consultation rescheduled.',
            ]);

            $rescheduledConsultation = $consultation->refresh()->load(['type', 'participants', 'questionnaireSubmissions']);

            if ($this->questionnaires->isReadyForMeetingRelease($rescheduledConsultation)) {
                if ($rescheduledConsultation->consultation_mode === 'online') {
                    $this->regenerateZoomLink($rescheduledConsultation, $source);
                } else {
                    $this->rescheduleNotifications->sendReadyConfirmation($rescheduledConsultation, $source);
                    $rescheduledConsultation->update(['status' => 'scheduled']);
                }

                $this->recreateOutlookEvent($consultation->refresh()->load(['type', 'professional']), $source);
            } else {
                $this->clearZoomLink($consultation->refresh(), $source);
                $this->rescheduleNotifications->sendPendingNextSteps($consultation->refresh(), $source);

                $consultation->integrationLogs()->create([
                    'provider' => 'zoom',
                    'action' => $source.'_zoom_link',
                    'status' => 'skipped',
                    'message' => 'Zoom link regeneration was skipped because required questionnaire steps are not complete.',
                ]);

                $consultation->integrationLogs()->create([
                    'provider' => 'outlook',
                    'action' => $source.'_outlook_sync',
                    'status' => 'skipped',
                    'message' => 'Outlook sync was skipped because required questionnaire steps are not complete.',
                ]);
            }

            $this->adminNotifications->sendForReschedule($consultation->refresh(), $oldStartsAtDate, $startsAt);

            return $consultation->refresh()->load(['type', 'professional', 'participants', 'paymentRequests', 'questionnaireSubmissions']);
        });
    }

    private function regenerateZoomLink(Consultation $consultation, string $source): void
    {
        if ($consultation->consultation_mode !== 'online') {
            $consultation->integrationLogs()->create([
                'provider' => 'zoom',
                'action' => $source.'_zoom_link',
                'status' => 'skipped',
                'message' => 'Zoom link is only regenerated for online consultations.',
            ]);

            return;
        }

        try {
            $oldMeetingId = $consultation->zoom_meeting_id;
            $meeting = $this->zoom->createMeeting($consultation);

            $consultation->update([
                'zoom_meeting_id' => $meeting['id'],
                'zoom_join_url' => $meeting['join_url'],
                'status' => 'scheduled',
            ]);

            $consultation->integrationLogs()->create([
                'provider' => 'zoom',
                'action' => $source.'_meeting',
                'status' => 'generated',
                'response_payload' => $meeting,
                'message' => 'Zoom meeting link regenerated after reschedule.',
            ]);

            $this->zoomNotifications->sendZoomLink($consultation->refresh(), $source.'_zoom_link');

            if (filled($oldMeetingId) && $oldMeetingId !== (string) $meeting['id']) {
                try {
                    $this->zoom->deleteMeeting($oldMeetingId);
                } catch (\Throwable $exception) {
                    $consultation->integrationLogs()->create([
                        'provider' => 'zoom',
                        'action' => $source.'_delete_old_meeting',
                        'status' => 'failed',
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $exception) {
            $consultation->integrationLogs()->create([
                'provider' => 'zoom',
                'action' => $source.'_zoom_link',
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function clearZoomLink(Consultation $consultation, string $source): void
    {
        if ($consultation->consultation_mode !== 'online') {
            return;
        }

        if (filled($consultation->zoom_meeting_id)) {
            try {
                $this->zoom->deleteMeeting($consultation->zoom_meeting_id);
            } catch (\Throwable $exception) {
                $consultation->integrationLogs()->create([
                    'provider' => 'zoom',
                    'action' => $source.'_delete_pending_meeting',
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $consultation->update([
            'zoom_meeting_id' => null,
            'zoom_join_url' => null,
        ]);
    }

    private function recreateOutlookEvent(Consultation $consultation, string $source): void
    {
        if (! config('services.outlook.enabled')) {
            $consultation->integrationLogs()->create([
                'provider' => 'outlook',
                'action' => $source.'_outlook_sync',
                'status' => 'skipped',
                'message' => 'Outlook sync is disabled.',
            ]);

            return;
        }

        try {
            $outlookEvent = $this->outlook->recreateConsultationEvent($consultation, $source.'_outlook_sync');

            $consultation->integrationLogs()->create([
                'provider' => 'outlook',
                'action' => $source.'_outlook_sync',
                'status' => 'synced',
                'request_payload' => $this->outlook->consultationEventPayload($consultation),
                'response_payload' => $outlookEvent->metadata['outlook_response'] ?? $outlookEvent->metadata,
                'message' => 'Outlook event recreated after reschedule.',
            ]);
        } catch (\Throwable $exception) {
            $consultation->integrationLogs()->create([
                'provider' => 'outlook',
                'action' => $source.'_outlook_sync',
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function statusAfterReschedule(Consultation $consultation): string
    {
        if (($consultation->status === 'scheduled' || filled($consultation->zoom_join_url))
            && $this->questionnaires->isReadyForMeetingRelease($consultation)) {
            return 'scheduled';
        }

        if ($consultation->payment_status === 'paid') {
            return 'paid';
        }

        return 'payment_pending';
    }
}
