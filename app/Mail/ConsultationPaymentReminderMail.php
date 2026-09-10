<?php

namespace App\Mail;

use App\Mail\Concerns\UsesApplicationMailFrom;
use App\Models\PaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConsultationPaymentReminderMail extends Mailable
{
    use Queueable, SerializesModels, UsesApplicationMailFrom;

    public function __construct(
        public PaymentRequest $paymentRequest,
        public bool $isReschedule = false
    ) {
        $this->paymentRequest->loadMissing(['consultation.type', 'participant']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->applicationFrom($this->paymentRequest->consultation),
            subject: $this->isReschedule
                ? 'Rescheduled consultation: complete your payment'
                : 'Reminder: complete your consultation payment'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.consultation-payment-reminder'
        );
    }
}
