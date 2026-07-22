<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Services\Integrations\OutlookCalendarClient;
use App\Services\Integrations\ZoomClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_zoom_uses_local_placeholder_when_meetings_are_disabled(): void
    {
        $this->seed();
        config(['services.zoom.enabled' => false]);
        Http::fake();

        $consultation = Consultation::with('type')->where('booking_number', 'SAMPLE-04')->firstOrFail();
        $meeting = app(ZoomClient::class)->createMeeting($consultation);

        $this->assertStringStartsWith('local-', $meeting['id']);
        $this->assertStringContainsString('/j/', $meeting['join_url']);
        Http::assertNothingSent();
    }

    public function test_zoom_can_create_and_delete_real_meeting_when_enabled(): void
    {
        $this->seed();
        config([
            'services.zoom.enabled' => true,
            'services.zoom.account_id' => 'account-id',
            'services.zoom.client_id' => 'client-id',
            'services.zoom.client_secret' => 'client-secret',
            'services.zoom.oauth_base_url' => 'https://zoom.us',
            'services.zoom.base_url' => 'https://api.zoom.us/v2',
        ]);

        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'zoom-token'], 200),
            'api.zoom.us/v2/users/me/meetings' => Http::response([
                'id' => 123456789,
                'join_url' => 'https://zoom.us/j/123456789',
                'start_url' => 'https://zoom.us/s/123456789',
            ], 201),
            'api.zoom.us/v2/meetings/123456789' => Http::response(null, 204),
        ]);

        $consultation = Consultation::with('type')->where('booking_number', 'SAMPLE-04')->firstOrFail();
        $zoom = app(ZoomClient::class);

        $meeting = $zoom->createMeeting($consultation);
        $zoom->deleteMeeting($meeting['id']);

        $this->assertSame('123456789', $meeting['id']);
        $this->assertSame('https://zoom.us/j/123456789', $meeting['join_url']);
        Http::assertSentCount(4);
    }

    public function test_outlook_sync_reports_disabled_when_toggle_is_off(): void
    {
        config(['services.outlook.enabled' => false]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Outlook sync is disabled.');

        app(OutlookCalendarClient::class)->syncCurrentWindow();
    }

    public function test_outlook_can_create_and_delete_smoke_test_event_when_enabled(): void
    {
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
                'id' => 'event-id',
                'webLink' => 'https://outlook.office.com/event',
            ], 201),
            'graph.microsoft.com/v1.0/users/socal%40example.com/calendars/socal-calendar/events/event-id' => Http::response(null, 204),
        ]);

        $outlook = app(OutlookCalendarClient::class);
        $eventId = $outlook->createSmokeTestEvent('socal');
        $outlook->deleteEvent('socal', $eventId);

        $this->assertSame('event-id', $eventId);
        Http::assertSentCount(4);
    }

    public function test_outlook_requires_calendar_owner_user_id_when_enabled(): void
    {
        config([
            'services.outlook.enabled' => true,
            'services.outlook.socal_user_id' => null,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Outlook socal user id is not configured.');

        app(OutlookCalendarClient::class)->createSmokeTestEvent('socal');
    }
}
