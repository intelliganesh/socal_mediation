<?php

namespace App\Services;

use App\Models\Consultation;

class QuestionnaireTemplateService
{
    public function requiresQuestionnaire(Consultation $consultation): bool
    {
        return $consultation->total_amount_cents > 0
            && $consultation->application !== null
            && ! in_array($consultation->status, ['draft', 'cancelled'], true);
    }

    public function templateForConsultation(Consultation $consultation): array
    {
        $key = $this->templateKeyForConsultation($consultation);
        $template = config("questionnaires.templates.{$key}");

        if (! is_array($template)) {
            throw new \RuntimeException("Questionnaire template [{$key}] is not configured.");
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

    public function allowedAnswerKeys(string $templateKey): array
    {
        return collect(config("questionnaires.templates.{$templateKey}.fields", []))
            ->pluck('name')
            ->all();
    }

    public function normalizeAnswers(string $templateKey, array $answers): array
    {
        $allowed = array_flip($this->allowedAnswerKeys($templateKey));
        $unknown = array_diff(array_keys($answers), array_keys($allowed));

        if ($unknown !== []) {
            throw new \DomainException('Unknown questionnaire answer field(s): '.implode(', ', $unknown));
        }

        return collect($answers)
            ->map(fn ($value) => $this->normalizeValue($value))
            ->all();
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
