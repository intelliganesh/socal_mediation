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
            'frontend_path' => 'divorce-mediation',
            'source_pdf' => 'questionnaires/DIVORCE MEDIATION INTAKE QUESTIONNAIRE.pdf',
            'requires_agreement' => true,
            'answer_payload' => [
                'full_name' => null,
                'spouse_partner_name' => null,
                'phone' => null,
                'email' => null,
                'spouse_phone' => null,
                'spouse_email' => null,
                'preferred_language' => null,
                'other_language' => null,
                'referral_source' => null,
                'date_of_marriage' => null,
                'date_of_separation' => null,
                'currently_living_together' => null,
                'moved_out_party' => null,
                'domestic_violence_history' => null,
                'domestic_violence_explanation' => null,
                'children_together' => null,
                'children' => null,
                'current_parenting_schedule' => null,
                'proposed_parenting_schedule' => null,
                'education_decision_maker' => null,
                'medical_decision_maker' => null,
                'child_related_concerns' => [],
                'child_related_concern_other' => null,
                'child_support_discussed_or_ordered' => null,
                'fair_monthly_child_support_amount' => null,
                'monthly_income_you' => null,
                'monthly_income_other_parent' => null,
                'spousal_support_expectation' => null,
                'spousal_support_duration' => null,
                'spousal_monthly_income_you' => null,
                'spousal_monthly_income_spouse' => null,
                'real_estate_types' => [],
                'real_estate_addresses' => null,
                'real_estate_estimated_values' => null,
                'mortgage_balances' => null,
                'bank_account_types' => [],
                'bank_accounts_estimated_total' => null,
                'retirement_account_types' => [],
                'retirement_estimated_total' => null,
                'vehicles' => null,
                'other_asset_types' => [],
                'other_assets_description' => null,
                'debts_credit_cards' => null,
                'debts_loans' => null,
                'debts_other' => null,
                'property_division_goals' => null,
                'divorce_case_filed' => null,
                'case_number' => null,
                'attorneys_involved' => null,
                'mediation_priorities' => null,
                'mediation_concerns' => null,
                'preferred_session_format' => null,
                'same_room_comfort' => null,
                'additional_information' => null,
                'confirmation_accepted' => null,
            ],
        ],
        'socal_party_mediation' => [
            'label' => 'Party Mediation Questionnaire',
            'version' => 1,
            'frontend_path' => 'party-mediation',
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
                'good_outcome' => null,
                'mediation_goals' => [],
                'other_goal' => null,
                'fairness_requirement' => null,
                'flexible_about' => null,
                'non_negotiables' => null,
                'mediator_notes' => null,
                'confirmation_accepted' => null,
            ],
        ],
        'legal_initial_intake' => [
            'label' => 'Initial Intake Questionnaire',
            'version' => 1,
            'frontend_path' => 'initial-intake',
            'source_pdf' => 'questionnaires/Initial Intake [Filllable].pdf',
            'requires_agreement' => false,
            'answer_payload' => [
                'name' => null,
                'address' => null,
                'home_phone' => null,
                'cell_phone' => null,
                'email' => null,
                'alternate_email' => null,
                'advice_or_assistance_needed' => null,
                'referral_source' => null,
                'referral_source_other' => null,
                'paid' => null,
                'confirmation_accepted' => null,
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
