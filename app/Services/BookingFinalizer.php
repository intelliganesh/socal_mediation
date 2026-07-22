<?php

namespace App\Services;

use App\Models\Consultation;
use App\Services\Integrations\OutlookCalendarClient;
use App\Services\Integrations\ZoomClient;

class BookingFinalizer
{
    public function __construct(
        private readonly ZoomClient $zoom,
        private readonly AdminZoomNotificationService $zoomNotifications,
        private readonly OutlookCalendarClient $outlook,
    ) {
    }

    public function syncPaymentStatus(Consultation $consultation): Consultation
    {
        $wasFullyPaid = $consultation->payment_status === 'paid';
        $total = $consultation->paymentRequests()->count();
        $paid = $consultation->paymentRequests()->where('status', 'paid')->count();

        if ($total > 0 && $paid === $total) {
            $updates = ['payment_status' => 'paid', 'status' => 'paid', 'confirmed_at' => now()];

            $consultation->update($updates);

            if (! $wasFullyPaid) {
                $consultation = $consultation->refresh()->load(['type', 'professional', 'participants']);
                $this->finalizePaidConsultation($consultation);
            }
        } elseif ($paid > 0) {
            $consultation->update(['payment_status' => 'partially_paid', 'status' => 'payment_pending']);
        }

        return $consultation->refresh();
    }

    private function finalizePaidConsultation(Consultation $consultation): void
    {
        $this->sendAutomaticZoomLink($consultation);
        $this->syncPaidConsultationToOutlook($consultation);
    }

    private function sendAutomaticZoomLink(Consultation $consultation): void
    {
        if ($consultation->consultation_mode !== 'online') {
            $consultation->integrationLogs()->create([
                'provider' => 'zoom',
                'action' => 'automatic_zoom_link',
                'status' => 'skipped',
                'message' => 'Zoom link is only created for online consultations.',
            ]);

            return;
        }

        try {
            if (blank($consultation->zoom_join_url)) {
                $meeting = $this->zoom->createMeeting($consultation);
                $consultation->update([
                    'zoom_meeting_id' => $meeting['id'],
                    'zoom_join_url' => $meeting['join_url'],
                ]);
            }

            $this->zoomNotifications->sendZoomLink($consultation->refresh(), 'automatic_zoom_link');
            $consultation->update(['status' => 'scheduled']);
        } catch (\Throwable $exception) {
            $consultation->integrationLogs()->create([
                'provider' => 'zoom',
                'action' => 'automatic_zoom_link',
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function syncPaidConsultationToOutlook(Consultation $consultation): void
    {
        if (! config('services.outlook.enabled')) {
            $consultation->integrationLogs()->create([
                'provider' => 'outlook',
                'action' => 'automatic_payment_sync',
                'status' => 'skipped',
                'message' => 'Outlook sync is disabled.',
            ]);

            return;
        }

        try {
            $syncedConsultation = $consultation->refresh()->load(['type', 'professional']);
            $outlookEvent = $this->outlook->syncConsultation($syncedConsultation, 'automatic_payment_sync');

            $consultation->integrationLogs()->create([
                'provider' => 'outlook',
                'action' => 'automatic_payment_sync',
                'status' => 'synced',
                'request_payload' => $this->outlook->consultationEventPayload($syncedConsultation),
                'response_payload' => $outlookEvent->metadata['outlook_response'] ?? $outlookEvent->metadata,
                'message' => 'Booking synced to Outlook after all payments were completed.',
            ]);
        } catch (\Throwable $exception) {
            $consultation->integrationLogs()->create([
                'provider' => 'outlook',
                'action' => 'automatic_payment_sync',
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
