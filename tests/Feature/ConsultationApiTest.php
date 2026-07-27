<?php

namespace Tests\Feature;

use App\Mail\ConsultationPaymentLinkMail;
use App\Mail\ConsultationZoomLinkMail;
use App\Models\Consultation;
use App\Models\ConsultationType;
use App\Models\ExternalCalendarEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsultationApiTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_split_payment_links_are_sent_only_to_selected_payment_participant_emails(): void
    {
        $this->seed();
        Mail::fake();

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

    public function test_final_paid_webhook_sends_zoom_links_and_syncs_outlook_once(): void
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
        ]);

        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response(['access_token' => 'graph-token'], 200),
            'graph.microsoft.com/v1.0/users/socal%40example.com/calendars/socal-calendar/events' => Http::response([
                'id' => 'automatic-paid-outlook-event-id',
                'webLink' => 'https://outlook.office.com/automatic-paid-event',
                'subject' => 'Synced booking',
            ], 201),
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
        $this->assertDatabaseHas('external_calendar_events', [
            'provider' => 'outlook',
            'external_id' => 'consultation-'.$consultationUuid,
            'application' => 'socal',
            'is_busy' => true,
        ]);

        $this->postJson('/api/v1/payments/converge/webhook', [
            'provider_reference' => $finalPayment['provider_reference'],
            'status' => 'paid',
        ])->assertOk();

        Mail::assertSent(ConsultationZoomLinkMail::class, 2);
        $this->assertSame(1, $consultation->integrationLogs()->where('action', 'automatic_payment_sync')->where('status', 'synced')->count());
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

    public function test_availability_refreshes_outlook_before_returning_slots_when_enabled(): void
    {
        config([
            'app.booking_day_start' => '09:00',
            'app.booking_day_end' => '12:00',
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
        $this->seed();

        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response(['access_token' => 'graph-token'], 200),
            'graph.microsoft.com/v1.0/users/socal%40example.com/calendars/socal-calendar/calendarView*' => Http::response([
                'value' => [[
                    'id' => 'socal-outlook-busy',
                    'subject' => '10 AM Team catchup meeting',
                    'showAs' => 'busy',
                    'webLink' => 'https://outlook.office.com/socal-event',
                    'start' => ['dateTime' => '2026-08-04T10:00:00', 'timeZone' => 'America/Los_Angeles'],
                    'end' => ['dateTime' => '2026-08-04T11:00:00', 'timeZone' => 'America/Los_Angeles'],
                ]],
            ], 200),
            'graph.microsoft.com/v1.0/users/legal%40example.com/calendars/legal-calendar/calendarView*' => Http::response([
                'value' => [[
                    'id' => 'legal-outlook-busy',
                    'subject' => '11 AM Legal hold',
                    'showAs' => 'busy',
                    'webLink' => 'https://outlook.office.com/legal-event',
                    'start' => ['dateTime' => '2026-08-04T11:00:00', 'timeZone' => 'America/Los_Angeles'],
                    'end' => ['dateTime' => '2026-08-04T12:00:00', 'timeZone' => 'America/Los_Angeles'],
                ]],
            ], 200),
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
    }

    public function test_availability_converts_outlook_utc_events_to_configured_booking_timezone(): void
    {
        config([
            'app.timezone' => 'Asia/Kolkata',
            'app.booking_timezone' => 'Asia/Kolkata',
            'app.booking_day_start' => '10:00',
            'app.booking_day_end' => '11:00',
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
        $this->seed();

        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response(['access_token' => 'graph-token'], 200),
            'graph.microsoft.com/v1.0/users/socal%40example.com/calendars/socal-calendar/calendarView*' => Http::response([
                'value' => [[
                    'id' => 'team-catchup-utc',
                    'subject' => 'Team catchup meeting',
                    'showAs' => 'tentative',
                    'webLink' => 'https://outlook.office.com/team-catchup',
                    'start' => ['dateTime' => '2026-07-23T04:30:00.0000000', 'timeZone' => 'UTC'],
                    'end' => ['dateTime' => '2026-07-23T05:00:00.0000000', 'timeZone' => 'UTC'],
                ]],
            ], 200),
            'graph.microsoft.com/v1.0/users/legal%40example.com/calendars/legal-calendar/calendarView*' => Http::response(['value' => []], 200),
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
}
