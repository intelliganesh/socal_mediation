<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertJsonPath('data.legal_service.name', 'Business, Payment & Contract Disputes')
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
            ->assertJsonPath('data.legal_service.name', 'Business, Payment & Contract Disputes')
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.payment_progress.total', 2);

        $this->assertDatabaseHas('consultations', [
            'uuid' => $consultationUuid,
            'primary_email' => 'taylor.reed@example.com',
            'payment_mode' => 'split',
        ]);
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
            ->assertJsonPath('data.status', 'pending_payment');
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
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.consultation_mode', 'online')
            ->assertJson(fn ($json) => $json->where('success', true)->whereType('data.zoom_join_url', 'string')->etc());
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
