<?php

namespace App\Mail;

use App\Mail\Concerns\UsesApplicationMailFrom;
use App\Models\Consultation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminNewConsultationRequestMail extends Mailable
{
    use Queueable, SerializesModels, UsesApplicationMailFrom;

    public function __construct(public Consultation $consultation)
    {
        $this->consultation->loadMissing(['type', 'professional']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->applicationFrom($this->consultation),
            subject: 'New consultation request: '.$this->consultation->booking_number
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-new-consultation-request'
        );
    }
}
