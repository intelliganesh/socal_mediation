<?php

namespace App\Mail;

use App\Mail\Concerns\UsesApplicationMailFrom;
use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConsultationCancellationMail extends Mailable
{
    use Queueable, SerializesModels, UsesApplicationMailFrom;

    public function __construct(
        public Consultation $consultation,
        public ConsultationParticipant $participant
    ) {
        $this->consultation->loadMissing(['type', 'professional']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->applicationFrom($this->consultation),
            subject: 'Your consultation has been cancelled'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.consultation-cancellation'
        );
    }
}
