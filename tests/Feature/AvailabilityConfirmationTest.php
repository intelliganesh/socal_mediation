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
        $this->busyEvent('10:00', '10:30');
        $this->busyEvent('11:00', '12:00');
        $this->busyEvent('16:00', '17:00');

        $slots = $this->getJson('/api/v1/availability?consultation_type_id='.$type->id.'&date=2026-09-10')
            ->assertOk()->json('data.slots');
        $times = array_column($slots, 'time');
        $this->assertContains('09:00', $times);
        $this->assertContains('12:00', $times);
        $this->assertNotContains('10:00', $times);
        $this->assertNotContains('11:00', $times);
        $this->assertNotContains('11:30', $times);
        foreach ($slots as $slot) {
            $this->assertArrayNotHasKey('ends_at', $slot);
            $this->assertTrue($slot['available']);
        }

        $this->postJson('/api/v1/availability/confirm', [
            'consultation_type_id' => $type->id,
            'starts_at' => '2026-09-10T09:00:00+05:30',
        ])->assertStatus(422)->assertJsonPath('message', 'Another appointment from 10:00 AM to 10:30 AM conflicts with your selected time, 9:00 AM to 1:00 PM. Please choose another start time. The next available start time is Sep 10, 2026 at 12:00 PM.');

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

    public function test_confirmation_checks_hours_past_starts_sundays_and_thirty_minute_grid(): void
    {
        $type = ConsultationType::where('slug', 'socal-half-day-mediation')->firstOrFail();
        foreach (['2026-09-10T14:00:00', '2026-08-31T09:00:00', '2026-09-13T09:00:00', '2026-09-10T09:15:00', '2026-09-10T09:30:01'] as $start) {
            $response = $this->postJson('/api/v1/availability/confirm', [
                'consultation_type_id' => $type->id,
                'starts_at' => $start,
            ])->assertStatus(422)
                ->assertJsonPath('success', false);

            $this->assertStringStartsWith(
                'The selected start time is not available. Please choose another start time. The next available start time is ',
                $response->json('message')
            );
        }

        $this->postJson('/api/v1/availability/confirm', [
            'consultation_type_id' => $type->id,
            'starts_at' => '2026-09-10T09:30:00',
        ])->assertOk()->assertJsonPath('data.ends_at', '2026-09-10T13:30:00+05:30');

        $this->getJson('/api/v1/availability?consultation_type_id='.$type->id.'&date=2026-08-31')
            ->assertOk()->assertJsonPath('data.slots', []);
    }

    public function test_saturdays_are_available_for_booking(): void
    {
        $type = ConsultationType::where('slug', 'socal-half-day-mediation')->firstOrFail();

        $slots = $this->getJson('/api/v1/availability?consultation_type_id='.$type->id.'&date=2026-09-12')
            ->assertOk()
            ->json('data.slots');

        $this->assertContains('09:00', array_column($slots, 'time'));

        $this->postJson('/api/v1/availability/confirm', [
            'consultation_type_id' => $type->id,
            'starts_at' => '2026-09-12T09:00:00+05:30',
        ])->assertOk()->assertJsonPath('data.ends_at', '2026-09-12T13:00:00+05:30');
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

    public function test_only_free_intro_calls_use_fifteen_minute_start_intervals(): void
    {
        config(['app.booking_day_end' => '10:00']);
        $intro = ConsultationType::where('slug', 'socal-free-intro-call')->firstOrFail();
        $legal = ConsultationType::where('slug', 'legal-professional-consultation')->firstOrFail();

        foreach ([$intro, $legal] as $type) {
            $slots = $this->getJson('/api/v1/availability?consultation_type_id='.$type->id.'&date=2026-09-10')
                ->assertOk()->json('data.slots');
            $this->assertSame(
                $type->id === $intro->id ? ['09:00', '09:15', '09:30', '09:45'] : ['09:00', '09:30'],
                array_column($slots, 'time')
            );
        }

        foreach (['09:15', '09:45'] as $time) {
            $this->postJson('/api/v1/availability/confirm', [
                'consultation_type_id' => $intro->id,
                'starts_at' => '2026-09-10T'.$time.':00+05:30',
            ])->assertOk()->assertJsonPath('data.ends_at', CarbonImmutable::parse('2026-09-10 '.$time, 'Asia/Kolkata')->addMinutes(15)->toIso8601String());
        }

        config(['app.booking_day_end' => '17:00']);
        $this->postJson('/api/v1/availability/confirm', [
            'consultation_type_id' => $legal->id,
            'starts_at' => '2026-09-10T09:15:00+05:30',
        ])->assertUnprocessable();
    }

    public function test_conflict_message_suggests_next_start_using_selected_type_duration_and_interval(): void
    {
        $this->busyEvent('09:15', '09:45');
        $intro = ConsultationType::where('slug', 'socal-free-intro-call')->firstOrFail();
        $legal = ConsultationType::where('slug', 'legal-professional-consultation')->firstOrFail();

        $this->postJson('/api/v1/availability/confirm', [
            'consultation_type_id' => $intro->id,
            'starts_at' => '2026-09-10T09:15:00+05:30',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Another appointment from 9:15 AM to 9:45 AM conflicts with your selected time, 9:15 AM to 9:30 AM. Please choose another start time. The next available start time is Sep 10, 2026 at 9:45 AM.');

        $this->postJson('/api/v1/availability/confirm', [
            'consultation_type_id' => $legal->id,
            'starts_at' => '2026-09-10T09:30:00+05:30',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Another appointment from 9:15 AM to 9:45 AM conflicts with your selected time, 9:30 AM to 10:30 AM. Please choose another start time. The next available start time is Sep 10, 2026 at 10:00 AM.');
    }

    public function test_confirmation_checks_the_selected_interval_live_in_outlook(): void
    {
        $this->enableOutlook();
        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response([
                'access_token' => 'graph-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/users/shared%40example.com/calendars/shared-calendar/calendarView*' => Http::response([
                'value' => [[
                    'id' => 'new-client-event',
                    'subject' => 'Client offline appointment',
                    'showAs' => 'busy',
                    'start' => ['dateTime' => '2026-09-10T12:45:00', 'timeZone' => 'Asia/Kolkata'],
                    'end' => ['dateTime' => '2026-09-10T13:15:00', 'timeZone' => 'Asia/Kolkata'],
                ]],
            ]),
        ]);
        $type = ConsultationType::where('slug', 'legal-professional-consultation')->firstOrFail();

        $this->postJson('/api/v1/availability/confirm', [
            'consultation_type_id' => $type->id,
            'starts_at' => '2026-09-10T12:30:00+05:30',
        ])->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Another appointment from 12:45 PM to 1:15 PM conflicts with your selected time, 12:30 PM to 1:30 PM. Please choose another start time. The next available start time is Sep 10, 2026 at 1:30 PM.'
            );

        Http::assertSent(fn ($request) => str_contains($request->url(), '/calendarView')
            && str_contains($request->url(), 'startDateTime=2026-09-10T12%3A30%3A00%2B05%3A30')
            && str_contains($request->url(), 'endDateTime=2026-09-10T13%3A30%3A00%2B05%3A30'));
    }

    public function test_free_intro_live_conflict_suggests_the_next_fifteen_minute_start(): void
    {
        $this->enableOutlook();
        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response([
                'access_token' => 'graph-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/users/shared%40example.com/calendars/shared-calendar/calendarView*' => Http::response([
                'value' => [[
                    'id' => 'new-free-intro-conflict',
                    'subject' => 'Client appointment',
                    'showAs' => 'busy',
                    'start' => ['dateTime' => '2026-09-10T15:20:00', 'timeZone' => 'Asia/Kolkata'],
                    'end' => ['dateTime' => '2026-09-10T15:50:00', 'timeZone' => 'Asia/Kolkata'],
                ]],
            ]),
        ]);
        $type = ConsultationType::where('slug', 'socal-free-intro-call')->firstOrFail();

        $this->postJson('/api/v1/availability/confirm', [
            'consultation_type_id' => $type->id,
            'starts_at' => '2026-09-10T15:30:00+05:30',
        ])->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Another appointment from 3:20 PM to 3:50 PM conflicts with your selected time, 3:30 PM to 3:45 PM. Please choose another start time. The next available start time is Sep 10, 2026 at 4:00 PM.'
            );
    }

    public function test_confirmation_fails_closed_when_live_outlook_cannot_be_checked(): void
    {
        $this->enableOutlook();
        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response([
                'access_token' => 'graph-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/users/shared%40example.com/calendars/shared-calendar/calendarView*' => Http::response('Unavailable', 503),
        ]);
        $type = ConsultationType::where('slug', 'legal-professional-consultation')->firstOrFail();

        $this->postJson('/api/v1/availability/confirm', [
            'consultation_type_id' => $type->id,
            'starts_at' => '2026-09-10T12:30:00+05:30',
        ])->assertStatus(503)
            ->assertJsonPath('message', 'Calendar availability could not be verified. Please try again.');
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

    private function enableOutlook(): void
    {
        config([
            'services.outlook.enabled' => true,
            'services.outlook.tenant_id' => 'tenant-id',
            'services.outlook.client_id' => 'client-id',
            'services.outlook.client_secret' => 'client-secret',
            'services.outlook.login_base_url' => 'https://login.microsoftonline.com',
            'services.outlook.base_url' => 'https://graph.microsoft.com/v1.0',
            'services.outlook.user_id' => 'shared@example.com',
            'services.outlook.calendar_id' => 'shared-calendar',
        ]);
    }
}
