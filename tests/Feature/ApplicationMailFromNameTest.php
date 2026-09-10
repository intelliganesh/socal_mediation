<?php

namespace Tests\Feature;

use App\Mail\AdminConsultationRescheduledMail;
use App\Mail\AdminNewConsultationRequestMail;
use App\Mail\ConsultationCancellationMail;
use App\Mail\ConsultationConclusionMail;
use App\Mail\ConsultationConfirmationMail;
use App\Mail\ConsultationPaymentLinkMail;
use App\Mail\ConsultationPaymentReminderMail;
use App\Mail\ConsultationQuestionnaireMail;
use App\Mail\ConsultationZoomLinkMail;
use App\Mail\FreeIntroParticipantScheduleMail;
use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use App\Models\ConsultationType;
use App\Models\PaymentRequest;
use App\Models\QuestionnaireSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationMailFromNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.from.address' => 'shared@example.test',
            'mail.from.name' => 'Default Sender',
            'mail.application_from_names.socal' => 'SoCal Mediation Center',
            'mail.application_from_names.legal' => 'Law Office of Steve Lopez',
        ]);

        $this->seed();
    }

    public function test_participant_and_admin_mailables_use_socal_sender_name(): void
    {
        [$consultation, $participant, $paymentRequest, $submission] = $this->mailModels('socal');

        $mailables = [
            new ConsultationConfirmationMail($consultation, $participant),
            new ConsultationZoomLinkMail($consultation, $participant),
            new ConsultationCancellationMail($consultation, $participant),
            new ConsultationConclusionMail($consultation, $participant),
            new ConsultationPaymentLinkMail($paymentRequest),
            new ConsultationPaymentReminderMail($paymentRequest),
            new ConsultationQuestionnaireMail($submission),
            new FreeIntroParticipantScheduleMail($participant),
            new AdminNewConsultationRequestMail($consultation),
            new AdminConsultationRescheduledMail($consultation),
        ];

        foreach ($mailables as $mailable) {
            $this->assertSame('shared@example.test', $mailable->envelope()->from->address);
            $this->assertSame('SoCal Mediation Center', $mailable->envelope()->from->name);
        }
    }

    public function test_legal_consultation_mail_uses_legal_sender_name(): void
    {
        [$consultation, $participant] = $this->mailModels('legal');

        $envelope = (new ConsultationConfirmationMail($consultation, $participant))->envelope();

        $this->assertSame('shared@example.test', $envelope->from->address);
        $this->assertSame('Law Office of Steve Lopez', $envelope->from->name);
    }

    public function test_unknown_application_falls_back_to_default_sender_name(): void
    {
        [$consultation, $participant] = $this->mailModels('unknown');

        $envelope = (new ConsultationConfirmationMail($consultation, $participant))->envelope();

        $this->assertSame('shared@example.test', $envelope->from->address);
        $this->assertSame('Default Sender', $envelope->from->name);
    }

    private function mailModels(string $application): array
    {
        $type = ConsultationType::query()
            ->where('application', $application)
            ->first()
            ?? ConsultationType::firstOrFail();

        $consultation = Consultation::create([
            'booking_number' => strtoupper($application).'-MAIL-'.Str::upper(Str::random(5)),
            'consultation_type_id' => $type->id,
            'application' => $application,
            'status' => 'scheduled',
            'payment_status' => 'pending',
            'consultation_mode' => 'online',
            'timezone' => 'America/Los_Angeles',
            'starts_at' => '2026-10-01 10:00:00',
            'ends_at' => '2026-10-01 11:00:00',
            'primary_first_name' => 'Test',
            'primary_last_name' => 'Client',
            'primary_email' => 'client@example.test',
            'total_amount_cents' => 10000,
            'currency' => 'USD',
            'payment_mode' => 'full',
        ]);

        $participant = ConsultationParticipant::create([
            'consultation_id' => $consultation->id,
            'first_name' => 'Test',
            'last_name' => 'Client',
            'email' => 'client@example.test',
            'is_primary' => true,
            'should_pay' => true,
            'share_amount_cents' => 10000,
        ]);

        $paymentRequest = PaymentRequest::create([
            'id' => (string) Str::uuid(),
            'consultation_id' => $consultation->id,
            'participant_id' => $participant->id,
            'provider' => 'converge',
            'status' => 'pending',
            'amount_cents' => 10000,
            'currency' => 'USD',
            'payment_url' => 'https://payments.example.test/pay',
        ]);

        $submission = QuestionnaireSubmission::create([
            'consultation_id' => $consultation->id,
            'participant_id' => $participant->id,
            'template_key' => $application === 'legal' ? 'legal_initial_intake' : 'socal_party_mediation',
            'template_version' => 1,
            'token' => Str::random(64),
            'status' => 'pending',
        ]);

        return [$consultation, $participant, $paymentRequest, $submission];
    }
}
