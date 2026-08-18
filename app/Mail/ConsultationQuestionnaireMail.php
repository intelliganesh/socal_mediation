<?php

namespace App\Mail;

use App\Models\QuestionnaireSubmission;
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

    public function __construct(public QuestionnaireSubmission $submission)
    {
        $this->submission->loadMissing(['consultation.type', 'consultation.professional', 'participant']);
        $workflow = app(QuestionnaireWorkflowService::class);
        $this->questionnaireUrl = $workflow->questionnaireUrl($this->submission) ?? '#';
        $this->agreementUrl = config('questionnaires.templates.'.$this->submission->template_key.'.requires_agreement')
            ? $workflow->agreementUrl($this->submission)
            : null;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Please complete your consultation questionnaire'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.consultation-questionnaire'
        );
    }
}
