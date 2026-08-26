<?php

namespace App\Mail;

use App\Models\Consultation;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminConsultationRescheduledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Consultation $consultation,
        public ?CarbonInterface $oldStartsAt = null,
        public ?CarbonInterface $newStartsAt = null,
    ) {
        $this->consultation->loadMissing(['type', 'professional']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Consultation rescheduled: '.$this->consultation->booking_number
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-consultation-rescheduled'
        );
    }
}
