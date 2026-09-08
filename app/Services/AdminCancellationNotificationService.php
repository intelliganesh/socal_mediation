<?php

namespace App\Services;

use App\Mail\ConsultationCancellationMail;
use App\Models\Consultation;
use Illuminate\Support\Facades\Mail;

class AdminCancellationNotificationService
{
    public function sendCancellation(Consultation $consultation): int
    {
        $recipients = $consultation->participants()->whereNotNull('email')->get();
        $sent = 0;

        foreach ($recipients as $participant) {
            try {
                Mail::to($participant->email)->send(new ConsultationCancellationMail($consultation, $participant));
            } catch (\Throwable $exception) {
                $consultation->integrationLogs()->create([
                    'provider' => 'mail',
                    'action' => 'manual_cancellation',
                    'status' => 'failed',
                    'request_payload' => [
                        'recipient' => $participant->email,
                        'template' => ConsultationCancellationMail::class,
                        'participant_id' => $participant->id,
                    ],
                    'message' => 'Cancellation email failed: '.$exception->getMessage(),
                ]);

                continue;
            }

            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => 'manual_cancellation',
                'status' => 'sent',
                'request_payload' => [
                    'recipient' => $participant->email,
                    'template' => ConsultationCancellationMail::class,
                    'participant_id' => $participant->id,
                ],
                'message' => 'Cancellation email sent after admin cancelled consultation.',
            ]);

            $sent++;
        }

        if ($sent === 0 && $recipients->isEmpty()) {
            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => 'manual_cancellation',
                'status' => 'skipped',
                'message' => 'No participant email recipients were found for this consultation.',
            ]);
        }

        return $sent;
    }
}
