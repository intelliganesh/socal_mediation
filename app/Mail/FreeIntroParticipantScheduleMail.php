<?php

namespace App\Mail;

use App\Models\ConsultationParticipant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FreeIntroParticipantScheduleMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ConsultationParticipant $participant)
    {
        $this->participant->loadMissing('consultation.type');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Select your 15-minute intro call slot'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.free-intro-participant-schedule'
        );
    }
}
