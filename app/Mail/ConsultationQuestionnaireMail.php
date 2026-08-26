<?php

namespace App\Mail;

use App\Models\QuestionnaireSubmission;
use App\Services\QuestionnaireTemplateService;
use App\Services\QuestionnaireWorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConsultationQuestionnaireMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $questionnaireUrl;

    public ?string $agreementUrl;

    public function __construct(
        public QuestionnaireSubmission $submission,
        public bool $isReschedule = false
    ) {
        $this->submission->loadMissing(['consultation.type', 'consultation.professional', 'participant']);
        $workflow = app(QuestionnaireWorkflowService::class);
        $this->questionnaireUrl = $workflow->questionnaireUrl($this->submission) ?? '#';
        $this->agreementUrl = app(QuestionnaireTemplateService::class)->requiresAgreement($this->submission->template_key)
            ? $workflow->agreementUrl($this->submission)
            : null;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isReschedule
                ? 'Rescheduled consultation: complete your questionnaire'
                : 'Please complete your consultation questionnaire'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.consultation-questionnaire'
        );
    }
}
