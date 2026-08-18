<?php

namespace App\Services;

use App\Models\Consultation;

class QuestionnaireTemplateService
{
    public const AGREEMENT_VERSION = '2026-08-18';

    public const TEMPLATES = [
        'socal_divorce_intake' => [
            'label' => 'Divorce Mediation Intake Questionnaire',
            'version' => 1,
            'frontend_path' => 'divorce-intake',
            'source_pdf' => 'questionnaires/DIVORCE MEDIATION INTAKE QUESTIONNAIRE.pdf',
            'requires_agreement' => true,
            'answer_payload' => [
                'preferred_language' => null,
                'other_language' => null,
                'relationship_summary' => null,
                'currently_living_together' => null,
                'domestic_violence_history' => null,
                'children_together' => null,
                'children_details' => null,
                'custody_concerns' => null,
                'child_support_status' => null,
                'spousal_support_expectation' => null,
                'asset_summary' => null,
                'debt_summary' => null,
                'case_filed_status' => null,
                'attorney_involvement' => null,
                'preferred_session_format' => null,
                'same_room_comfort' => null,
                'goals_for_mediation' => null,
                'additional_information' => null,
            ],
        ],
        'socal_party_mediation' => [
            'label' => 'Party Mediation Questionnaire',
            'version' => 1,
            'frontend_path' => 'party-mediation-questionnaire',
            'source_pdf' => 'questionnaires/PARTY MEDIATION QUESTIONNAIRE.pdf',
            'requires_agreement' => true,
            'answer_payload' => [
                'name' => null,
                'date' => null,
                'other_party_name' => null,
                'case_or_matter' => null,
                'dispute_summary' => null,
                'problem_started_at' => null,
                'main_concern' => null,
                'personal_contribution' => null,
                'impact' => null,
                'desired_result' => null,
                'acceptable_solution' => null,
                'unacceptable_result' => null,
                'mediation_goals' => [],
                'other_goal' => null,
                'important_information' => null,
                'signature_name' => null,
                'signature_date' => null,
            ],
        ],
        'legal_initial_intake' => [
            'label' => 'Initial Intake Questionnaire',
            'version' => 1,
            'frontend_path' => 'initial-intake',
            'source_pdf' => 'questionnaires/Initial Intake [Filllable].pdf',
            'requires_agreement' => false,
            'answer_payload' => [
                'previous_consultation' => null,
                'name' => null,
                'address' => null,
                'email' => null,
                'alternate_email' => null,
                'phone' => null,
                'matter_type' => [],
                'other_matter_type' => null,
                'reason_for_visit' => null,
                'desired_outcome' => null,
                'additional_information' => null,
            ],
        ],
    ];

    public function requiresQuestionnaire(Consultation $consultation): bool
    {
        return $consultation->total_amount_cents > 0
            && $consultation->application !== null
            && ! in_array($consultation->status, ['draft', 'cancelled'], true);
    }

    public function templateForConsultation(Consultation $consultation): array
    {
        return $this->template($this->templateKeyForConsultation($consultation));
    }

    public function template(string $key): array
    {
        $template = self::TEMPLATES[$key] ?? null;

        if (! is_array($template)) {
            throw new \RuntimeException("Questionnaire template [{$key}] is not supported.");
        }

        return ['key' => $key, ...$template];
    }

    public function templateKeyForConsultation(Consultation $consultation): string
    {
        if ($consultation->application === 'legal') {
            return 'legal_initial_intake';
        }

        $service = strtolower((string) $consultation->legal_service_name);

        return str_contains($service, 'divorce') || str_contains($service, 'family')
            ? 'socal_divorce_intake'
            : 'socal_party_mediation';
    }

    public function normalizeAnswers(array $answers): array
    {
        return collect($answers)
            ->map(fn ($value) => $this->normalizeValue($value))
            ->all();
    }

    public function answerPayload(string $templateKey): array
    {
        return $this->template($templateKey)['answer_payload'];
    }

    public function requiresAgreement(string $templateKey): bool
    {
        return (bool) $this->template($templateKey)['requires_agreement'];
    }

    public function frontendPath(string $templateKey): string
    {
        return $this->template($templateKey)['frontend_path'];
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return collect($value)
                ->map(fn ($item) => $this->normalizeValue($item))
                ->values()
                ->all();
        }

        if (is_bool($value) || $value === null || is_numeric($value)) {
            return $value;
        }

        return trim((string) $value);
    }
}
