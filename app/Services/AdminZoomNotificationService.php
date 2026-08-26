<?php

namespace App\Services;

use App\Mail\ConsultationZoomLinkMail;
use App\Models\Consultation;
use Illuminate\Support\Facades\Mail;

class AdminZoomNotificationService
{
    public function resendZoomLink(Consultation $consultation): int
    {
        return $this->sendZoomLink($consultation);
    }

    public function sendZoomLink(Consultation $consultation, string $action = 'manual_zoom_link'): int
    {
        if (blank($consultation->zoom_join_url)) {
            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => $action,
                'status' => 'skipped',
                'message' => 'Zoom link is not available for this consultation.',
            ]);

            return 0;
        }

        $recipients = $consultation->participants()->whereNotNull('email')->get();
        $sent = 0;
        $isReschedule = str_contains($action, 'reschedule');

        foreach ($recipients as $participant) {
            try {
                Mail::to($participant->email)->send(new ConsultationZoomLinkMail($consultation, $participant, $isReschedule));
            } catch (\Throwable $exception) {
                $consultation->integrationLogs()->create([
                    'provider' => 'mail',
                    'action' => $action,
                    'status' => 'failed',
                    'request_payload' => [
                        'recipient' => $participant->email,
                        'template' => ConsultationZoomLinkMail::class,
                        'participant_id' => $participant->id,
                    ],
                    'message' => 'Zoom meeting link email failed: '.$exception->getMessage(),
                ]);

                continue;
            }

            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => $action,
                'status' => 'sent',
                'request_payload' => [
                    'recipient' => $participant->email,
                    'template' => ConsultationZoomLinkMail::class,
                    'participant_id' => $participant->id,
                ],
                'message' => match ($action) {
                    'manual_zoom_link' => 'Zoom meeting link resent from admin panel.',
                    'manual_reschedule_zoom_link' => 'Zoom meeting link sent after consultation reschedule.',
                    default => 'Zoom meeting link sent after all payments were completed.',
                },
            ]);

            $sent++;
        }

        if ($sent === 0 && $recipients->isEmpty()) {
            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => $action,
                'status' => 'skipped',
                'message' => 'No participant email recipients were found for this consultation.',
            ]);
        }

        return $sent;
    }
}
