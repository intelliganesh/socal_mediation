<?php

namespace Tests\Feature;

use App\Mail\AdminConsultationRescheduledMail;
use App\Mail\AdminNewConsultationRequestMail;
use App\Mail\ConsultationConfirmationMail;
use App\Mail\ConsultationPaymentLinkMail;
use App\Mail\ConsultationQuestionnaireMail;
use App\Mail\ConsultationZoomLinkMail;
use App\Mail\FreeIntroParticipantScheduleMail;
use App\Models\AppSetting;
use App\Models\Consultation;
use App\Models\ConsultationType;
use App\Models\ExternalCalendarEvent;
use App\Models\PaymentRequest;
use App\Models\QuestionnaireSubmission;
use App\Services\Integrations\ConvergeClient;
use App\Services\QuestionnaireWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsultationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_payment_status_uses_application_redirect_and_brand(): void
    {
        $this->seed();
        config([
            'app.payment_redirect_urls.socal' => 'https://socal.example.test/payment-complete',
            'app.payment_redirect_urls.legal' => 'https://legal.example.test/payment-complete',
        ]);

        $socalPayment = Consultation::where('booking_number', 'SAMPLE-03')
            ->firstOrFail()
            ->paymentRequests()
            ->firstOrFail();
        $socalPayment->update(['status' => 'paid']);

        $this->get(URL::signedRoute('payments.status.show', [
            'paymentRequest' => $socalPayment,
            'state' => 'success',
        ]))
            ->assertOk()
            ->assertSee('Continue to SoCal Mediation Center')
            ->assertSee('href="https://socal.example.test/payment-complete" style="display:inline-block;margin-top:24px;border-radius:6px;background:#082BC3', false)
            ->assertDontSee('https://legal.example.test/payment-complete');

        $legalPayment = Consultation::where('booking_number', 'SAMPLE-08')
            ->firstOrFail()
            ->paymentRequests()
            ->firstOrFail();
        $legalPayment->update(['status' => 'paid']);

        $this->get(URL::signedRoute('payments.status.show', [
            'paymentRequest' => $legalPayment,
            'state' => 'success',
        ]))
            ->assertOk()
            ->assertSee('Continue to Legal Consultation')
            ->assertSee('href="https://legal.example.test/payment-complete" style="display:inline-block;margin-top:24px;border-radius:6px;background:#75172E', false)
            ->assertDontSee('https://socal.example.test/payment-complete');
    }

    public function test_payment_redirect_is_hidden_until_paid_and_for_unsafe_url(): void
    {
        $this->seed();
        $payment = Consultation::where('booking_number', 'SAMPLE-08')
            ->firstOrFail()
            ->paymentRequests()
            ->firstOrFail();

        config(['app.payment_redirect_urls.legal' => 'https://legal.example.test/payment-complete']);

        $this->get(URL::signedRoute('payments.status.show', [
            'paymentRequest' => $payment,
            'state' => 'pending',
        ]))
            ->assertOk()
            ->assertDontSee('Continue to Legal Consultation')
            ->assertDontSee('https://legal.example.test/payment-complete');

        $payment->update(['status' => 'paid']);
        config(['app.payment_redirect_urls.legal' => 'javascript:alert(1)']);

        $this->get(URL::signedRoute('payments.status.show', [
            'paymentRequest' => $payment,
            'state' => 'success',
        ]))
            ->assertOk()
            ->assertDontSee('Continue to Legal Consultation')
            ->assertDontSee('javascript:alert(1)');
    }

    public function test_payment_success_page_shows_only_agreement_link_when_agreement_and_questionnaire_are_pending(): void
    {
        $this->seed();
        config(['app.payment_redirect_urls.socal' => 'https://socal.example.test']);

        $consultation = Consultation::where('booking_number', 'SAMPLE-03')->firstOrFail();
        $payment = $consultation->paymentRequests()
            ->with('participant')
            ->where('status', 'paid')
            ->firstOrFail();

        $submission = app(QuestionnaireWorkflowService::class)
            ->ensureSubmission($consultation, $payment->participant);

        $submission->update([
            'status' => 'pending',
            'agreement_accepted' => false,
        ]);

        $this->get(URL::signedRoute('payments.status.show', [
            'paymentRequest' => $payment,
            'state' => 'success',
        ]))
            ->assertOk()
            ->assertSee('Proceed to Agreement')
            ->assertDontSee('Proceed to Questionnaire')
            ->assertSee('/agreement?token='.$submission->token, false)
            ->assertDontSee('/party-mediation?token='.$submission->token, false);
    }

    public function test_it_creates_a_draft_consultation(): void
    {
        $this->seed();

        $type = ConsultationType::where('slug', 'socal-full-day-mediation')->firstOrFail();

        $response = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.type.slug', 'socal-full-day-mediation');

        $this->assertFalse(Schema::hasColumn('consultations', 'uuid'));
        $this->assertTrue(Str::isUuid($response->json('data.uuid')));
        $this->assertDatabaseHas('consultations', [
            'id' => $response->json('data.uuid'),
        ]);
    }

    public function test_new_consultation_request_notification_is_sent_after_completion_not_draft(): void
    {
        $this->seed();
        Mail::fake();
        config([
            'services.converge.enabled' => false,
            'services.outlook.enabled' => false,
        ]);

        AppSetting::create([
            'key' => 'admin_new_consultation_notifications',
            'value' => [
                'enabled' => true,
                'emails' => ['owner@example.com', 'manager@example.com'],
            ],
        ]);

        $type = ConsultationType::where('slug', 'legal-professional-consultation')->firstOrFail();

        $consultationId = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'consultation_mode' => 'phone',
            'primary_client' => [
                'first_name' => 'New',
                'last_name' => 'Client',
                'email' => 'new.client@example.com',
            ],
        ])->assertCreated()->json('data.uuid');

        Mail::assertNotSent(AdminNewConsultationRequestMail::class);

        $this->postJson('/api/v1/consultations/'.$consultationId.'/complete', [
            'starts_at' => '2026-10-19T13:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'payment_mode' => 'full',
            'payment_method' => 'card',
        ])->assertOk();

        Mail::assertSent(AdminNewConsultationRequestMail::class, 2);
        Mail::assertSent(AdminNewConsultationRequestMail::class, fn (AdminNewConsultationRequestMail $mail) => $mail->hasTo('owner@example.com'));
        Mail::assertSent(AdminNewConsultationRequestMail::class, fn (AdminNewConsultationRequestMail $mail) => $mail->hasTo('manager@example.com'));

        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultationId,
            'provider' => 'mail',
            'action' => 'admin_new_consultation_request',
            'status' => 'sent',
        ]);
    }

    public function test_completing_consultation_immediately_creates_events_in_both_outlook_calendars(): void
    {
        $this->seed();
        Mail::fake();
        config([
            'services.outlook.enabled' => true,
            'services.outlook.tenant_id' => 'tenant-id',
            'services.outlook.client_id' => 'client-id',
            'services.outlook.client_secret' => 'client-secret',
            'services.outlook.login_base_url' => 'https://login.microsoftonline.com',
            'services.outlook.base_url' => 'https://graph.microsoft.com/v1.0',
            'services.outlook.socal_user_id' => 'socal@example.com',
            'services.outlook.socal_calendar_id' => 'socal-calendar',
            'services.outlook.legal_user_id' => 'legal@example.com',
            'services.outlook.legal_calendar_id' => 'legal-calendar',
        ]);

        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response(['access_token' => 'graph-token']),
            'graph.microsoft.com/v1.0/users/socal%40example.com/calendars/socal-calendar/events' => Http::response([
                'id' => 'completion-socal-event-id',
                'webLink' => 'https://outlook.office.com/completion-socal-event',
            ], 201),
            'graph.microsoft.com/v1.0/users/legal%40example.com/calendars/legal-calendar/events' => Http::response([
                'id' => 'completion-legal-event-id',
                'webLink' => 'https://outlook.office.com/completion-legal-event',
            ], 201),
        ]);

        $type = ConsultationType::where('slug', 'legal-professional-consultation')->firstOrFail();
        $consultationId = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'consultation_mode' => 'phone',
            'primary_client' => [
                'first_name' => 'Dual',
                'last_name' => 'Calendar',
                'email' => 'dual.calendar@example.com',
            ],
        ])->assertCreated()->json('data.uuid');

        $this->postJson('/api/v1/consultations/'.$consultationId.'/complete', [
            'starts_at' => '2026-10-19T13:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'payment_mode' => 'full',
            'payment_method' => 'card',
        ])->assertOk();

        foreach (['socal' => 'completion-socal-event-id', 'legal' => 'completion-legal-event-id'] as $application => $eventId) {
            $this->assertDatabaseHas('external_calendar_events', [
                'provider' => 'outlook',
                'external_id' => 'consultation-'.$consultationId.'-'.$application,
                'application' => $application,
                'metadata->outlook_event_id' => $eventId,
            ]);
        }

        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultationId,
            'provider' => 'outlook',
            'action' => 'automatic_completion_sync',
            'status' => 'synced',
        ]);
    }

    public function test_it_saves_details_form_fields_on_draft_without_completing_it(): void
    {
        $this->seed();

        $type = ConsultationType::where('slug', 'socal-full-day-mediation')->firstOrFail();

        $response = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'legal_service_name' => 'Business, Payment & Contract Disputes',
            'consultation_mode' => 'online',
            'referral_source' => 'Google',
            'primary_client' => [
                'first_name' => 'Jonathan',
                'last_name' => 'Miller',
                'email' => 'miller123@gmail.com',
                'phone_country' => '+1',
                'phone' => '(495) 060-0000',
            ],
            'participants' => [
                [
                    'first_name' => 'Morgan',
                    'last_name' => 'Miller',
                    'email' => 'morgan.miller@example.com',
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.consultation_mode', 'online')
            ->assertJsonPath('data.legal_service_name', 'Business, Payment & Contract Disputes')
            ->assertJsonPath('data.primary_client.email', 'miller123@gmail.com')
            ->assertJsonCount(2, 'data.participants');
    }

    public function test_it_saves_and_displays_other_referral_source(): void
    {
        $this->seed();

        $type = ConsultationType::where('slug', 'socal-full-day-mediation')->firstOrFail();

        $draft = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'consultation_mode' => 'online',
            'referral_source' => 'Other Referral',
            'referral_source_others' => 'Neighbor referral group',
            'primary_client' => [
                'first_name' => 'Referral',
                'email' => 'referral@example.com',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.referral_source', 'Other Referral')
            ->assertJsonPath('data.referral_source_others', 'Neighbor referral group')
            ->assertJsonPath('data.referral_source_display', 'Neighbor referral group')
            ->json('data.uuid');

        $this->assertDatabaseHas('consultations', [
            'id' => $draft,
            'referral_source' => 'Other Referral',
            'referral_source_others' => 'Neighbor referral group',
        ]);

        $this->getJson('/api/v1/consultations/'.$draft)
            ->assertOk()
            ->assertJsonPath('data.referral_source_display', 'Neighbor referral group');
    }

    public function test_non_other_referral_source_clears_custom_referral_text(): void
    {
        $this->seed();

        $type = ConsultationType::where('slug', 'socal-full-day-mediation')->firstOrFail();

        $response = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'referral_source' => 'Google',
            'referral_source_others' => 'Should not be stored',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.referral_source', 'Google')
            ->assertJsonPath('data.referral_source_others', null)
            ->assertJsonPath('data.referral_source_display', 'Google');
    }

    public function test_it_returns_consultation_type_catalog(): void
    {
        $this->seed();

        $this->getJson('/api/v1/consultation-types?application=legal')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_it_completes_consultation_using_legal_service_name_and_sends_payment_links(): void
    {
        $this->seed();
        Mail::fake();
        $this->enableConverge();

        $type = ConsultationType::where('slug', 'socal-half-day-mediation')->firstOrFail();
        $consultationUuid = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
        ])->json('data.uuid');

        $this->postJson("/api/v1/consultations/{$consultationUuid}/complete", [
            'legal_service_name' => 'Business, Payment & Contract Disputes',
            'consultation_mode' => 'online',
            'starts_at' => '2026-08-03T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'payment_mode' => 'split',
            'payment_method' => 'card',
            'primary_client' => [
                'first_name' => 'Taylor',
                'last_name' => 'Reed',
                'email' => 'taylor.reed@example.com',
                'phone_country' => '+1',
                'phone' => '(555) 010-9090',
            ],
            'participants' => [
                [
                    'first_name' => 'Morgan',
                    'last_name' => 'Reed',
                    'email' => 'morgan.reed@example.com',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.legal_service_name', 'Business, Payment & Contract Disputes')
            ->assertJsonPath('data.status', 'payment_pending')
            ->assertJsonPath('data.payment_progress.total', 2);

        $this->assertDatabaseHas('consultations', [
            'id' => $consultationUuid,
            'primary_email' => 'taylor.reed@example.com',
            'payment_mode' => 'split',
        ]);

        Mail::assertSent(ConsultationPaymentLinkMail::class, 2);
        $this->assertDatabaseHas('integration_logs', [
            'provider' => 'mail',
            'action' => 'automatic_payment_link',
            'status' => 'sent',
        ]);
    }

    public function test_free_intro_single_participant_confirms_without_payment(): void
    {
        $this->seed();
        Mail::fake();

        $type = ConsultationType::where('slug', 'socal-free-intro-call')->firstOrFail();

        $consultationId = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'consultation_mode' => 'phone',
            'primary_client' => [
                'first_name' => 'Single',
                'last_name' => 'Intro',
                'email' => 'single.intro@example.com',
            ],
        ])->assertCreated()->json('data.uuid');

        $this->postJson('/api/v1/consultations/'.$consultationId.'/complete', [
            'starts_at' => '2026-10-20T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_progress.total', 0);

        $this->assertDatabaseHas('consultation_participants', [
            'consultation_id' => $consultationId,
            'email' => 'single.intro@example.com',
            'is_primary' => true,
            'scheduling_status' => 'scheduled',
        ]);

        Mail::assertSent(ConsultationConfirmationMail::class, 1);
        Mail::assertNotSent(FreeIntroParticipantScheduleMail::class);
        Mail::assertNotSent(ConsultationPaymentLinkMail::class);
    }

    public function test_booking_uses_configured_timezone_when_frontend_sends_conflicting_timezone(): void
    {
        $this->seed();
        Mail::fake();
        config([
            'app.timezone' => 'Asia/Kolkata',
            'app.booking_timezone' => 'Asia/Kolkata',
            'services.outlook.enabled' => true,
            'services.outlook.tenant_id' => 'tenant',
            'services.outlook.client_id' => 'client',
            'services.outlook.client_secret' => 'secret',
            'services.outlook.login_base_url' => 'https://login.microsoftonline.com',
            'services.outlook.base_url' => 'https://graph.microsoft.com/v1.0',
            'services.outlook.socal_user_id' => 'socal-user',
            'services.outlook.legal_user_id' => 'legal-user',
        ]);

        $eventPayloads = [];
        Http::fake(function ($request) use (&$eventPayloads) {
            if (str_contains($request->url(), 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'outlook-token']);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/events')) {
                $eventPayloads[] = json_decode($request->body(), true);

                return Http::response([
                    'id' => (string) Str::uuid(),
                    'webLink' => 'https://outlook.example.test/event',
                    'showAs' => 'busy',
                ], 201);
            }

            return Http::response(['value' => []]);
        });

        $type = ConsultationType::where('slug', 'socal-free-intro-call')->firstOrFail();
        $consultationId = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'consultation_mode' => 'phone',
            'primary_client' => [
                'first_name' => 'Timezone',
                'last_name' => 'Mismatch',
                'email' => 'timezone.mismatch@example.com',
            ],
        ])->assertCreated()->json('data.uuid');

        $this->postJson('/api/v1/consultations/'.$consultationId.'/complete', [
            'starts_at' => '2026-08-26T10:45:00+05:30',
            'timezone' => 'America/Los_Angeles',
        ])
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Asia/Kolkata');

        $consultation = Consultation::findOrFail($consultationId);

        $this->assertSame('Asia/Kolkata', $consultation->timezone);
        $this->assertSame('2026-08-26 10:45:00', $consultation->getRawOriginal('starts_at'));
        $this->assertNotEmpty($eventPayloads);
        foreach ($eventPayloads as $payload) {
            $this->assertSame('2026-08-26T10:45:00', $payload['start']['dateTime']);
            $this->assertSame('India Standard Time', $payload['start']['timeZone']);
        }
    }

    public function test_free_intro_multiple_participants_invites_others_before_final_confirmation(): void
    {
        $this->seed();
        Mail::fake();
        config([
            'services.outlook.enabled' => true,
            'services.outlook.tenant_id' => 'tenant',
            'services.outlook.client_id' => 'client',
            'services.outlook.client_secret' => 'secret',
            'services.outlook.login_base_url' => 'https://login.microsoftonline.com',
            'services.outlook.base_url' => 'https://graph.microsoft.com/v1.0',
            'services.outlook.socal_user_id' => 'socal-user',
            'services.outlook.legal_user_id' => 'legal-user',
        ]);
        $eventCreateRequests = 0;
        Http::fake(function ($request) use (&$eventCreateRequests) {
            if (str_contains($request->url(), 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'outlook-token']);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/events')) {
                $eventCreateRequests++;

                return Http::response([
                    'id' => (string) Str::uuid(),
                    'webLink' => 'https://outlook.example.test/event',
                    'showAs' => 'busy',
                ], 201);
            }

            return Http::response(['value' => []]);
        });

        $type = ConsultationType::where('slug', 'socal-free-intro-call')->firstOrFail();

        $consultationId = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'consultation_mode' => 'phone',
            'primary_client' => [
                'first_name' => 'Primary',
                'last_name' => 'Intro',
                'email' => 'primary.intro@example.com',
            ],
            'participants' => [
                [
                    'first_name' => 'Second',
                    'last_name' => 'Intro',
                    'email' => 'second.intro@example.com',
                ],
                [
                    'first_name' => 'Third',
                    'last_name' => 'Intro',
                    'email' => 'third.intro@example.com',
                ],
            ],
        ])->assertCreated()->json('data.uuid');

        $this->postJson('/api/v1/consultations/'.$consultationId.'/complete', [
            'starts_at' => '2026-10-21T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_status', 'paid');

        Mail::assertSent(FreeIntroParticipantScheduleMail::class, 2);
        Mail::assertSent(ConsultationConfirmationMail::class, 1);
        Mail::assertNotSent(ConsultationPaymentLinkMail::class);

        $participants = Consultation::findOrFail($consultationId)->participants()->where('is_primary', false)->orderBy('email')->get();
        $this->assertCount(2, $participants);
        $this->assertNotNull($participants[0]->scheduling_token);
        $this->assertSame('pending', $participants[0]->scheduling_status);

        $firstSlotResponse = $this->postJson('/api/v1/free-intro-slots/'.$participants[0]->scheduling_token, [
            'starts_at' => '2026-10-21T09:15:00-07:00',
            'timezone' => 'America/Los_Angeles',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.starts_at', '2026-10-21T09:15:00-07:00')
            ->assertJsonPath('data.ends_at', '2026-10-21T09:30:00-07:00')
            ->assertJsonPath('data.timezone', 'America/Los_Angeles')
            ->assertJsonMissingPath('data.scheduled_participant_slot');

        $this->assertNotSame('2026-10-21T09:00:00-07:00', $firstSlotResponse->json('data.starts_at'));

        Mail::assertSent(ConsultationConfirmationMail::class, 2);

        $this->postJson('/api/v1/free-intro-slots/'.$participants[1]->scheduling_token, [
            'starts_at' => '2026-10-21T09:30:00-07:00',
            'timezone' => 'America/Los_Angeles',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');

        Mail::assertSent(ConsultationConfirmationMail::class, 3);

        $this->assertDatabaseHas('consultation_participants', [
            'id' => $participants[0]->id,
            'scheduling_status' => 'scheduled',
        ]);
        $this->assertDatabaseHas('consultation_participants', [
            'id' => $participants[1]->id,
            'scheduling_status' => 'scheduled',
        ]);

        $allParticipants = Consultation::findOrFail($consultationId)->participants()->orderBy('email')->get();
        foreach ($allParticipants as $participant) {
            $this->assertDatabaseHas('external_calendar_events', [
                'provider' => 'outlook',
                'external_id' => 'free-intro-participant-'.$participant->id.'-socal',
                'application' => 'socal',
            ]);
            $this->assertDatabaseHas('external_calendar_events', [
                'provider' => 'outlook',
                'external_id' => 'free-intro-participant-'.$participant->id.'-legal',
                'application' => 'legal',
            ]);
        }

        $this->assertSame(6, $eventCreateRequests);
    }

    public function test_free_intro_participant_slot_rejects_invalid_token(): void
    {
        $this->seed();
        Mail::fake();

        $type = ConsultationType::where('slug', 'socal-free-intro-call')->firstOrFail();
        $consultationId = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'consultation_mode' => 'phone',
            'primary_client' => [
                'first_name' => 'Primary',
                'email' => 'primary.invalid@example.com',
            ],
            'participants' => [[
                'first_name' => 'Other',
                'email' => 'other.invalid@example.com',
            ]],
        ])->assertCreated()->json('data.uuid');

        $this->postJson('/api/v1/consultations/'.$consultationId.'/complete', [
            'starts_at' => '2026-10-22T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
        ])->assertOk();

        $participant = Consultation::findOrFail($consultationId)->participants()->where('is_primary', false)->firstOrFail();

        $this->postJson('/api/v1/free-intro-slots/wrong-token', [
            'starts_at' => '2026-10-22T09:15:00-07:00',
            'timezone' => 'America/Los_Angeles',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The participant scheduling token is invalid.');
    }

    public function test_free_intro_participant_schedule_email_uses_frontend_link(): void
    {
        $this->seed();
        config([
            'app.frontend_url' => 'https://booking-frontend.example.test',
            'app.payment_redirect_urls.socal' => 'https://payment-result.example.test',
        ]);

        $type = ConsultationType::where('slug', 'socal-free-intro-call')->firstOrFail();
        $consultationId = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'consultation_mode' => 'phone',
            'primary_client' => [
                'first_name' => 'Primary',
                'email' => 'primary.link@example.com',
            ],
            'participants' => [[
                'first_name' => 'Other',
                'email' => 'other.link@example.com',
            ]],
        ])->assertCreated()->json('data.uuid');

        $this->postJson('/api/v1/consultations/'.$consultationId.'/complete', [
            'starts_at' => '2026-10-23T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
        ])->assertOk();

        $participant = Consultation::findOrFail($consultationId)->participants()->where('is_primary', false)->firstOrFail();
        $html = (new FreeIntroParticipantScheduleMail($participant))->render();

        $this->assertStringContainsString('https://booking-frontend.example.test/free-intro-schedule?token='.$participant->scheduling_token, $html);
        $this->assertStringContainsString('Slot Selection', $html);
        $this->assertStringContainsString('Please choose your preferred available slot.', $html);
        $this->assertStringNotContainsString('Oct 23, 2026 - 9:00 AM America/Los_Angeles', $html);
        $this->assertStringNotContainsString('https://payment-result.example.test', $html);
    }

    public function test_split_payment_links_are_sent_only_to_selected_payment_participant_emails(): void
    {
        $this->seed();
        Mail::fake();
        $this->enableConverge();

        $type = ConsultationType::where('slug', 'socal-full-day-mediation')->firstOrFail();
        $consultationUuid = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
        ])->json('data.uuid');

        $response = $this->postJson("/api/v1/consultations/{$consultationUuid}/complete", [
            'legal_service_name' => 'Business, Payment & Contract Disputes',
            'consultation_mode' => 'online',
            'starts_at' => '2026-08-04T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'payment_mode' => 'split',
            'payment_method' => 'card',
            'payment_participant_emails' => [
                'morgan.selected@example.com',
                'riley.selected@example.com',
            ],
            'primary_client' => [
                'first_name' => 'Taylor',
                'last_name' => 'Selected',
                'email' => 'taylor.selected@example.com',
            ],
            'participants' => [
                [
                    'first_name' => 'Morgan',
                    'last_name' => 'Selected',
                    'email' => 'morgan.selected@example.com',
                ],
                [
                    'first_name' => 'Jordan',
                    'last_name' => 'Skipped',
                    'email' => 'jordan.skipped@example.com',
                ],
                [
                    'first_name' => 'Riley',
                    'last_name' => 'Selected',
                    'email' => 'riley.selected@example.com',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.payment_progress.total', 2)
            ->json('data');

        $this->assertCount(2, $response['payment_requests']);

        Mail::assertSent(ConsultationPaymentLinkMail::class, 2);
        Mail::assertSent(ConsultationPaymentLinkMail::class, fn (ConsultationPaymentLinkMail $mail) => $mail->paymentRequest->participant->email === 'morgan.selected@example.com');
        Mail::assertSent(ConsultationPaymentLinkMail::class, fn (ConsultationPaymentLinkMail $mail) => $mail->paymentRequest->participant->email === 'riley.selected@example.com');
        Mail::assertNotSent(ConsultationPaymentLinkMail::class, fn (ConsultationPaymentLinkMail $mail) => $mail->paymentRequest->participant->email === 'taylor.selected@example.com');
        Mail::assertNotSent(ConsultationPaymentLinkMail::class, fn (ConsultationPaymentLinkMail $mail) => $mail->paymentRequest->participant->email === 'jordan.skipped@example.com');
    }

    public function test_full_payment_link_is_sent_to_primary_client(): void
    {
        $this->seed();
        Mail::fake();
        $this->enableConverge();

        $type = ConsultationType::where('slug', 'socal-half-day-mediation')->firstOrFail();
        $consultationUuid = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
        ])->json('data.uuid');

        $response = $this->postJson("/api/v1/consultations/{$consultationUuid}/complete", [
            'legal_service_name' => 'Business, Payment & Contract Disputes',
            'consultation_mode' => 'online',
            'starts_at' => '2026-08-05T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'payment_mode' => 'full',
            'payment_method' => 'card',
            'primary_client' => [
                'first_name' => 'Primary',
                'last_name' => 'Payer',
                'email' => 'primary.payer@example.com',
            ],
            'participants' => [
                [
                    'first_name' => 'Other',
                    'last_name' => 'Participant',
                    'email' => 'other.participant@example.com',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.payment_progress.total', 1)
            ->json('data');

        $this->assertCount(1, $response['payment_requests']);
        $this->assertFalse(Schema::hasColumn('payment_requests', 'uuid'));
        $this->assertTrue(Str::isUuid($response['payment_requests'][0]['id']));

        Mail::assertSent(ConsultationPaymentLinkMail::class, 1);
        Mail::assertSent(ConsultationPaymentLinkMail::class, fn (ConsultationPaymentLinkMail $mail) => $mail->paymentRequest->participant->email === 'primary.payer@example.com');
        Mail::assertNotSent(ConsultationPaymentLinkMail::class, fn (ConsultationPaymentLinkMail $mail) => $mail->paymentRequest->participant->email === 'other.participant@example.com');
    }

    public function test_payment_link_email_uses_confirmation_template_with_socal_brand_and_type_icon(): void
    {
        $this->seed();

        $consultation = Consultation::where('booking_number', 'SAMPLE-03')->firstOrFail();
        $paymentRequest = $consultation->paymentRequests()->where('status', 'pending')->firstOrFail();
        $html = (new ConsultationPaymentLinkMail($paymentRequest))->render();

        $this->assertStringContainsString('Consultation Payment', $html);
        $this->assertStringContainsString('BOOKING ID: '.$consultation->booking_number, $html);
        $this->assertStringContainsString('Payment Pending', $html);
        $this->assertStringContainsString('Pay Consultation Fee', $html);
        $this->assertStringContainsString('background:#082BC3', $html);
        $this->assertStringContainsString('background:#F1F6FE', $html);
        $this->assertStringContainsString($paymentRequest->payment_url, $html);
        $this->assertStringContainsString('admin-icons/socal_payment_pending.svg', $html);
        $this->assertStringContainsString('admin-icons/socal_law.svg', $html);
        $this->assertStringContainsString('admin-icons/socal_calendar.svg', $html);
        $this->assertStringContainsString('admin-icons/consultation_type'.$consultation->consultation_type_id.'.svg', $html);
    }

    public function test_final_paid_email_uses_confirmation_template_and_includes_zoom_link_when_available(): void
    {
        $this->seed();
        config(['app.frontend_url' => 'https://booking.example.test/']);

        $consultation = Consultation::where('booking_number', 'SAMPLE-04')->firstOrFail();
        $participant = $consultation->participants()->whereNotNull('email')->firstOrFail();
        $html = (new ConsultationZoomLinkMail($consultation, $participant))->render();

        $this->assertStringContainsString('Consultation Confirmed', $html);
        $this->assertStringContainsString('Payment Successful', $html);
        $this->assertStringContainsString('BOOKING ID: '.$consultation->booking_number, $html);
        $this->assertStringContainsString('Join Zoom Meeting', $html);
        $this->assertStringContainsString($consultation->zoom_join_url, $html);
        $this->assertStringContainsString('href="https://booking.example.test/reschedule/'.$consultation->id.'"', $html);
        $this->assertStringContainsString('>Reschedule Booking</a>', $html);
        $this->assertStringNotContainsString('[reschedule/', $html);
        $this->assertStringContainsString('admin-icons/socal_payment_check.svg', $html);
        $this->assertStringContainsString('admin-icons/video.svg', $html);
    }

    public function test_legal_email_uses_law_firm_brand_and_type_icon(): void
    {
        $this->seed();

        $consultation = Consultation::where('booking_number', 'SAMPLE-07')->firstOrFail();
        $participant = $consultation->participants()->whereNotNull('email')->firstOrFail();
        $html = (new ConsultationZoomLinkMail($consultation, $participant))->render();

        $this->assertStringContainsString('background:#75172E', $html);
        $this->assertStringContainsString('background:#E8DDE1', $html);
        $this->assertStringContainsString('color:#75172E', $html);
        $this->assertStringContainsString('admin-icons/legal_payment_check.svg', $html);
        $this->assertStringContainsString('admin-icons/legal.svg', $html);
        $this->assertStringContainsString('admin-icons/consultation_type'.$consultation->consultation_type_id.'.svg', $html);
    }

    public function test_email_shows_configured_phone_or_location_for_non_zoom_modes(): void
    {
        $this->seed();
        config([
            'app.consultation_contact.phone.socal' => '+1 (555) 222-3333',
            'app.consultation_contact.location_address.socal' => '123 Mediation Way, Los Angeles, CA 90001',
        ]);

        $consultation = Consultation::where('booking_number', 'SAMPLE-03')->firstOrFail();
        $participant = $consultation->participants()->whereNotNull('email')->firstOrFail();

        $consultation->update(['consultation_mode' => 'phone']);
        $phoneHtml = (new ConsultationConfirmationMail($consultation->refresh(), $participant))->render();
        $this->assertStringContainsString('Phone Number', $phoneHtml);
        $this->assertStringContainsString('+1 (555) 222-3333', $phoneHtml);
        $this->assertStringNotContainsString('123 Mediation Way', $phoneHtml);

        $consultation->update(['consultation_mode' => 'offline']);
        $locationHtml = (new ConsultationConfirmationMail($consultation->refresh(), $participant))->render();
        $this->assertStringContainsString('Location Address', $locationHtml);
        $this->assertStringContainsString('123 Mediation Way, Los Angeles, CA 90001', $locationHtml);
        $this->assertStringNotContainsString('+1 (555) 222-3333', $locationHtml);
    }

    public function test_it_completes_using_details_already_saved_on_draft(): void
    {
        $this->seed();

        $type = ConsultationType::where('slug', 'socal-half-day-mediation')->firstOrFail();
        $consultationUuid = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'legal_service_name' => 'Business, Payment & Contract Disputes',
            'consultation_mode' => 'online',
            'primary_client' => [
                'first_name' => 'Jonathan',
                'last_name' => 'Miller',
                'email' => 'miller123@gmail.com',
                'phone_country' => '+1',
                'phone' => '(495) 060-0000',
            ],
            'participants' => [
                [
                    'first_name' => 'Morgan',
                    'last_name' => 'Miller',
                    'email' => 'morgan.miller@example.com',
                ],
            ],
        ])->json('data.uuid');

        $this->postJson("/api/v1/consultations/{$consultationUuid}/complete", [
            'starts_at' => '2026-08-03T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'payment_mode' => 'split',
            'payment_method' => 'card',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.primary_client.email', 'miller123@gmail.com')
            ->assertJsonPath('data.consultation_mode', 'online')
            ->assertJsonPath('data.payment_progress.total', 2)
            ->assertJsonPath('data.status', 'payment_pending');
    }

    public function test_zoom_url_is_created_and_returned_after_paid_webhook(): void
    {
        $this->seed();
        config([
            'services.converge.mode' => 'sandbox',
            'services.converge.sandbox_base_url' => 'https://api.demo.convergepay.com',
            'services.converge.account_id' => 'account-id',
            'services.converge.user_id' => 'api-user',
            'services.converge.pin' => 'secret-pin',
            'services.converge.return_url' => 'http://localhost/api/v1/payments/converge/return',
        ]);
        Http::fake([
            'api.demo.convergepay.com/VirtualMerchantDemo/processxml.do' => Http::response(
                '<txn><ssl_result>0</ssl_result><ssl_result_message>APPROVAL</ssl_result_message><ssl_txn_id>verified-zoom-txn</ssl_txn_id></txn>',
                200
            ),
        ]);

        $type = ConsultationType::where('slug', 'socal-half-day-mediation')->firstOrFail();
        $consultationUuid = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'legal_service_name' => 'Business, Payment & Contract Disputes',
            'consultation_mode' => 'online',
            'primary_client' => [
                'first_name' => 'Zoom',
                'last_name' => 'Check',
                'email' => 'zoom.check@example.com',
            ],
        ])->json('data.uuid');

        $complete = $this->postJson("/api/v1/consultations/{$consultationUuid}/complete", [
            'starts_at' => '2026-08-03T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'payment_mode' => 'full',
            'payment_method' => 'card',
        ])
            ->assertOk()
            ->assertJsonPath('data.zoom_join_url', null)
            ->json('data');

        $providerReference = $complete['payment_requests'][0]['provider_reference'];

        $this->postJson('/api/v1/payments/converge/webhook', [
            'provider_reference' => $providerReference,
            'status' => 'paid',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.consultation_mode', 'online')
            ->assertJson(fn ($json) => $json->where('success', true)->whereType('data.zoom_join_url', 'string')->etc());
    }

    public function test_converge_confirmation_marks_full_payment_paid_and_finalizes_consultation(): void
    {
        $this->seed();
        Mail::fake();
        config([
            'services.converge.mode' => 'sandbox',
            'services.converge.sandbox_base_url' => 'https://api.demo.convergepay.com',
            'services.converge.account_id' => 'account-id',
            'services.converge.user_id' => 'api-user',
            'services.converge.pin' => 'secret-pin',
            'services.converge.return_url' => 'http://localhost/api/v1/payments/converge/return',
            'services.zoom.enabled' => true,
            'services.zoom.oauth_base_url' => 'https://zoom.test',
            'services.zoom.base_url' => 'https://api.zoom.test/v2',
            'services.zoom.account_id' => 'account-id',
            'services.zoom.client_id' => 'client-id',
            'services.zoom.client_secret' => 'client-secret',
            'app.payment_redirect_urls.socal' => 'https://socal.example.test',
        ]);
        Http::fake([
            'api.demo.convergepay.com/VirtualMerchantDemo/processxml.do' => Http::response(
                '<txn><ssl_result>0</ssl_result><ssl_result_message>APPROVAL</ssl_result_message><ssl_txn_id>converge-txn-100</ssl_txn_id><ssl_approval_code>916299</ssl_approval_code></txn>',
                200
            ),
            'zoom.test/oauth/token' => Http::response(['access_token' => 'zoom-token'], 200),
            'api.zoom.test/v2/users/me/meetings' => Http::response([
                'id' => 'questionnaire-zoom-meeting',
                'join_url' => 'https://zoom.test/j/questionnaire-zoom-meeting',
                'start_url' => 'https://zoom.test/s/questionnaire-zoom-meeting',
            ], 201),
        ]);

        $type = ConsultationType::where('slug', 'socal-half-day-mediation')->firstOrFail();
        $consultationUuid = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'legal_service_name' => 'Business, Payment & Contract Disputes',
            'consultation_mode' => 'online',
            'primary_client' => [
                'first_name' => 'Confirm',
                'last_name' => 'Payer',
                'email' => 'confirm.payer@example.com',
            ],
        ])->json('data.uuid');

        $complete = $this->postJson("/api/v1/consultations/{$consultationUuid}/complete", [
            'starts_at' => '2026-10-27T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'payment_mode' => 'full',
            'payment_method' => 'card',
        ])
            ->assertOk()
            ->json('data');

        $paymentRequestId = $complete['payment_requests'][0]['id'];

        $this->postJson('/api/v1/payments/converge/confirmation', [
            'ssl_invoice_number' => $paymentRequestId,
            'ssl_result' => '0',
            'ssl_result_message' => 'APPROVAL',
            'ssl_txn_id' => 'converge-txn-100',
            'ssl_approval_code' => '916299',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Payment verification completed.')
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.zoom_join_url', null)
            ->assertJsonPath('data.agreement_agreed', false)
            ->assertJsonMissingPath('data.questionnaire_progress');

        $this->assertDatabaseHas('payment_requests', [
            'id' => $paymentRequestId,
            'status' => 'paid',
            'transaction_id' => 'converge-txn-100',
        ]);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => PaymentRequest::class,
            'loggable_id' => $paymentRequestId,
            'provider' => 'converge',
            'action' => 'payment_confirmation',
            'status' => 'paid',
        ]);
        Mail::assertSent(ConsultationQuestionnaireMail::class);
        Mail::assertNotSent(ConsultationZoomLinkMail::class);

        $submissions = QuestionnaireSubmission::where('consultation_id', $consultationUuid)->get();
        $this->assertGreaterThan(0, $submissions->count());
        $this->assertSame('socal_party_mediation', $submissions->first()->template_key);
        $questionnaireMailHtml = (new ConsultationQuestionnaireMail($submissions->first()))->render();
        $this->assertStringContainsString('/party-mediation?token='.$submissions->first()->token, $questionnaireMailHtml);
        $this->assertStringContainsString('/agreement?token='.$submissions->first()->token, $questionnaireMailHtml);

        foreach ($submissions as $index => $submission) {
            $this->postJson('/api/v1/agreements/'.$submission->token, [
                'accepted' => true,
            ])->assertOk();

            $response = $this->postJson('/api/v1/questionnaires/socal-party-mediation/'.$submission->token, [
                'answers' => [
                    'dispute_summary' => 'Contract payment dispute.',
                    'desired_result' => 'A practical settlement.',
                ],
            ])->assertOk();

            if ($index < $submissions->count() - 1) {
                $response
                    ->assertJsonPath('data.status', 'paid')
                    ->assertJsonPath('data.zoom_join_url', null);
            } else {
                $response
                    ->assertJsonPath('data.status', 'scheduled')
                    ->assertJsonPath('data.payment_status', 'paid')
                    ->assertJsonPath('data.agreement_agreed', true)
                    ->assertJsonMissingPath('data.questionnaire_progress')
                    ->assertJsonPath('data.zoom_join_url', 'https://zoom.test/j/questionnaire-zoom-meeting');
            }
        }

        Mail::assertSent(ConsultationZoomLinkMail::class, fn (ConsultationZoomLinkMail $mail) => $mail->isReschedule
            && $mail->envelope()->subject === 'Your rescheduled consultation Zoom meeting link'
            && str_contains($mail->render(), 'Consultation Rescheduled')
            && str_contains($mail->render(), 'updated Zoom meeting link'));
        $this->assertDatabaseHas('questionnaire_submissions', [
            'id' => $submissions->first()->id,
            'status' => 'submitted',
            'agreement_accepted' => true,
        ]);
    }

    public function test_questionnaire_api_selects_divorce_template_and_requires_socal_agreement(): void
    {
        $this->seed();
        Mail::fake();
        config([
            'services.outlook.enabled' => false,
            'app.payment_redirect_urls.socal' => 'https://payment-result.example.test',
        ]);

        $consultation = Consultation::where('application', 'socal')->firstOrFail();
        $consultation->update([
            'status' => 'paid',
            'payment_status' => 'paid',
            'legal_service_name' => 'Divorce & Family Matters',
            'total_amount_cents' => 260000,
        ]);
        $participant = $consultation->participants()->firstOrFail();
        $submission = app(QuestionnaireWorkflowService::class)->ensureSubmission($consultation, $participant);

        $this->getJson('/api/v1/questionnaires/'.$submission->token)
            ->assertNotFound();

        $this->getJson('/api/v1/questionnaires/socal-party-mediation/'.$submission->token)
            ->assertStatus(409);

        $this->getJson('/api/v1/questionnaires/socal-divorce-intake/'.$submission->token)
            ->assertOk()
            ->assertJsonPath('data.questionnaire_completed', false)
            ->assertJsonPath('data.agreement_agreed', false)
            ->assertJsonPath('data.submitted_at', null);

        $this->postJson('/api/v1/questionnaires/socal-party-mediation/'.$submission->token, [
            'answers' => ['full_name' => 'Wrong endpoint for this token.'],
        ])->assertStatus(409);

        $this->postJson('/api/v1/questionnaires/socal-divorce-intake/'.$submission->token, [
            'answers' => ['full_name' => 'Alex Morgan'],
        ])
            ->assertOk()
            ->assertJsonPath('data.agreement_agreed', false)
            ->assertJsonMissingPath('data.questionnaire_progress');

        $this->getJson('/api/v1/questionnaires/socal-divorce-intake/'.$submission->token)
            ->assertOk()
            ->assertJsonPath('data.questionnaire_completed', true)
            ->assertJsonPath('data.agreement_agreed', false)
            ->assertJsonPath('data.submitted_at', fn ($value) => is_string($value));

        $this->getJson('/api/v1/agreements/'.$submission->token)
            ->assertOk()
            ->assertJsonPath('data.required', true)
            ->assertJsonPath('data.accepted', false)
            ->assertJsonPath('data.agreement_agreed', false)
            ->assertJsonPath('data.questionnaire_completed', true)
            ->assertJsonMissingPath('data.consultation.questionnaire_completed');

        $this->postJson('/api/v1/agreements/'.$submission->token, [
            'accepted' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.agreement_agreed', true)
            ->assertJsonPath('data.questionnaire_completed', true)
            ->assertJsonMissingPath('data.consultation.questionnaire_completed')
            ->assertJsonMissingPath('data.questionnaire_progress');

        $this->assertDatabaseHas('questionnaire_submissions', [
            'id' => $submission->id,
            'template_key' => 'socal_divorce_intake',
            'status' => 'submitted',
            'agreement_accepted' => true,
        ]);
    }

    public function test_disabled_converge_gateway_does_not_create_a_payment_link(): void
    {
        $this->seed();
        Mail::fake();
        config(['services.converge.enabled' => false]);

        $type = ConsultationType::where('slug', 'legal-professional-consultation')->firstOrFail();
        $consultationUuid = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'consultation_mode' => 'phone',
            'primary_client' => [
                'first_name' => 'Demo',
                'last_name' => 'Payer',
                'email' => 'demo.payer@example.com',
            ],
        ])->assertCreated()->json('data.uuid');

        $complete = $this->postJson('/api/v1/consultations/'.$consultationUuid.'/complete', [
            'starts_at' => '2026-08-14T13:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'consultation_mode' => 'phone',
            'payment_mode' => 'full',
            'payment_method' => 'card',
        ])->assertOk()->json('data');

        $paymentRequest = PaymentRequest::findOrFail($complete['payment_requests'][0]['id']);

        $this->assertNull($paymentRequest->payment_url);
        $this->assertSame('pending', $paymentRequest->status);
        Mail::assertNotSent(ConsultationPaymentLinkMail::class);
    }

    public function test_simulated_payment_endpoint_enforces_environment_flags_and_api_key(): void
    {
        $this->seed();
        $payment = PaymentRequest::where('status', 'pending')->firstOrFail();
        $endpoint = '/api/v1/testing/payments/'.$payment->id.'/complete';

        config([
            'services.payment_simulation.enabled' => false,
            'services.payment_simulation.key' => 'simulation-secret',
            'services.converge.enabled' => false,
        ]);

        $this->postJson($endpoint, [], ['X-Payment-Simulation-Key' => 'simulation-secret'])
            ->assertNotFound();

        config([
            'services.payment_simulation.enabled' => true,
            'app.env' => 'production',
        ]);

        $this->postJson($endpoint, [], ['X-Payment-Simulation-Key' => 'simulation-secret'])
            ->assertNotFound();

        config(['app.env' => 'testing']);

        $this->postJson($endpoint)->assertForbidden();
        $this->postJson($endpoint, [], ['X-Payment-Simulation-Key' => 'wrong-secret'])
            ->assertForbidden();

        config(['services.converge.enabled' => true]);

        $this->postJson($endpoint, [], ['X-Payment-Simulation-Key' => 'simulation-secret'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Payment simulation is unavailable while Converge is enabled.');

        config(['services.converge.enabled' => false]);

        $this->postJson('/api/v1/testing/payments/'.Str::uuid().'/complete', [], [
            'X-Payment-Simulation-Key' => 'simulation-secret',
        ])->assertNotFound();
    }

    public function test_simulated_split_payments_finalize_zoom_and_outlook_only_after_last_share(): void
    {
        $this->seed();
        Mail::fake();
        config([
            'services.payment_simulation.enabled' => true,
            'services.payment_simulation.key' => 'simulation-secret',
            'services.converge.enabled' => false,
            'services.converge.payment_sync_enabled' => true,
            'services.outlook.enabled' => true,
            'services.outlook.tenant_id' => 'tenant-id',
            'services.outlook.client_id' => 'client-id',
            'services.outlook.client_secret' => 'client-secret',
            'services.outlook.login_base_url' => 'https://login.microsoftonline.com',
            'services.outlook.base_url' => 'https://graph.microsoft.com/v1.0',
            'services.outlook.socal_user_id' => 'socal@example.com',
            'services.outlook.socal_calendar_id' => 'socal-calendar',
            'services.outlook.legal_user_id' => 'legal@example.com',
            'services.outlook.legal_calendar_id' => 'legal-calendar',
        ]);

        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response(['access_token' => 'graph-token'], 200),
            'graph.microsoft.com/v1.0/users/socal%40example.com/calendars/socal-calendar/events' => Http::response([
                'id' => 'simulated-payment-socal-event-id',
                'webLink' => 'https://outlook.office.com/simulated-payment-socal-event',
                'subject' => 'Simulated paid booking',
            ], 201),
            'graph.microsoft.com/v1.0/users/legal%40example.com/calendars/legal-calendar/events' => Http::response([
                'id' => 'simulated-payment-legal-event-id',
                'webLink' => 'https://outlook.office.com/simulated-payment-legal-event',
                'subject' => 'Simulated paid booking',
            ], 201),
            'graph.microsoft.com/v1.0/users/socal%40example.com/calendars/socal-calendar/events/simulated-payment-socal-event-id' => Http::response([
                'id' => 'simulated-payment-socal-event-id',
                'webLink' => 'https://outlook.office.com/simulated-payment-socal-event',
            ]),
            'graph.microsoft.com/v1.0/users/legal%40example.com/calendars/legal-calendar/events/simulated-payment-legal-event-id' => Http::response([
                'id' => 'simulated-payment-legal-event-id',
                'webLink' => 'https://outlook.office.com/simulated-payment-legal-event',
            ]),
        ]);

        $type = ConsultationType::where('slug', 'socal-half-day-mediation')->firstOrFail();
        $consultationUuid = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'legal_service_name' => 'Business, Payment & Contract Disputes',
            'consultation_mode' => 'online',
            'primary_client' => [
                'first_name' => 'Simulation',
                'last_name' => 'Primary',
                'email' => 'simulation.primary@example.com',
            ],
            'participants' => [[
                'first_name' => 'Simulation',
                'last_name' => 'Participant',
                'email' => 'simulation.participant@example.com',
            ]],
        ])->assertCreated()->json('data.uuid');

        $complete = $this->postJson('/api/v1/consultations/'.$consultationUuid.'/complete', [
            'starts_at' => '2026-08-17T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'payment_mode' => 'split',
            'payment_method' => 'card',
        ])->assertOk()->json('data');

        [$firstPayment, $finalPayment] = $complete['payment_requests'];

        $this->assertSame('simulation', $firstPayment['provider']);
        $this->assertNull($firstPayment['payment_url']);
        Mail::assertNotSent(ConsultationPaymentLinkMail::class);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultationUuid,
            'provider' => 'simulation',
            'action' => 'automatic_payment_link',
            'status' => 'skipped',
        ]);

        $headers = ['X-Payment-Simulation-Key' => 'simulation-secret'];

        $this->postJson('/api/v1/testing/payments/'.$firstPayment['id'].'/complete', [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'payment_pending')
            ->assertJsonPath('data.payment_status', 'partially_paid')
            ->assertJsonPath('data.payment_progress.paid', 1)
            ->assertJsonPath('data.zoom_join_url', null);

        Mail::assertNotSent(ConsultationZoomLinkMail::class);

        $this->postJson('/api/v1/testing/payments/'.$finalPayment['id'].'/complete', [], $headers)
            ->assertOk()
            ->assertJsonPath('message', 'Simulated payment completed.')
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_progress.paid', 2)
            ->assertJson(fn ($json) => $json->whereType('data.zoom_join_url', 'string')->etc());

        Mail::assertSent(ConsultationZoomLinkMail::class, 2);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => PaymentRequest::class,
            'loggable_id' => $finalPayment['id'],
            'provider' => 'simulation',
            'action' => 'payment_confirmation',
            'status' => 'paid',
        ]);
        foreach (['socal', 'legal'] as $application) {
            $this->assertDatabaseHas('external_calendar_events', [
                'provider' => 'outlook',
                'external_id' => 'consultation-'.$consultationUuid.'-'.$application,
                'application' => $application,
                'is_busy' => true,
            ]);
        }

        $this->postJson('/api/v1/testing/payments/'.$finalPayment['id'].'/complete', [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');

        Mail::assertSent(ConsultationZoomLinkMail::class, 2);
        $this->assertSame(1, PaymentRequest::findOrFail($finalPayment['id'])->integrationLogs()
            ->where('provider', 'simulation')
            ->where('action', 'payment_confirmation')
            ->count());
        $this->assertSame(1, Consultation::findOrFail($consultationUuid)->integrationLogs()
            ->where('action', 'automatic_payment_sync')
            ->where('status', 'synced')
            ->count());
    }

    public function test_simulated_payment_rejects_cancelled_and_draft_consultations(): void
    {
        $this->seed();
        config([
            'services.payment_simulation.enabled' => true,
            'services.payment_simulation.key' => 'simulation-secret',
            'services.converge.enabled' => false,
        ]);

        $payment = PaymentRequest::where('status', 'pending')->firstOrFail();
        $consultation = $payment->consultation;
        $endpoint = '/api/v1/testing/payments/'.$payment->id.'/complete';
        $headers = ['X-Payment-Simulation-Key' => 'simulation-secret'];

        $consultation->update(['status' => 'cancelled']);
        $this->postJson($endpoint, [], $headers)->assertStatus(409);

        $consultation->update(['status' => 'draft']);
        $this->postJson($endpoint, [], $headers)->assertStatus(409);

        $this->assertDatabaseHas('payment_requests', [
            'id' => $payment->id,
            'status' => 'pending',
        ]);
    }

    public function test_single_simulated_payment_completes_a_full_payment_consultation(): void
    {
        $this->seed();
        Mail::fake();
        config([
            'services.payment_simulation.enabled' => true,
            'services.payment_simulation.key' => 'simulation-secret',
            'services.converge.enabled' => false,
            'services.outlook.enabled' => false,
        ]);

        $type = ConsultationType::where('slug', 'legal-professional-consultation')->firstOrFail();
        $consultationUuid = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'consultation_mode' => 'phone',
            'primary_client' => [
                'first_name' => 'Full',
                'last_name' => 'Simulation',
                'email' => 'full.simulation@example.com',
            ],
        ])->assertCreated()->json('data.uuid');

        $complete = $this->postJson('/api/v1/consultations/'.$consultationUuid.'/complete', [
            'starts_at' => '2026-08-18T13:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'payment_mode' => 'full',
            'payment_method' => 'card',
        ])->assertOk()->json('data');

        $this->assertSame(1, $complete['payment_progress']['total']);
        $paymentRequestId = $complete['payment_requests'][0]['id'];

        $this->postJson('/api/v1/testing/payments/'.$paymentRequestId.'/complete', [], [
            'X-Payment-Simulation-Key' => 'simulation-secret',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_progress.paid', 1);

        $this->assertDatabaseHas('payment_requests', [
            'id' => $paymentRequestId,
            'provider' => 'simulation',
            'status' => 'paid',
        ]);
        Mail::assertNotSent(ConsultationPaymentLinkMail::class);
    }

    public function test_enabled_converge_gateway_creates_fresh_hosted_payment_session_on_signed_checkout(): void
    {
        $this->seed();
        Mail::fake();
        config([
            'services.converge.enabled' => true,
            'services.converge.mode' => 'sandbox',
            'services.converge.sandbox_base_url' => 'https://api.demo.convergepay.com',
            'services.converge.account_id' => 'account-id',
            'services.converge.user_id' => 'api-user',
            'services.converge.pin' => 'secret-pin',
            'services.converge.return_url' => 'http://localhost/api/v1/payments/converge/return',
        ]);

        Http::fake([
            'api.demo.convergepay.com/hosted-payments/transaction_token' => Http::response('session-token-123', 200),
        ]);

        $type = ConsultationType::where('slug', 'legal-professional-consultation')->firstOrFail();
        $consultationUuid = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'consultation_mode' => 'phone',
            'primary_client' => [
                'first_name' => 'Hosted',
                'last_name' => 'Payer',
                'email' => 'hosted.payer@example.com',
            ],
        ])->assertCreated()->json('data.uuid');

        $complete = $this->postJson('/api/v1/consultations/'.$consultationUuid.'/complete', [
            'starts_at' => '2026-08-14T13:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'consultation_mode' => 'phone',
            'payment_mode' => 'full',
            'payment_method' => 'card',
        ])->assertOk()->json('data');

        $paymentRequest = PaymentRequest::findOrFail($complete['payment_requests'][0]['id']);

        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.demo.convergepay.com/hosted-payments/transaction_token');
        $this->assertStringContainsString('/payments/'.$paymentRequest->id.'/checkout?', $paymentRequest->payment_url);
        $this->assertNull($paymentRequest->metadata['session_token']);

        $this->get($paymentRequest->payment_url)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('action="https://api.demo.convergepay.com/hosted-payments/"', false)
            ->assertSee('method="post"', false)
            ->assertSee('name="ssl_txn_auth_token" value="session-token-123"', false);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.demo.convergepay.com/hosted-payments/transaction_token'
            && $request['ssl_transaction_type'] === 'ccsale'
            && $request['ssl_amount'] === '195.00'
            && $request['ssl_invoice_number'] === $paymentRequest->provider_reference
            && strlen($request['ssl_invoice_number']) <= 25
            && strlen($request['ssl_customer_code']) <= 17
            && $request['ssl_customer_code'] === $paymentRequest->consultation->booking_number);

        $tamperedUrl = str_replace('signature=', 'signature=invalid', $paymentRequest->payment_url);
        $this->get($tamperedUrl)->assertForbidden();
    }

    public function test_converge_session_creation_error_shows_retry_button(): void
    {
        $this->seed();
        config([
            'services.converge.enabled' => true,
            'services.converge.mode' => 'sandbox',
            'services.converge.sandbox_base_url' => 'https://api.demo.convergepay.com',
            'services.converge.account_id' => 'account-id',
            'services.converge.user_id' => 'api-user',
            'services.converge.pin' => 'secret-pin',
            'services.converge.return_url' => 'http://localhost/api/v1/payments/converge/return',
        ]);

        Http::fake([
            'api.demo.convergepay.com/hosted-payments/transaction_token' => Http::response('Gateway unavailable', 503),
        ]);

        $payment = Consultation::where('booking_number', 'SAMPLE-03')
            ->firstOrFail()
            ->paymentRequests()
            ->where('status', 'pending')
            ->firstOrFail();
        $checkoutUrl = URL::signedRoute('payments.checkout', ['paymentRequest' => $payment]);
        $payment->update(['payment_url' => $checkoutUrl]);

        $response = $this->get($checkoutUrl)
            ->assertOk()
            ->assertSee('We could not start the secure payment session. Please try again.')
            ->assertSee('Try Payment Again')
            ->assertSee('href="'.$checkoutUrl.'"', false)
            ->assertSee('background:#082BC3', false);

        $failureLog = $payment->integrationLogs()
            ->where('action', 'hosted_payment_session')
            ->where('status', 'failed')
            ->latest()
            ->firstOrFail();

        $response->assertSee('Support reference:')->assertSee('PAY-'.$failureLog->id);
    }

    public function test_forged_confirmation_cannot_mark_payment_paid_without_converge_verification(): void
    {
        $this->seed();
        config([
            'services.converge.mode' => 'sandbox',
            'services.converge.sandbox_base_url' => 'https://api.demo.convergepay.com',
            'services.converge.account_id' => 'account-id',
            'services.converge.user_id' => 'api-user',
            'services.converge.pin' => 'secret-pin',
        ]);
        Http::fake([
            'api.demo.convergepay.com/VirtualMerchantDemo/processxml.do' => Http::response(
                '<txn><ssl_txn_count>0</ssl_txn_count></txn>',
                200
            ),
        ]);

        $payment = PaymentRequest::where('status', 'pending')->firstOrFail();

        $this->postJson('/api/v1/payments/converge/confirmation', [
            'ssl_invoice_number' => $payment->id,
            'ssl_result' => '0',
            'ssl_result_message' => 'APPROVAL',
            'ssl_txn_id' => 'forged-transaction',
            'ssl_card_number' => '4000000000000002',
        ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', $payment->consultation->payment_status);

        $this->assertDatabaseHas('payment_requests', [
            'id' => $payment->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => PaymentRequest::class,
            'loggable_id' => $payment->id,
            'provider' => 'converge',
            'action' => 'payment_confirmation',
            'status' => 'skipped',
        ]);
        $log = $payment->integrationLogs()
            ->where('action', 'payment_confirmation')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('[FILTERED]', $log->request_payload['ssl_card_number']);
    }

    public function test_converge_return_processes_approved_payload_and_redirects_to_success(): void
    {
        $this->seed();
        Mail::fake();
        config([
            'services.converge.enabled' => true,
            'services.converge.mode' => 'sandbox',
            'services.converge.sandbox_base_url' => 'https://api.demo.convergepay.com',
            'services.converge.account_id' => 'account-id',
            'services.converge.user_id' => 'api-user',
            'services.converge.pin' => 'secret-pin',
            'services.converge.return_url' => 'http://localhost/api/v1/payments/converge/return',
        ]);
        $type = ConsultationType::where('slug', 'legal-professional-consultation')->firstOrFail();
        $consultationUuid = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'consultation_mode' => 'phone',
            'primary_client' => [
                'first_name' => 'Return',
                'last_name' => 'Payer',
                'email' => 'return.payer@example.com',
            ],
        ])->assertCreated()->json('data.uuid');

        $complete = $this->postJson('/api/v1/consultations/'.$consultationUuid.'/complete', [
            'starts_at' => '2026-08-19T13:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'payment_mode' => 'full',
            'payment_method' => 'card',
        ])->assertOk()->json('data');

        $paymentRequestId = $complete['payment_requests'][0]['id'];
        $paymentRequest = PaymentRequest::findOrFail($paymentRequestId);
        $transactionId = 'return-verified-txn';
        $invoiceNumber = $paymentRequest->provider_reference;
        $amount = number_format($paymentRequest->amount_cents / 100, 2, '.', '');

        Http::fake([
            'api.demo.convergepay.com/VirtualMerchantDemo/processxml.do' => Http::response(
                '<txn><ssl_txn_id>'.$transactionId.'</ssl_txn_id><ssl_trans_status>OPN</ssl_trans_status><ssl_transaction_type>SALE</ssl_transaction_type><ssl_amount>'.$amount.'</ssl_amount><ssl_invoice_number>'.$invoiceNumber.'</ssl_invoice_number><ssl_result_message>APPROVAL</ssl_result_message><ssl_approval_code>026077</ssl_approval_code></txn>',
                200
            ),
        ]);

        $response = $this->post(route('payments.converge.return.web'), [
            'ssl_last_name' => 'Payer',
            'ssl_approval_code' => '026077',
            'ssl_customer_code' => $paymentRequest->consultation->booking_number,
            'ssl_email' => $paymentRequest->participant->email,
            'ssl_amount' => $amount,
            'ssl_description' => $paymentRequest->consultation->booking_number.' - Consultation',
            'ssl_exp_date' => '1230',
            'ssl_card_short_description' => 'VISA',
            'ssl_first_name' => 'Return',
            'ssl_invoice_number' => $invoiceNumber,
            'ssl_txn_id' => $transactionId,
            'ssl_transaction_type' => 'SALE',
            'ssl_result' => '0',
            'ssl_result_message' => 'APPROVAL',
            'ssl_card_number' => '40**********0002',
            'ssl_cvv2_response' => 'M',
            'ssl_txn_time' => '08/06/2026 08:56:46 AM',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/payments/'.$paymentRequestId.'/status/success', $response->headers->get('Location'));

        $statusUrl = $response->headers->get('Location');
        $requestsBeforeStatusPage = Http::recorded()->count();
        $this->get($statusUrl)
            ->assertOk()
            ->assertSee('Payment Successful')
            ->assertSee('Your payment was completed successfully.');
        $this->get($statusUrl)->assertOk();
        $this->assertCount($requestsBeforeStatusPage, Http::recorded());

        $this->assertDatabaseHas('payment_requests', [
            'id' => $paymentRequestId,
            'status' => 'paid',
            'transaction_id' => $transactionId,
            'approval_code' => '026077',
        ]);
        $this->assertSame($transactionId, PaymentRequest::findOrFail($paymentRequestId)->metadata['converge_transaction_id']);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => PaymentRequest::class,
            'loggable_id' => $paymentRequestId,
            'provider' => 'converge',
            'action' => 'payment_return',
            'status' => 'paid',
        ]);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'processxml.do'));
    }

    public function test_converge_path_return_processes_approved_response_without_xml_lookup(): void
    {
        $this->seed();
        config([
            'services.converge.mode' => 'sandbox',
            'services.converge.sandbox_base_url' => 'https://api.demo.convergepay.com',
            'services.converge.account_id' => 'account-id',
            'services.converge.user_id' => 'api-user',
            'services.converge.pin' => 'secret-pin',
        ]);

        Http::fake([
            'api.demo.convergepay.com/VirtualMerchantDemo/processxml.do' => Http::response(
                '<txn><ssl_result>0</ssl_result><ssl_result_message>APPROVAL</ssl_result_message><ssl_txn_id>browser-approved-txn</ssl_txn_id><ssl_approval_code>654321</ssl_approval_code></txn>',
                200
            ),
        ]);

        $payment = PaymentRequest::where('status', 'pending')->firstOrFail();

        $response = $this->post(route('payments.converge.return.payment', ['paymentRequest' => $payment]), [
            'ssl_result' => '0',
            'ssl_result_message' => 'APPROVAL',
            'ssl_txn_id' => 'browser-approved-txn',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/payments/'.$payment->id.'/status/success', $response->headers->get('Location'));
        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('Payment Successful');

        $this->assertDatabaseHas('payment_requests', [
            'id' => $payment->id,
            'status' => 'paid',
            'transaction_id' => 'browser-approved-txn',
        ]);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'processxml.do'));
    }

    public function test_converge_post_return_redirects_a_declined_payment_to_failed_status(): void
    {
        $this->seed();
        config([
            'services.converge.mode' => 'sandbox',
            'services.converge.sandbox_base_url' => 'https://api.demo.convergepay.com',
            'services.converge.account_id' => 'account-id',
            'services.converge.user_id' => 'api-user',
            'services.converge.pin' => 'secret-pin',
        ]);

        Http::fake([
            'api.demo.convergepay.com/VirtualMerchantDemo/processxml.do' => Http::response(
                '<txn><ssl_result>1</ssl_result><ssl_result_message>DECLINED</ssl_result_message><ssl_txn_id>declined-return-txn</ssl_txn_id></txn>',
                200
            ),
        ]);

        $payment = PaymentRequest::where('status', 'pending')->firstOrFail();
        $response = $this->post(route('payments.converge.return.payment', ['paymentRequest' => $payment]), [
            'ssl_result' => '1',
            'ssl_result_message' => 'DECLINED',
            'ssl_txn_id' => 'declined-return-txn',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/payments/'.$payment->id.'/status/failed', $response->headers->get('Location'));
        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('The payment was not approved.')
            ->assertSee('Try Payment Again');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'processxml.do'));
    }

    public function test_converge_webhook_resolves_short_invoice_and_verifies_transaction(): void
    {
        $this->seed();
        config([
            'services.converge.mode' => 'sandbox',
            'services.converge.sandbox_base_url' => 'https://api.demo.convergepay.com',
            'services.converge.account_id' => 'account-id',
            'services.converge.user_id' => 'api-user',
            'services.converge.pin' => 'secret-pin',
        ]);

        Http::fake([
            'api.demo.convergepay.com/VirtualMerchantDemo/processxml.do' => Http::response(
                '<txn><ssl_result>0</ssl_result><ssl_result_message>APPROVAL</ssl_result_message><ssl_txn_id>export-script-txn</ssl_txn_id></txn>',
                200
            ),
        ]);

        $payment = PaymentRequest::where('status', 'pending')->firstOrFail();

        $this->post('/api/v1/payments/converge/webhook', [
            'ssl_invoice_number' => $payment->provider_reference,
            'ssl_txn_id' => 'export-script-txn',
            'ssl_result' => '0',
            'ssl_result_message' => 'APPROVAL',
        ])->assertOk();

        $this->assertDatabaseHas('payment_requests', [
            'id' => $payment->id,
            'status' => 'paid',
            'transaction_id' => 'export-script-txn',
        ]);

        Http::assertSent(fn ($request) => str_contains((string) $request->body(), '%3Cssl_txn_id%3Eexport-script-txn%3C%2Fssl_txn_id%3E'));
    }

    public function test_converge_payment_sync_command_updates_pending_payment_from_xml_api(): void
    {
        $this->seed();
        config([
            'services.converge.payment_sync_enabled' => true,
            'services.converge.mode' => 'sandbox',
            'services.converge.sandbox_base_url' => 'https://api.demo.convergepay.com',
            'services.converge.account_id' => 'account-id',
            'services.converge.user_id' => 'api-user',
            'services.converge.pin' => 'secret-pin',
        ]);

        $type = ConsultationType::where('slug', 'socal-half-day-mediation')->firstOrFail();
        $consultationUuid = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
            'legal_service_name' => 'Business, Payment & Contract Disputes',
            'consultation_mode' => 'offline',
            'primary_client' => [
                'first_name' => 'Poll',
                'last_name' => 'Payer',
                'email' => 'poll.payer@example.com',
            ],
        ])->json('data.uuid');

        $complete = $this->postJson("/api/v1/consultations/{$consultationUuid}/complete", [
            'starts_at' => '2026-08-10T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'payment_mode' => 'full',
            'payment_method' => 'card',
        ])->json('data');

        $paymentRequestId = $complete['payment_requests'][0]['id'];
        $paymentRequest = PaymentRequest::findOrFail($paymentRequestId);
        $invoiceNumber = $paymentRequest->provider_reference;
        $convergeAmount = number_format($paymentRequest->amount_cents / 100, 2, '.', '');
        Http::fake([
            'api.demo.convergepay.com/VirtualMerchantDemo/processxml.do' => Http::response(
                '<txn><ssl_txn_count>1</ssl_txn_count><transactions><transaction><ssl_result>0</ssl_result><ssl_result_message>APPROVAL</ssl_result_message><ssl_txn_id>poll-txn-200</ssl_txn_id><ssl_approval_code>123456</ssl_approval_code><ssl_amount>'.$convergeAmount.'</ssl_amount></transaction></transactions></txn>',
                200
            ),
        ]);

        $this->artisan('payments:sync-converge', ['--payment-request' => $paymentRequestId])
            ->expectsOutputToContain('checked 1 payment(s): 1 paid, 0 failed, 0 skipped, 0 error(s).')
            ->assertExitCode(0);

        $this->assertDatabaseHas('payment_requests', [
            'id' => $paymentRequestId,
            'status' => 'paid',
            'transaction_id' => 'poll-txn-200',
            'approval_code' => '123456',
        ]);
        $this->assertDatabaseHas('consultations', [
            'id' => $consultationUuid,
            'payment_status' => 'paid',
        ]);

        Http::assertSent(fn ($request) => str_contains((string) $request->body(), '%3Cssl_invoice_number%3E'.$invoiceNumber.'%3C%2Fssl_invoice_number%3E'));
    }

    public function test_converge_payment_sync_command_logs_errors_and_leaves_payment_pending(): void
    {
        $this->seed();
        config([
            'services.converge.payment_sync_enabled' => true,
            'services.converge.mode' => 'sandbox',
            'services.converge.sandbox_base_url' => 'https://api.demo.convergepay.com',
            'services.converge.account_id' => 'account-id',
            'services.converge.user_id' => 'api-user',
            'services.converge.pin' => 'secret-pin',
        ]);

        $payment = PaymentRequest::where('status', 'pending')->firstOrFail();

        Http::fake([
            'api.demo.convergepay.com/VirtualMerchantDemo/processxml.do' => Http::response('temporarily unavailable', 503),
        ]);

        $this->artisan('payments:sync-converge', ['--payment-request' => $payment->id])
            ->expectsOutputToContain('checked 1 payment(s): 0 paid, 0 failed, 0 skipped, 1 error(s).')
            ->assertExitCode(0);

        $this->assertDatabaseHas('payment_requests', [
            'id' => $payment->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => PaymentRequest::class,
            'loggable_id' => $payment->id,
            'provider' => 'converge',
            'action' => 'payment_status_sync',
            'status' => 'failed',
        ]);
    }

    public function test_converge_return_saves_transaction_id_and_redirects_pending_without_a_result(): void
    {
        $this->seed();
        config([
            'services.converge.payment_sync_enabled' => true,
            'services.converge.mode' => 'sandbox',
            'services.converge.sandbox_base_url' => 'https://api.demo.convergepay.com',
            'services.converge.account_id' => 'account-id',
            'services.converge.user_id' => 'api-user',
            'services.converge.pin' => 'secret-pin',
        ]);

        $payment = PaymentRequest::where('status', 'pending')->firstOrFail();

        Http::fake([
            'api.demo.convergepay.com/VirtualMerchantDemo/processxml.do' => Http::response('temporarily unavailable', 503),
        ]);

        $response = $this->post(route('payments.converge.return.payment', ['paymentRequest' => $payment]), [
            'ssl_txn_id' => 'stored-return-txn',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/payments/'.$payment->id.'/status/pending', $response->headers->get('Location'));
        $pendingUrl = $response->headers->get('Location');
        $this->get($pendingUrl)
            ->assertOk()
            ->assertSee('We are still verifying your payment.');
        $this->get(str_replace('/status/pending', '/status/success', $pendingUrl))->assertForbidden();

        $payment->refresh();
        $this->assertSame('stored-return-txn', $payment->metadata['converge_transaction_id']);
        $this->assertContains('stored-return-txn', $payment->metadata['converge_transaction_ids']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'processxml.do'));
    }

    public function test_converge_cron_verifies_a_pending_return_in_the_background(): void
    {
        $this->seed();
        config([
            'services.converge.payment_sync_enabled' => true,
            'services.converge.mode' => 'sandbox',
            'services.converge.sandbox_base_url' => 'https://api.demo.convergepay.com',
            'services.converge.account_id' => 'account-id',
            'services.converge.user_id' => 'api-user',
            'services.converge.pin' => 'secret-pin',
        ]);

        $payment = PaymentRequest::where('status', 'pending')->firstOrFail();
        $amount = number_format($payment->amount_cents / 100, 2, '.', '');
        Http::fake([
            'api.demo.convergepay.com/VirtualMerchantDemo/processxml.do' => Http::response(
                '<txn><ssl_txn_id>background-audit-txn</ssl_txn_id><ssl_transaction_type>SALE</ssl_transaction_type><ssl_amount>'.$amount.'</ssl_amount><ssl_result_message>APPROVAL</ssl_result_message><ssl_approval_code>654321</ssl_approval_code></txn>',
                200
            ),
        ]);

        $this->post(route('payments.converge.return.payment', ['paymentRequest' => $payment]), [
            'ssl_txn_id' => 'background-audit-txn',
            'ssl_amount' => $amount,
        ])->assertRedirect();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'processxml.do'));
        $this->assertDatabaseHas('payment_requests', [
            'id' => $payment->id,
            'status' => 'pending',
            'transaction_id' => 'background-audit-txn',
        ]);

        $this->artisan('payments:sync-converge', ['--payment-request' => $payment->id])
            ->expectsOutputToContain('checked 1 payment(s): 1 paid, 0 failed, 0 skipped, 0 error(s).')
            ->assertExitCode(0);

        $this->assertDatabaseHas('payment_requests', [
            'id' => $payment->id,
            'status' => 'paid',
            'transaction_id' => 'background-audit-txn',
            'approval_code' => '654321',
        ]);
        $this->assertNotNull(PaymentRequest::findOrFail($payment->id)->last_status_check_at);
        $this->assertNotNull(PaymentRequest::findOrFail($payment->id)->txnquery_response);
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), '%3Cssl_txn_id%3Ebackground-audit-txn%3C%2Fssl_txn_id%3E'));

        $this->artisan('payments:sync-converge', ['--payment-request' => $payment->id])
            ->expectsOutputToContain('checked 0 payment(s)')
            ->assertExitCode(0);
        $this->assertCount(1, Http::recorded()->filter(fn ($entry) => str_contains($entry[0]->url(), 'processxml.do')));
    }

    public function test_converge_cron_marks_a_pending_decline_failed_and_stops_checking(): void
    {
        $this->seed();
        config([
            'services.converge.payment_sync_enabled' => true,
            'services.converge.mode' => 'sandbox',
            'services.converge.sandbox_base_url' => 'https://api.demo.convergepay.com',
            'services.converge.account_id' => 'account-id',
            'services.converge.user_id' => 'api-user',
            'services.converge.pin' => 'secret-pin',
        ]);

        $payment = PaymentRequest::where('status', 'pending')->firstOrFail();
        $payment->update(['transaction_id' => 'background-declined-txn']);
        Http::fake([
            'api.demo.convergepay.com/VirtualMerchantDemo/processxml.do' => Http::response(
                '<txn><ssl_txn_id>background-declined-txn</ssl_txn_id><ssl_transaction_type>SALE</ssl_transaction_type><ssl_result_message>DECLINED</ssl_result_message></txn>',
                200
            ),
        ]);

        $this->artisan('payments:sync-converge', ['--payment-request' => $payment->id])
            ->expectsOutputToContain('checked 1 payment(s): 0 paid, 1 failed, 0 skipped, 0 error(s).')
            ->assertExitCode(0);

        $this->assertDatabaseHas('payment_requests', [
            'id' => $payment->id,
            'status' => 'failed',
            'transaction_id' => 'background-declined-txn',
        ]);
        $payment->refresh();
        $this->assertNotNull($payment->last_status_check_at);
        $this->assertNotNull($payment->txnquery_response);

        $this->artisan('payments:sync-converge', ['--payment-request' => $payment->id])
            ->expectsOutputToContain('checked 0 payment(s)')
            ->assertExitCode(0);
        $this->assertCount(1, Http::recorded()->filter(fn ($entry) => str_contains($entry[0]->url(), 'processxml.do')));
    }

    public function test_converge_lookup_uses_transaction_id_saved_from_an_earlier_return(): void
    {
        $this->seed();
        config([
            'services.converge.mode' => 'sandbox',
            'services.converge.sandbox_base_url' => 'https://api.demo.convergepay.com',
            'services.converge.account_id' => 'account-id',
            'services.converge.user_id' => 'api-user',
            'services.converge.pin' => 'secret-pin',
        ]);

        $payment = PaymentRequest::where('status', 'pending')->firstOrFail();
        $payment->update([
            'metadata' => array_merge($payment->metadata ?? [], [
                'converge_transaction_id' => 'stored-return-txn',
            ]),
        ]);

        Http::fake([
            'api.demo.convergepay.com/VirtualMerchantDemo/processxml.do' => Http::response(
                '<txn><ssl_result>0</ssl_result><ssl_result_message>APPROVAL</ssl_result_message><ssl_txn_id>stored-return-txn</ssl_txn_id><ssl_approval_code>123456</ssl_approval_code></txn>',
                200
            ),
        ]);

        $result = app(ConvergeClient::class)->lookupPaymentStatus($payment);

        $this->assertSame('paid', $result['status']);
        $this->assertSame('stored-return-txn', $result['transaction_id']);
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), '%3Cssl_txn_id%3Estored-return-txn%3C%2Fssl_txn_id%3E'));
    }

    public function test_final_paid_webhook_sends_zoom_links_and_syncs_outlook_once(): void
    {
        $this->seed();
        Mail::fake();
        config([
            'services.converge.mode' => 'sandbox',
            'services.converge.payment_sync_enabled' => false,
            'services.converge.sandbox_base_url' => 'https://api.demo.convergepay.com',
            'services.converge.account_id' => 'account-id',
            'services.converge.user_id' => 'api-user',
            'services.converge.pin' => 'secret-pin',
            'services.outlook.enabled' => true,
            'services.outlook.tenant_id' => 'tenant-id',
            'services.outlook.client_id' => 'client-id',
            'services.outlook.client_secret' => 'client-secret',
            'services.outlook.login_base_url' => 'https://login.microsoftonline.com',
            'services.outlook.base_url' => 'https://graph.microsoft.com/v1.0',
            'services.outlook.socal_user_id' => 'socal@example.com',
            'services.outlook.socal_calendar_id' => 'socal-calendar',
            'services.outlook.legal_user_id' => 'legal@example.com',
            'services.outlook.legal_calendar_id' => 'legal-calendar',
        ]);

        Http::fake([
            'api.demo.convergepay.com/VirtualMerchantDemo/processxml.do' => Http::sequence()
                ->push('<txn><ssl_result>0</ssl_result><ssl_result_message>APPROVAL</ssl_result_message><ssl_txn_id>split-first-txn</ssl_txn_id></txn>', 200)
                ->push('<txn><ssl_result>0</ssl_result><ssl_result_message>APPROVAL</ssl_result_message><ssl_txn_id>split-final-txn</ssl_txn_id></txn>', 200),
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response(['access_token' => 'graph-token'], 200),
            'graph.microsoft.com/v1.0/users/socal%40example.com/calendars/socal-calendar/events' => Http::response([
                'id' => 'automatic-paid-socal-outlook-event-id',
                'webLink' => 'https://outlook.office.com/automatic-paid-socal-event',
                'subject' => 'Synced booking',
            ], 201),
            'graph.microsoft.com/v1.0/users/legal%40example.com/calendars/legal-calendar/events' => Http::response([
                'id' => 'automatic-paid-legal-outlook-event-id',
                'webLink' => 'https://outlook.office.com/automatic-paid-legal-event',
                'subject' => 'Synced booking',
            ], 201),
            'graph.microsoft.com/v1.0/users/socal%40example.com/calendars/socal-calendar/events/automatic-paid-socal-outlook-event-id' => Http::response([
                'id' => 'automatic-paid-socal-outlook-event-id',
                'webLink' => 'https://outlook.office.com/automatic-paid-socal-event',
            ]),
            'graph.microsoft.com/v1.0/users/legal%40example.com/calendars/legal-calendar/events/automatic-paid-legal-outlook-event-id' => Http::response([
                'id' => 'automatic-paid-legal-outlook-event-id',
                'webLink' => 'https://outlook.office.com/automatic-paid-legal-event',
            ]),
        ]);

        $type = ConsultationType::where('slug', 'socal-half-day-mediation')->firstOrFail();
        $consultationUuid = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
        ])->json('data.uuid');

        $complete = $this->postJson("/api/v1/consultations/{$consultationUuid}/complete", [
            'legal_service_name' => 'Business, Payment & Contract Disputes',
            'consultation_mode' => 'online',
            'starts_at' => '2026-08-06T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'payment_mode' => 'split',
            'payment_method' => 'card',
            'primary_client' => [
                'first_name' => 'Final',
                'last_name' => 'Primary',
                'email' => 'final.primary@example.com',
            ],
            'participants' => [
                [
                    'first_name' => 'Final',
                    'last_name' => 'Participant',
                    'email' => 'final.participant@example.com',
                ],
            ],
        ])
            ->assertOk()
            ->json('data');

        [$firstPayment, $finalPayment] = $complete['payment_requests'];

        $this->postJson('/api/v1/payments/converge/webhook', [
            'provider_reference' => $firstPayment['provider_reference'],
            'status' => 'paid',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'payment_pending')
            ->assertJsonPath('data.payment_status', 'partially_paid')
            ->assertJsonPath('data.zoom_join_url', null);

        Mail::assertNotSent(ConsultationZoomLinkMail::class);
        $this->assertDatabaseMissing('integration_logs', [
            'provider' => 'outlook',
            'action' => 'automatic_payment_sync',
        ]);

        $this->postJson('/api/v1/payments/converge/webhook', [
            'provider_reference' => $finalPayment['provider_reference'],
            'status' => 'paid',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJson(fn ($json) => $json->whereType('data.zoom_join_url', 'string')->etc());

        Mail::assertSent(ConsultationZoomLinkMail::class, 2);
        Mail::assertSent(ConsultationZoomLinkMail::class, fn (ConsultationZoomLinkMail $mail) => $mail->participant->email === 'final.primary@example.com');
        Mail::assertSent(ConsultationZoomLinkMail::class, fn (ConsultationZoomLinkMail $mail) => $mail->participant->email === 'final.participant@example.com');

        $consultation = Consultation::findOrFail($consultationUuid);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultation->id,
            'provider' => 'mail',
            'action' => 'automatic_zoom_link',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultation->id,
            'provider' => 'outlook',
            'action' => 'automatic_payment_sync',
            'status' => 'synced',
        ]);
        foreach (['socal', 'legal'] as $application) {
            $this->assertDatabaseHas('external_calendar_events', [
                'provider' => 'outlook',
                'external_id' => 'consultation-'.$consultationUuid.'-'.$application,
                'application' => $application,
                'is_busy' => true,
            ]);
        }

        $this->postJson('/api/v1/payments/converge/webhook', [
            'payment_request_id' => $finalPayment['id'],
            'status' => 'paid',
        ])->assertOk();

        Mail::assertSent(ConsultationZoomLinkMail::class, 2);
        $this->assertSame(1, $consultation->integrationLogs()->where('action', 'automatic_payment_sync')->where('status', 'synced')->count());
    }

    public function test_reschedule_booking_api_regenerates_zoom_and_recreates_outlook_event(): void
    {
        $this->seed();
        Mail::fake();
        config([
            'services.outlook.enabled' => true,
            'services.outlook.tenant_id' => 'tenant-id',
            'services.outlook.client_id' => 'client-id',
            'services.outlook.client_secret' => 'client-secret',
            'services.outlook.login_base_url' => 'https://login.microsoftonline.com',
            'services.outlook.base_url' => 'https://graph.microsoft.com/v1.0',
            'services.outlook.socal_user_id' => 'socal@example.com',
            'services.outlook.socal_calendar_id' => 'socal-calendar',
            'services.outlook.legal_user_id' => 'legal@example.com',
            'services.outlook.legal_calendar_id' => 'legal-calendar',
        ]);

        $consultation = Consultation::where('booking_number', 'SAMPLE-04')->firstOrFail();
        $consultation->update([
            'zoom_meeting_id' => 'local-old-meeting-id',
            'zoom_join_url' => 'https://zoom.us/j/old-meeting',
        ]);
        $consultation->participants()->get()->each(function ($participant) use ($consultation) {
            app(QuestionnaireWorkflowService::class)
                ->ensureSubmission($consultation, $participant)
                ->update([
                    'status' => 'submitted',
                    'submitted_at' => now(),
                    'agreement_accepted' => true,
                    'agreement_accepted_at' => now(),
                ]);
        });
        $oldZoomUrl = $consultation->zoom_join_url;

        ExternalCalendarEvent::create([
            'provider' => 'outlook',
            'external_id' => 'consultation-'.$consultation->id,
            'application' => 'socal',
            'title' => 'Old consultation event',
            'starts_at' => $consultation->starts_at,
            'ends_at' => $consultation->ends_at,
            'is_busy' => true,
            'metadata' => ['outlook_event_id' => 'old-outlook-event-id'],
        ]);

        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response(['access_token' => 'graph-token'], 200),
            'graph.microsoft.com/v1.0/users/socal%40example.com/calendars/socal-calendar/events/old-outlook-event-id' => Http::response([
                'id' => 'old-outlook-event-id',
                'webLink' => 'https://outlook.office.com/rescheduled-socal-event',
                'subject' => 'Rescheduled booking',
            ]),
            'graph.microsoft.com/v1.0/users/legal%40example.com/calendars/legal-calendar/events' => Http::response([
                'id' => 'new-rescheduled-legal-outlook-event-id',
                'webLink' => 'https://outlook.office.com/rescheduled-legal-event',
                'subject' => 'Rescheduled booking',
            ], 201),
        ]);

        $this->postJson('/api/v1/consultations/'.$consultation->id.'/reschedule', [
            'starts_at' => '2026-10-06T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Consultation rescheduled.')
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.starts_at', '2026-10-06T09:00:00-07:00')
            ->assertJson(fn ($json) => $json->whereType('data.zoom_join_url', 'string')->etc());

        $consultation->refresh();
        $this->assertSame('2026-10-06 09:00:00', $consultation->starts_at->format('Y-m-d H:i:s'));
        $this->assertNotSame($oldZoomUrl, $consultation->zoom_join_url);

        Mail::assertSent(ConsultationZoomLinkMail::class);
        foreach (['socal' => 'old-outlook-event-id', 'legal' => 'new-rescheduled-legal-outlook-event-id'] as $application => $eventId) {
            $this->assertDatabaseHas('external_calendar_events', [
                'provider' => 'outlook',
                'external_id' => 'consultation-'.$consultation->id.'-'.$application,
                'application' => $application,
                'metadata->outlook_event_id' => $eventId,
                'is_busy' => true,
            ]);
        }
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultation->id,
            'provider' => 'api',
            'action' => 'api_reschedule',
            'status' => 'rescheduled',
        ]);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultation->id,
            'provider' => 'outlook',
            'action' => 'api_reschedule_outlook_sync',
            'status' => 'synced',
        ]);
    }

    public function test_reschedule_booking_api_sends_confirmation_and_admin_email_after_questionnaires_are_complete(): void
    {
        $this->seed();
        Mail::fake();
        config([
            'services.outlook.enabled' => false,
            'services.zoom.enabled' => false,
        ]);

        AppSetting::create([
            'key' => 'admin_new_consultation_notifications',
            'value' => [
                'enabled' => true,
                'emails' => ['owner@example.com'],
            ],
        ]);

        $consultation = Consultation::where('booking_number', 'SAMPLE-07')->firstOrFail();
        $consultation->update(['consultation_mode' => 'phone']);
        $participant = $consultation->participants()->where('is_primary', true)->firstOrFail();

        app(QuestionnaireWorkflowService::class)
            ->ensureSubmission($consultation, $participant)
            ->update([
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);

        $this->postJson('/api/v1/consultations/'.$consultation->id.'/reschedule', [
            'starts_at' => '2026-10-06T10:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Consultation rescheduled.')
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.starts_at', '2026-10-06T10:00:00-07:00');

        Mail::assertSent(ConsultationConfirmationMail::class, fn (ConsultationConfirmationMail $mail) => $mail->participant->email === 'lena.ortiz@example.com'
            && $mail->isReschedule
            && $mail->envelope()->subject === 'Your consultation has been rescheduled'
            && str_contains($mail->render(), 'Consultation Rescheduled'));
        Mail::assertSent(AdminConsultationRescheduledMail::class, fn (AdminConsultationRescheduledMail $mail) => $mail->hasTo('owner@example.com'));
        Mail::assertNotSent(ConsultationZoomLinkMail::class);

        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultation->id,
            'provider' => 'mail',
            'action' => 'api_reschedule_confirmation',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultation->id,
            'provider' => 'mail',
            'action' => 'admin_consultation_rescheduled',
            'status' => 'sent',
        ]);
    }

    public function test_reschedule_booking_api_does_not_generate_zoom_before_questionnaires_are_complete(): void
    {
        $this->seed();
        Mail::fake();

        $consultation = Consultation::where('booking_number', 'SAMPLE-04')->firstOrFail();
        $consultation->update([
            'zoom_meeting_id' => 'old-pending-questionnaire-meeting-id',
            'zoom_join_url' => 'https://zoom.us/j/old-pending-questionnaire-meeting',
        ]);

        $this->postJson('/api/v1/consultations/'.$consultation->id.'/reschedule', [
            'starts_at' => '2026-10-07T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Consultation rescheduled.')
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.zoom_join_url', null);

        Mail::assertNotSent(ConsultationZoomLinkMail::class);
        Mail::assertSent(ConsultationQuestionnaireMail::class, 4);
        $this->assertNull($consultation->refresh()->zoom_join_url);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultation->id,
            'provider' => 'mail',
            'action' => 'api_reschedule_questionnaire_link',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultation->id,
            'provider' => 'zoom',
            'action' => 'api_reschedule_zoom_link',
            'status' => 'skipped',
        ]);
    }

    public function test_reschedule_booking_api_resends_pending_payment_links_before_questionnaires(): void
    {
        $this->seed();
        Mail::fake();

        $consultation = Consultation::where('booking_number', 'SAMPLE-03')->firstOrFail();

        $this->postJson('/api/v1/consultations/'.$consultation->id.'/reschedule', [
            'starts_at' => '2026-10-09T13:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Consultation rescheduled.')
            ->assertJsonPath('data.payment_status', 'partially_paid')
            ->assertJsonPath('data.status', 'payment_pending');

        Mail::assertSent(ConsultationPaymentLinkMail::class, 2);
        Mail::assertSent(ConsultationQuestionnaireMail::class, 1);
        Mail::assertNotSent(ConsultationZoomLinkMail::class);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultation->id,
            'provider' => 'mail',
            'action' => 'api_reschedule_payment_link',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultation->id,
            'provider' => 'mail',
            'action' => 'api_reschedule_questionnaire_link',
            'status' => 'sent',
        ]);
    }

    public function test_reschedule_booking_api_rejects_unavailable_slot(): void
    {
        $this->seed();

        $consultation = Consultation::where('booking_number', 'SAMPLE-04')->firstOrFail();
        ExternalCalendarEvent::create([
            'provider' => 'outlook',
            'external_id' => 'blocked-reschedule-slot',
            'application' => 'socal',
            'title' => 'Blocked slot',
            'starts_at' => '2026-10-07 10:00:00',
            'ends_at' => '2026-10-07 11:00:00',
            'is_busy' => true,
        ]);

        $this->postJson('/api/v1/consultations/'.$consultation->id.'/reschedule', [
            'starts_at' => '2026-10-07T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This time slot is no longer available. Please choose another slot.');
    }

    public function test_reschedule_status_api_returns_only_pending_or_completed(): void
    {
        $this->seed();

        $pending = Consultation::where('booking_number', 'SAMPLE-04')->firstOrFail();
        $completed = Consultation::where('booking_number', 'SAMPLE-08')->firstOrFail();
        $completed->update(['status' => 'completed']);

        $this->getJson('/api/v1/consultations/'.$pending->id.'/reschedule-status')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonMissing(['status' => 'scheduled']);

        $this->getJson('/api/v1/consultations/'.$completed->id.'/reschedule-status')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_reschedule_booking_api_rejects_completed_consultation(): void
    {
        $this->seed();

        $consultation = Consultation::where('booking_number', 'SAMPLE-08')->firstOrFail();
        $consultation->update(['status' => 'completed']);

        $this->postJson('/api/v1/consultations/'.$consultation->id.'/reschedule', [
            'starts_at' => '2026-10-08T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only active bookings can be rescheduled.');
    }

    public function test_availability_slots_are_generated_from_configured_business_hours(): void
    {
        config([
            'app.booking_day_start' => '10:00',
            'app.booking_day_end' => '12:00',
        ]);
        $this->seed();

        $type = ConsultationType::where('slug', 'legal-professional-consultation')->firstOrFail();

        $slots = collect($this->getJson('/api/v1/availability?consultation_type_id='.$type->id.'&month=2026-08')
            ->assertOk()
            ->json('data'))
            ->firstWhere('date', '2026-08-03')['slots'];

        $this->assertSame(['10:00', '11:00'], collect($slots)->pluck('time')->all());
        $this->assertSame(['starts_at', 'ends_at'], array_values(array_intersect(['starts_at', 'ends_at'], array_keys($slots[0]))));
    }

    public function test_availability_returns_slots_for_selected_date_in_configured_timezone(): void
    {
        config([
            'app.booking_timezone' => 'Asia/Kolkata',
            'app.booking_day_start' => '10:00',
            'app.booking_day_end' => '12:00',
        ]);
        $this->seed();

        $type = ConsultationType::where('slug', 'legal-professional-consultation')->firstOrFail();

        $data = $this->getJson('/api/v1/availability?consultation_type_id='.$type->id.'&date=2026-08-03')
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-08-03', $data['date']);
        $this->assertSame(['10:00', '11:00'], collect($data['slots'])->pluck('time')->all());
        $this->assertSame('2026-08-03T10:00:00+05:30', $data['slots'][0]['starts_at']);
    }

    public function test_availability_excludes_same_day_slots_that_have_already_started(): void
    {
        config([
            'app.booking_timezone' => 'America/Los_Angeles',
            'app.booking_day_start' => '09:00',
            'app.booking_day_end' => '17:00',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-08-13 15:55:00', 'America/Los_Angeles'));
        $this->seed();

        $type = ConsultationType::where('slug', 'socal-half-day-mediation')->firstOrFail();

        $data = $this->getJson('/api/v1/availability?consultation_type_id='.$type->id.'&date=2026-08-13')
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-08-13', $data['date']);
        $this->assertSame([], $data['slots']);
    }

    public function test_availability_slot_spacing_uses_consultation_type_duration(): void
    {
        config([
            'app.booking_day_start' => '09:00',
            'app.booking_day_end' => '17:00',
        ]);
        $this->seed();

        $halfDay = ConsultationType::where('slug', 'socal-half-day-mediation')->firstOrFail();
        $fullDay = ConsultationType::where('slug', 'socal-full-day-mediation')->firstOrFail();

        $halfDaySlots = collect($this->getJson('/api/v1/availability?consultation_type_id='.$halfDay->id.'&month=2026-08')
            ->assertOk()
            ->json('data'))
            ->firstWhere('date', '2026-08-03')['slots'];

        $fullDaySlots = collect($this->getJson('/api/v1/availability?consultation_type_id='.$fullDay->id.'&month=2026-08')
            ->assertOk()
            ->json('data'))
            ->firstWhere('date', '2026-08-03')['slots'];

        $this->assertSame(['09:00', '13:00'], collect($halfDaySlots)->pluck('time')->all());
        $this->assertSame(['09:00'], collect($fullDaySlots)->pluck('time')->all());
    }

    public function test_availability_marks_duration_based_overlaps_unavailable(): void
    {
        config([
            'app.booking_day_start' => '09:00',
            'app.booking_day_end' => '17:00',
        ]);
        $this->seed();

        $legal = ConsultationType::where('slug', 'legal-professional-consultation')->firstOrFail();
        Consultation::where('booking_number', 'SAMPLE-08')->update([
            'starts_at' => '2026-08-03 10:00:00',
            'ends_at' => '2026-08-03 11:00:00',
            'consultation_type_id' => $legal->id,
        ]);

        $slots = collect($this->getJson('/api/v1/availability?consultation_type_id='.$legal->id.'&month=2026-08')
            ->assertOk()
            ->json('data'))
            ->firstWhere('date', '2026-08-03')['slots'];

        $this->assertFalse(collect($slots)->firstWhere('time', '10:00')['available']);
        $this->assertTrue(collect($slots)->firstWhere('time', '11:00')['available']);
    }

    public function test_availability_blocks_two_application_bookings_and_outlook_events_even_with_professional_filter(): void
    {
        config([
            'app.booking_day_start' => '09:00',
            'app.booking_day_end' => '13:00',
        ]);
        $this->seed();

        $legal = ConsultationType::where('slug', 'legal-professional-consultation')->firstOrFail();
        Consultation::where('booking_number', 'SAMPLE-02')->update([
            'starts_at' => '2026-08-03 10:00:00',
            'ends_at' => '2026-08-03 11:00:00',
            'professional_id' => 1,
        ]);
        ExternalCalendarEvent::create([
            'provider' => 'outlook',
            'external_id' => 'outlook-10am-team-catchup',
            'application' => 'socal',
            'title' => '10 AM Team catchup meeting',
            'starts_at' => '2026-08-03 11:00:00',
            'ends_at' => '2026-08-03 12:00:00',
            'is_busy' => true,
        ]);

        $slots = collect($this->getJson('/api/v1/availability?consultation_type_id='.$legal->id.'&month=2026-08&professional_id=2')
            ->assertOk()
            ->json('data'))
            ->firstWhere('date', '2026-08-03')['slots'];

        $this->assertFalse(collect($slots)->firstWhere('time', '10:00')['available']);
        $this->assertFalse(collect($slots)->firstWhere('time', '11:00')['available']);
        $this->assertTrue(collect($slots)->firstWhere('time', '12:00')['available']);
    }

    public function test_availability_blocks_existing_outlook_row_in_configured_booking_timezone(): void
    {
        config([
            'app.timezone' => 'Asia/Kolkata',
            'app.booking_timezone' => 'Asia/Kolkata',
            'app.booking_day_start' => '10:00',
            'app.booking_day_end' => '11:00',
        ]);
        $this->seed();

        ExternalCalendarEvent::create([
            'provider' => 'outlook',
            'external_id' => 'existing-team-catchup-row',
            'application' => 'legal',
            'title' => 'Team catchup meeting',
            'starts_at' => '2026-07-23 10:00:00',
            'ends_at' => '2026-07-23 10:30:00',
            'is_busy' => true,
        ]);

        $type = ConsultationType::where('slug', 'socal-free-intro-call')->firstOrFail();
        $data = $this->getJson('/api/v1/availability?consultation_type_id='.$type->id.'&date=2026-07-23')
            ->assertOk()
            ->json('data');

        $this->assertFalse(collect($data['slots'])->firstWhere('time', '10:00')['available']);
        $this->assertFalse(collect($data['slots'])->firstWhere('time', '10:15')['available']);
        $this->assertTrue(collect($data['slots'])->firstWhere('time', '10:30')['available']);
    }

    public function test_offline_legal_booking_blocks_socal_availability(): void
    {
        config([
            'app.booking_day_start' => '10:00',
            'app.booking_day_end' => '11:00',
        ]);
        $this->seed();

        Consultation::where('booking_number', 'SAMPLE-08')->update([
            'application' => 'legal',
            'consultation_mode' => 'offline',
            'status' => 'scheduled',
            'starts_at' => '2026-08-05 10:00:00',
            'ends_at' => '2026-08-05 11:00:00',
        ]);

        $type = ConsultationType::where('slug', 'socal-free-intro-call')->firstOrFail();
        $data = $this->getJson('/api/v1/availability?consultation_type_id='.$type->id.'&date=2026-08-05')
            ->assertOk()
            ->json('data');

        $this->assertFalse(collect($data['slots'])->firstWhere('time', '10:00')['available']);
    }

    public function test_availability_uses_locally_synced_outlook_rows_without_inline_http_calls(): void
    {
        config([
            'app.booking_day_start' => '09:00',
            'app.booking_day_end' => '12:00',
            'services.outlook.enabled' => true,
        ]);
        $this->seed();
        Http::fake();
        ExternalCalendarEvent::create([
            'provider' => 'outlook',
            'external_id' => 'socal-outlook-busy',
            'application' => 'socal',
            'title' => '10 AM Team catchup meeting',
            'starts_at' => '2026-08-04 10:00:00',
            'ends_at' => '2026-08-04 11:00:00',
            'is_busy' => true,
        ]);
        ExternalCalendarEvent::create([
            'provider' => 'outlook',
            'external_id' => 'legal-outlook-busy',
            'application' => 'legal',
            'title' => '11 AM Legal hold',
            'starts_at' => '2026-08-04 11:00:00',
            'ends_at' => '2026-08-04 12:00:00',
            'is_busy' => true,
        ]);

        $type = ConsultationType::where('slug', 'legal-professional-consultation')->firstOrFail();
        $slots = collect($this->getJson('/api/v1/availability?consultation_type_id='.$type->id.'&month=2026-08')
            ->assertOk()
            ->json('data'))
            ->firstWhere('date', '2026-08-04')['slots'];

        $this->assertFalse(collect($slots)->firstWhere('time', '10:00')['available']);
        $this->assertFalse(collect($slots)->firstWhere('time', '11:00')['available']);
        $this->assertDatabaseHas('external_calendar_events', [
            'provider' => 'outlook',
            'external_id' => 'socal-outlook-busy',
            'title' => '10 AM Team catchup meeting',
            'is_busy' => true,
        ]);
        $this->assertDatabaseHas('external_calendar_events', [
            'provider' => 'outlook',
            'external_id' => 'legal-outlook-busy',
            'title' => '11 AM Legal hold',
            'is_busy' => true,
        ]);
        Http::assertNothingSent();
    }

    public function test_availability_uses_outlook_rows_in_configured_booking_timezone(): void
    {
        config([
            'app.timezone' => 'Asia/Kolkata',
            'app.booking_timezone' => 'Asia/Kolkata',
            'app.booking_day_start' => '10:00',
            'app.booking_day_end' => '11:00',
            'services.outlook.enabled' => true,
        ]);
        $this->seed();
        Http::fake();
        ExternalCalendarEvent::create([
            'provider' => 'outlook',
            'external_id' => 'team-catchup-utc',
            'application' => 'socal',
            'title' => 'Team catchup meeting',
            'starts_at' => '2026-07-23 10:00:00',
            'ends_at' => '2026-07-23 10:30:00',
            'is_busy' => true,
        ]);

        $type = ConsultationType::where('slug', 'socal-free-intro-call')->firstOrFail();
        $data = $this->getJson('/api/v1/availability?consultation_type_id='.$type->id.'&date=2026-07-23')
            ->assertOk()
            ->json('data');

        $slot = collect($data['slots'])->firstWhere('time', '10:00');

        $this->assertFalse($slot['available']);
        $this->assertSame('2026-07-23T10:00:00+05:30', $slot['starts_at']);
        $this->assertDatabaseHas('external_calendar_events', [
            'provider' => 'outlook',
            'external_id' => 'team-catchup-utc',
            'title' => 'Team catchup meeting',
            'starts_at' => '2026-07-23 10:00:00',
            'ends_at' => '2026-07-23 10:30:00',
            'is_busy' => true,
        ]);
        Http::assertNothingSent();
    }

    public function test_unknown_legal_service_name_returns_validation_style_error(): void
    {
        $this->seed();

        $consultation = Consultation::where('booking_number', 'SAMPLE-06')->firstOrFail();

        $this->postJson("/api/v1/consultations/{$consultation->uuid}/complete", [
            'legal_service_name' => 'Nonexistent Service',
            'consultation_mode' => 'online',
            'starts_at' => '2026-08-03T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'payment_mode' => 'full',
            'primary_client' => [
                'first_name' => 'Taylor',
                'email' => 'taylor.reed@example.com',
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_schedule_rejects_slots_outside_configured_business_hours(): void
    {
        config([
            'app.booking_day_start' => '10:00',
            'app.booking_day_end' => '12:00',
        ]);
        $this->seed();

        $type = ConsultationType::where('slug', 'legal-professional-consultation')->firstOrFail();
        $consultationUuid = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
        ])->json('data.uuid');

        $this->postJson("/api/v1/consultations/{$consultationUuid}/complete", [
            'legal_service_name' => 'Professional Legal Consultation',
            'consultation_mode' => 'online',
            'starts_at' => '2026-08-03T09:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'payment_mode' => 'full',
            'primary_client' => [
                'first_name' => 'Taylor',
                'email' => 'taylor.reed@example.com',
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Selected slot is outside the configured booking hours.');
    }

    public function test_schedule_rejects_slots_that_have_already_started(): void
    {
        config([
            'app.booking_timezone' => 'America/Los_Angeles',
            'app.booking_day_start' => '09:00',
            'app.booking_day_end' => '17:00',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-08-13 15:55:00', 'America/Los_Angeles'));
        $this->seed();

        $type = ConsultationType::where('slug', 'socal-half-day-mediation')->firstOrFail();
        $consultationUuid = $this->postJson('/api/v1/consultations/draft', [
            'consultation_type_id' => $type->id,
        ])->json('data.uuid');

        $this->postJson("/api/v1/consultations/{$consultationUuid}/complete", [
            'legal_service_name' => 'Business, Payment & Contract Disputes',
            'consultation_mode' => 'online',
            'starts_at' => '2026-08-13T13:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
            'payment_mode' => 'full',
            'primary_client' => [
                'first_name' => 'Taylor',
                'email' => 'taylor.reed@example.com',
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Selected slot has already started.');
    }

    private function enableConverge(): void
    {
        config([
            'services.converge.enabled' => true,
            'services.converge.account_id' => 'account-id',
            'services.converge.user_id' => 'api-user',
            'services.converge.pin' => 'secret-pin',
            'services.converge.return_url' => 'https://app.example.test/api/v1/payments/converge/return',
        ]);
    }
}
