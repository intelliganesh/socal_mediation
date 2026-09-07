<?php

namespace App\Mail;

use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use App\Services\QuestionnairePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConsultationZoomLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Consultation $consultation,
        public ConsultationParticipant $participant,
        public bool $isReschedule = false
    ) {
        $this->consultation->loadMissing('type');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isReschedule
                ? 'Your rescheduled consultation Zoom meeting link'
                : 'Your consultation Zoom meeting link'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.consultation-zoom-link'
        );
    }

    public function attachments(): array
    {
        $submission = $this->participant->questionnaireSubmissions()
            ->where('consultation_id', $this->consultation->id)
            ->where('status', 'submitted')
            ->first();

        if (! $submission) {
            return [];
        }

        return app(QuestionnairePdfService::class)->mailAttachmentsForParticipant($submission);
    }
}
