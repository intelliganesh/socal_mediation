<?php

namespace App\Services;

use App\Models\QuestionnaireSubmission;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class QuestionnairePdfService
{
    public function download(QuestionnaireSubmission $submission): Response
    {
        $submission->loadMissing(['consultation.type', 'consultation.professional', 'participant']);
        $template = config('questionnaires.templates.'.$submission->template_key, []);
        $filename = Str::slug($submission->consultation->booking_number.'-'.$submission->participant->first_name.'-'.$submission->participant->last_name.'-questionnaire').'.pdf';

        return Pdf::loadView('pdf.questionnaire-summary', [
            'submission' => $submission,
            'consultation' => $submission->consultation,
            'participant' => $submission->participant,
            'template' => $template,
            'fields' => collect($template['fields'] ?? []),
        ])->download($filename);
    }
}
