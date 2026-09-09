<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationType;
use App\Models\ExternalCalendarEvent;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AvailabilityConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.booking_timezone' => 'Asia/Kolkata',
            'app.booking_day_start' => '09:00',
            'app.booking_day_end' => '17:00',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00:00', 'Asia/Kolkata'));
        $this->seed();
        Http::preventStrayRequests();
    }

    public function test_start_can_be_listed_but_full_duration_conflicts_and_confirmation_does_not_reserve(): void
    {
        $type = ConsultationType::where('slug', 'socal-half-day-mediation')->firstOrFail();
        $this->busyEvent('11:00', '12:00');
        $this->busyEvent('16:00', '17:00');

        $slots = $this->getJson('/api/v1/availability?consultation_type_id='.$type->id.'&date=2026-09-10')
            ->assertOk()->json('data.slots');
        $times = array_column($slots, 'time');
        $this->assertContains('09:00', $times);
        $this->assertContains('12:00', $times);
        $this->assertNotContains('11:00', $times);
        $this->assertNotContains('11:30', $times);
        foreach ($slots as $slot) {
            $this->assertArrayNotHasKey('ends_at', $slot);
            $this->assertTrue($slot['available']);
        }

        $this->postJson('/api/v1/availability/confirm', [
            'consultation_type_id' => $type->id,
            'starts_at' => '2026-09-10T09:00:00+05:30',
        ])->assertStatus(422)->assertJsonPath('message', 'The selected time does not have enough availability for this consultation. Please choose another start time.');

        $count = Consultation::count();
        $this->postJson('/api/v1/availability/confirm', [
            'consultation_type_id' => $type->id,
            'starts_at' => '2026-09-10T12:00:00-07:00',
            'timezone' => 'America/Los_Angeles',
        ])->assertOk()->assertExactJson([
            'success' => true,
            'message' => 'Selected time slot is available.',
            'data' => [
                'starts_at' => '2026-09-10T12:00:00+05:30',
                'ends_at' => '2026-09-10T16:00:00+05:30',
            ],
        ]);
        $this->assertSame($count, Consultation::count());

        // Final booking validation must still reject a conflict arriving after confirmation.
        $this->busyEvent('14:00', '14:30');
        $this->expectException(\DomainException::class);
        app(AvailabilityService::class)->assertAvailable($type, CarbonImmutable::parse('2026-09-10 12:00:00', 'Asia/Kolkata'));
    }

    public function test_confirmation_checks_hours_past_starts_weekends_and_thirty_minute_grid(): void
    {
        $type = ConsultationType::where('slug', 'socal-half-day-mediation')->firstOrFail();
        foreach (['2026-09-10T14:00:00', '2026-08-31T09:00:00', '2026-09-12T09:00:00', '2026-09-10T09:15:00', '2026-09-10T09:30:01'] as $start) {
            $this->postJson('/api/v1/availability/confirm', [
                'consultation_type_id' => $type->id,
                'starts_at' => $start,
            ])->assertStatus(422)->assertJsonPath('success', false);
        }

        $this->postJson('/api/v1/availability/confirm', [
            'consultation_type_id' => $type->id,
            'starts_at' => '2026-09-10T09:30:00',
        ])->assertOk()->assertJsonPath('data.ends_at', '2026-09-10T13:30:00+05:30');

        $this->getJson('/api/v1/availability?consultation_type_id='.$type->id.'&date=2026-08-31')
            ->assertOk()->assertJsonPath('data.slots', []);
    }

    public function test_confirmation_validates_inputs_and_uses_selected_type_duration(): void
    {
        $this->postJson('/api/v1/availability/confirm', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['consultation_type_id', 'starts_at']);

        foreach (['socal-free-intro-call', 'legal-professional-consultation', 'socal-full-day-mediation'] as $slug) {
            $type = ConsultationType::where('slug', $slug)->firstOrFail();
            $this->postJson('/api/v1/availability/confirm', [
                'consultation_type_id' => $type->id,
                'starts_at' => '2026-09-10T09:00:00+05:30',
            ])->assertOk()->assertJsonPath('data.ends_at', CarbonImmutable::parse('2026-09-10 09:00:00', 'Asia/Kolkata')->addMinutes($type->duration_minutes)->toIso8601String());
        }
    }

    private function busyEvent(string $start, string $end): void
    {
        ExternalCalendarEvent::create([
            'provider' => 'outlook',
            'external_id' => 'busy-'.$start,
            'application' => 'legal',
            'title' => 'Busy',
            'starts_at' => '2026-09-10 '.$start.':00',
            'ends_at' => '2026-09-10 '.$end.':00',
            'is_busy' => true,
        ]);
    }
}
