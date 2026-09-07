<?php

namespace App\Services;

use App\Models\QuestionnaireSubmission;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Str;

class QuestionnairePdfService
{
    public function download(QuestionnaireSubmission $submission): Response
    {
        $submission->loadMissing(['consultation.type', 'consultation.professional', 'participant']);

        return Pdf::loadView('pdf.questionnaire-summary', $this->questionnaireViewData($submission))
            ->download($this->questionnaireFilename($submission));
    }

    public function downloadAgreement(QuestionnaireSubmission $submission): Response
    {
        $submission->loadMissing(['consultation.type', 'consultation.professional', 'participant']);

        return Pdf::loadView('pdf.agreement-summary', $this->agreementViewData($submission))
            ->download($this->agreementFilename($submission));
    }

    public function mailAttachmentsForParticipant(QuestionnaireSubmission $submission): array
    {
        $submission->loadMissing(['consultation.type', 'consultation.professional', 'participant']);

        if ($submission->status !== 'submitted') {
            return [];
        }

        $attachments = [
            Attachment::fromData(
                fn () => Pdf::loadView('pdf.questionnaire-summary', $this->questionnaireViewData($submission))->output(),
                $this->questionnaireFilename($submission)
            )->withMime('application/pdf'),
        ];

        if ($submission->agreement_accepted) {
            $attachments[] = Attachment::fromData(
                fn () => Pdf::loadView('pdf.agreement-summary', $this->agreementViewData($submission))->output(),
                $this->agreementFilename($submission)
            )->withMime('application/pdf');
        }

        return $attachments;
    }

    private function questionnaireViewData(QuestionnaireSubmission $submission): array
    {
        $template = app(QuestionnaireTemplateService::class)->template($submission->template_key);

        return [
            'submission' => $submission,
            'consultation' => $submission->consultation,
            'participant' => $submission->participant,
            'template' => $template,
            'answers' => collect($submission->answers ?? []),
        ];
    }

    private function agreementViewData(QuestionnaireSubmission $submission): array
    {
        return [
            'submission' => $submission,
            'consultation' => $submission->consultation,
            'participant' => $submission->participant,
        ];
    }

    private function questionnaireFilename(QuestionnaireSubmission $submission): string
    {
        return Str::slug($submission->consultation->booking_number.'-'.$submission->participant->first_name.'-'.$submission->participant->last_name.'-questionnaire').'.pdf';
    }

    private function agreementFilename(QuestionnaireSubmission $submission): string
    {
        return Str::slug($submission->consultation->booking_number.'-'.$submission->participant->first_name.'-'.$submission->participant->last_name.'-agreement').'.pdf';
    }
}
