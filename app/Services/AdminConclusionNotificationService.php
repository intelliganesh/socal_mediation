<?php

namespace App\Services;

use App\Mail\ConsultationConclusionMail;
use App\Models\Consultation;
use Illuminate\Support\Facades\Mail;

class AdminConclusionNotificationService
{
    public function sendConclusion(Consultation $consultation): int
    {
        $recipients = $consultation->participants()->whereNotNull('email')->get();
        $sent = 0;

        foreach ($recipients as $participant) {
            try {
                Mail::to($participant->email)->send(new ConsultationConclusionMail($consultation, $participant));
            } catch (\Throwable $exception) {
                $consultation->integrationLogs()->create([
                    'provider' => 'mail',
                    'action' => 'manual_conclusion',
                    'status' => 'failed',
                    'request_payload' => [
                        'recipient' => $participant->email,
                        'template' => ConsultationConclusionMail::class,
                        'participant_id' => $participant->id,
                    ],
                    'message' => 'Conclusion email failed: '.$exception->getMessage(),
                ]);

                continue;
            }

            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => 'manual_conclusion',
                'status' => 'sent',
                'request_payload' => [
                    'recipient' => $participant->email,
                    'template' => ConsultationConclusionMail::class,
                    'participant_id' => $participant->id,
                ],
                'message' => 'Conclusion email sent after admin marked consultation completed.',
            ]);

            $sent++;
        }

        if ($sent === 0 && $recipients->isEmpty()) {
            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => 'manual_conclusion',
                'status' => 'skipped',
                'message' => 'No participant email recipients were found for this consultation.',
            ]);
        }

        return $sent;
    }
}
