<?php

namespace App\Services;

use App\Mail\ConsultationZoomLinkMail;
use App\Models\Consultation;
use Illuminate\Support\Facades\Mail;

class AdminZoomNotificationService
{
    public function resendZoomLink(Consultation $consultation): int
    {
        if (blank($consultation->zoom_join_url)) {
            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => 'manual_zoom_link',
                'status' => 'skipped',
                'message' => 'Zoom link is not available for this consultation.',
            ]);

            return 0;
        }

        $sent = 0;

        foreach ($consultation->participants()->whereNotNull('email')->get() as $participant) {
            Mail::to($participant->email)->send(new ConsultationZoomLinkMail($consultation, $participant));

            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => 'manual_zoom_link',
                'status' => 'sent',
                'request_payload' => [
                    'recipient' => $participant->email,
                    'template' => ConsultationZoomLinkMail::class,
                    'participant_id' => $participant->id,
                ],
                'message' => 'Zoom meeting link resent from admin panel.',
            ]);

            $sent++;
        }

        if ($sent === 0) {
            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => 'manual_zoom_link',
                'status' => 'skipped',
                'message' => 'No participant email recipients were found for this consultation.',
            ]);
        }

        return $sent;
    }
}
