<?php

namespace App\Console\Commands;

use App\Models\Consultation;
use App\Services\AdminPaymentNotificationService;
use App\Services\QuestionnaireTemplateService;
use App\Services\QuestionnaireWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendConsultationReminders extends Command
{
    protected $signature = 'consultations:send-reminders';

    protected $description = 'Send daily payment reminders and questionnaire reminders for upcoming consultations.';

    public function __construct(
        private readonly AdminPaymentNotificationService $paymentNotifications,
        private readonly QuestionnaireWorkflowService $questionnaires,
        private readonly QuestionnaireTemplateService $templates,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $paymentReminderCount = $this->sendPaymentReminders($now);
        $questionnaireReminderCount = $this->sendQuestionnaireReminders($now);

        $this->info("Payment reminders sent: {$paymentReminderCount}");
        $this->info("Questionnaire reminders sent: {$questionnaireReminderCount}");

        return self::SUCCESS;
    }

    private function sendPaymentReminders(CarbonImmutable $now): int
    {
        $sent = 0;

        Consultation::query()
            ->with(['paymentRequests.participant', 'participants', 'type'])
            ->whereNotNull('starts_at')
            ->whereNotIn('status', ['draft', 'cancelled', 'completed'])
            ->whereHas('paymentRequests', function ($query) {
                $query->whereNot('status', 'paid')
                    ->whereNotNull('payment_url');
            })
            ->where('starts_at', '>=', $now->subDay())
            ->get()
            ->each(function (Consultation $consultation) use (&$sent, $now) {
                if (! $this->paymentReminderWindowOpen($consultation, $now)) {
                    return;
                }

                $sent += $this->paymentNotifications->sendPaymentReminders($consultation, 'automatic_payment_reminder');
            });

        return $sent;
    }

    private function sendQuestionnaireReminders(CarbonImmutable $now): int
    {
        $sent = 0;

        Consultation::query()
            ->with(['participants', 'questionnaireSubmissions.participant', 'paymentRequests.participant', 'type'])
            ->whereNotNull('starts_at')
            ->where('payment_status', 'paid')
            ->whereNotIn('status', ['draft', 'cancelled', 'completed'])
            ->whereBetween('starts_at', [$now->subDay(), $now->addDays(3)->endOfDay()])
            ->get()
            ->each(function (Consultation $consultation) use (&$sent, $now) {
                if (! $this->templates->requiresQuestionnaire($consultation) || ! $this->questionnaireReminderDue($consultation, $now)) {
                    return;
                }

                $sent += $this->questionnaires->sendPendingQuestionnaireReminders($consultation, 'automatic_questionnaire_reminder');
            });

        return $sent;
    }

    private function paymentReminderWindowOpen(Consultation $consultation, CarbonImmutable $now): bool
    {
        $timezone = $consultation->timezone ?: config('app.timezone');
        $bookingDate = $consultation->starts_at?->copy()->timezone($timezone)->toDateString();

        return $bookingDate !== null && $bookingDate >= $now->timezone($timezone)->toDateString();
    }

    private function questionnaireReminderDue(Consultation $consultation, CarbonImmutable $now): bool
    {
        $timezone = $consultation->timezone ?: config('app.timezone');
        $bookingDate = $consultation->starts_at?->copy()->timezone($timezone);

        return $bookingDate !== null && $bookingDate->isSameDay($now->timezone($timezone)->addDays(2));
    }
}
