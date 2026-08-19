<?php

namespace App\Mail;

use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConsultationConclusionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Consultation $consultation,
        public ConsultationParticipant $participant
    ) {
        $this->consultation->loadMissing(['type', 'professional']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your consultation has been completed'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.consultation-conclusion'
        );
    }
}
