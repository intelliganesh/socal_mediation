<?php

namespace Tests\Feature;

use App\Mail\ConsultationPaymentLinkMail;
use App\Mail\ConsultationZoomLinkMail;
use App\Models\Consultation;
use App\Models\ExternalCalendarEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_panel_links_to_api_documentation(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@socal.test')->firstOrFail();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('API Documentation')
            ->assertSee('/api/documentation');
    }

    public function test_consultation_detail_actions_follow_payment_state(): void
    {
        $this->seed();
        Mail::fake();

        $admin = User::where('email', 'admin@socal.test')->firstOrFail();
        $paidConsultation = Consultation::where('booking_number', 'SAMPLE-04')->firstOrFail();
        $partialConsultation = Consultation::where('booking_number', 'SAMPLE-03')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.consultations.show', $paidConsultation))
            ->assertOk()
            ->assertSee('Admin Actions')
            ->assertDontSee('Send Reminder')
            ->assertDontSee('Send Payment Links');

        $this->actingAs($admin)
            ->get(route('admin.consultations.show', $partialConsultation))
            ->assertOk()
            ->assertSee('Participants')
            ->assertSee('Payment Progress')
            ->assertSee('Payment Shares')
            ->assertSee('Email Activity')
            ->assertSee('Professional')
            ->assertSee('Meeting Mode')
            ->assertSee('Zoom')
            ->assertSee('Outlook Calendar')
            ->assertSee('View Payment Link')
            ->assertSee('Send Reminder')
            ->assertSee('Send Payment Links')
            ->assertSee('Sync This Booking To Outlook');

        $this->actingAs($admin)
            ->post(route('admin.consultations.payment-links', $partialConsultation))
            ->assertRedirect();

        Mail::assertSent(ConsultationPaymentLinkMail::class, 2);
    }

    public function test_calendar_can_be_filtered_by_application(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@socal.test')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.calendar.index', ['application' => 'legal']))
            ->assertOk()
            ->assertSee('All Applications')
            ->assertSee('Legal Consultation');
    }

    public function test_calendar_sync_pushes_future_consultations_and_deletes_stale_outlook_events(): void
    {
        $this->seed();
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

        Consultation::query()->update(['starts_at' => null, 'ends_at' => null]);
        $consultation = Consultation::where('booking_number', 'SAMPLE-08')->firstOrFail();
        $consultation->update([
            'status' => 'scheduled',
            'starts_at' => now(config('app.booking_timezone'))->addDays(5)->setTime(10, 0),
            'ends_at' => now(config('app.booking_timezone'))->addDays(5)->setTime(11, 0),
        ]);

        ExternalCalendarEvent::create([
            'provider' => 'outlook',
            'external_id' => 'consultation-stale-future',
            'application' => 'legal',
            'title' => 'Stale future consultation',
            'starts_at' => now(config('app.booking_timezone'))->addDays(6)->setTime(10, 0),
            'ends_at' => now(config('app.booking_timezone'))->addDays(6)->setTime(11, 0),
            'is_busy' => true,
            'metadata' => ['outlook_event_id' => 'stale-event-id'],
        ]);

        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response(['access_token' => 'graph-token'], 200),
            'graph.microsoft.com/v1.0/users/legal%40example.com/calendars/legal-calendar/events/stale-event-id' => Http::response(null, 204),
            'graph.microsoft.com/v1.0/users/socal%40example.com/calendars/socal-calendar/calendarView*' => Http::response(['value' => []], 200),
            'graph.microsoft.com/v1.0/users/legal%40example.com/calendars/legal-calendar/calendarView*' => Http::response(['value' => []], 200),
            'graph.microsoft.com/v1.0/users/legal%40example.com/calendars/legal-calendar/events' => Http::response([
                'id' => 'future-consultation-outlook-id',
                'webLink' => 'https://outlook.office.com/future-consultation',
            ], 201),
        ]);

        $admin = User::where('email', 'admin@socal.test')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.calendar.sync'))
            ->assertRedirect()
            ->assertSessionHas('status', 'Outlook sync completed. 0 busy event(s) refreshed, 1 future consultation(s) synced, 1 stale consultation event(s) deleted.');

        $this->assertDatabaseHas('external_calendar_events', [
            'provider' => 'outlook',
            'external_id' => 'consultation-'.$consultation->id,
            'application' => 'legal',
            'is_busy' => true,
        ]);
        $this->assertDatabaseMissing('external_calendar_events', [
            'provider' => 'outlook',
            'external_id' => 'consultation-stale-future',
        ]);
    }

    public function test_admin_can_resend_zoom_meeting_link(): void
    {
        $this->seed();
        Mail::fake();

        $admin = User::where('email', 'admin@socal.test')->firstOrFail();
        $consultation = Consultation::where('booking_number', 'SAMPLE-04')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.consultations.show', $consultation))
            ->assertOk()
            ->assertSee('Resend Zoom Link');

        $this->actingAs($admin)
            ->post(route('admin.consultations.zoom-link', $consultation))
            ->assertRedirect();

        Mail::assertSent(ConsultationZoomLinkMail::class, 4);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultation->id,
            'provider' => 'mail',
            'action' => 'manual_zoom_link',
            'status' => 'sent',
        ]);
    }

    public function test_email_activity_uses_distinct_labels_for_each_mail_action(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@socal.test')->firstOrFail();
        $consultation = Consultation::where('booking_number', 'SAMPLE-04')->firstOrFail();

        foreach ([
            'manual_payment_link' => 'client@example.com',
            'manual_payment_reminder' => 'participant@example.com',
            'manual_zoom_link' => 'zoom@example.com',
            'manual_reschedule_zoom_link' => 'reschedule@example.com',
        ] as $action => $recipient) {
            $consultation->integrationLogs()->create([
                'provider' => 'mail',
                'action' => $action,
                'status' => 'sent',
                'request_payload' => ['recipient' => $recipient],
            ]);
        }

        $this->actingAs($admin)
            ->get(route('admin.consultations.show', $consultation))
            ->assertOk()
            ->assertSee('Payment Link')
            ->assertSee('Payment Reminder')
            ->assertSee('Zoom Link')
            ->assertSee('Reschedule Zoom Link')
            ->assertSee('client@example.com')
            ->assertSee('participant@example.com')
            ->assertSee('zoom@example.com')
            ->assertSee('reschedule@example.com');
    }

    public function test_admin_can_cancel_reschedule_and_regenerate_meeting_link(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@socal.test')->firstOrFail();
        $onlineConsultation = Consultation::where('booking_number', 'SAMPLE-04')->firstOrFail();
        $scheduledConsultation = Consultation::where('booking_number', 'SAMPLE-08')->firstOrFail();
        $oldZoomUrl = $onlineConsultation->zoom_join_url;

        $this->actingAs($admin)
            ->get(route('admin.consultations.show', $onlineConsultation))
            ->assertOk()
            ->assertSee('Regenerate Meeting Link')
            ->assertSee('Cancel Consultation')
            ->assertSee('Reschedule');

        $this->actingAs($admin)
            ->post(route('admin.consultations.regenerate-zoom', $onlineConsultation))
            ->assertRedirect();

        $this->assertNotSame($oldZoomUrl, $onlineConsultation->refresh()->zoom_join_url);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $onlineConsultation->id,
            'provider' => 'zoom',
            'action' => 'manual_regenerate_meeting',
            'status' => 'generated',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.consultations.reschedule', $scheduledConsultation), [
                'starts_at' => '2026-10-01T09:00',
            ])
            ->assertRedirect();

        $this->assertSame('2026-10-01 09:00:00', $scheduledConsultation->refresh()->starts_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $scheduledConsultation->id,
            'provider' => 'admin',
            'action' => 'manual_reschedule',
            'status' => 'rescheduled',
        ]);

        $halfDayConsultation = Consultation::where('booking_number', 'SAMPLE-03')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.consultations.reschedule', $halfDayConsultation), [
                'starts_at' => '2026-10-02T13:00',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Consultation rescheduled.');

        $this->assertSame('2026-10-02 13:00:00', $halfDayConsultation->refresh()->starts_at->format('Y-m-d H:i:s'));

        $this->actingAs($admin)
            ->post(route('admin.consultations.reschedule', $halfDayConsultation), [
                'starts_at' => $onlineConsultation->starts_at->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Selected slot overlaps with an existing booking or Outlook event.');

        $this->assertSame('2026-10-02 13:00:00', $halfDayConsultation->refresh()->starts_at->format('Y-m-d H:i:s'));

        $this->actingAs($admin)
            ->post(route('admin.consultations.cancel', $scheduledConsultation))
            ->assertRedirect();

        $this->assertSame('cancelled', $scheduledConsultation->refresh()->status);
        $this->assertSame('pending', $scheduledConsultation->payment_status);
    }

    public function test_reschedule_keeps_zoom_update_when_zoom_email_delivery_fails(): void
    {
        $this->seed();

        Mail::shouldReceive('to')
            ->andThrow(new \RuntimeException('SMTP failed'));

        $admin = User::where('email', 'admin@socal.test')->firstOrFail();
        $consultation = Consultation::where('booking_number', 'SAMPLE-04')->firstOrFail();
        $oldZoomUrl = $consultation->zoom_join_url;

        $this->actingAs($admin)
            ->post(route('admin.consultations.reschedule', $consultation), [
                'starts_at' => '2026-10-05T09:00',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Consultation rescheduled. Zoom link was regenerated, but no Zoom emails were sent.');

        $consultation->refresh();

        $this->assertSame('2026-10-05 09:00:00', $consultation->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('scheduled', $consultation->status);
        $this->assertNotSame($oldZoomUrl, $consultation->zoom_join_url);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultation->id,
            'provider' => 'mail',
            'action' => 'manual_reschedule_zoom_link',
            'status' => 'failed',
        ]);
    }

    public function test_admin_can_sync_consultation_to_outlook(): void
    {
        $this->seed();
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
                'id' => 'outlook-event-id',
                'webLink' => 'https://outlook.office.com/event',
            ], 201),
        ]);

        $admin = User::where('email', 'admin@socal.test')->firstOrFail();
        $consultation = Consultation::where('booking_number', 'SAMPLE-04')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.consultations.sync-outlook', $consultation))
            ->assertRedirect()
            ->assertSessionHas('status', 'This booking was synced to Outlook.');

        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultation->id,
            'provider' => 'outlook',
            'action' => 'manual_booking_sync',
            'status' => 'synced',
        ]);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultation->id,
            'provider' => 'outlook',
            'action' => 'manual_booking_sync',
            'request_payload->subject' => $consultation->booking_number.' - '.$consultation->type->name,
            'response_payload->id' => 'outlook-event-id',
        ]);

        $this->assertDatabaseHas('external_calendar_events', [
            'provider' => 'outlook',
            'external_id' => 'consultation-'.$consultation->uuid,
            'application' => 'socal',
            'is_busy' => true,
        ]);
    }

    public function test_reschedule_automatically_syncs_consultation_to_outlook_when_enabled(): void
    {
        $this->seed();
        config([
            'services.outlook.enabled' => true,
            'services.outlook.tenant_id' => 'tenant-id',
            'services.outlook.client_id' => 'client-id',
            'services.outlook.client_secret' => 'client-secret',
            'services.outlook.login_base_url' => 'https://login.microsoftonline.com',
            'services.outlook.base_url' => 'https://graph.microsoft.com/v1.0',
            'services.outlook.legal_user_id' => 'legal@example.com',
            'services.outlook.legal_calendar_id' => 'legal-calendar',
        ]);

        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response(['access_token' => 'graph-token'], 200),
            'graph.microsoft.com/v1.0/users/legal%40example.com/calendars/legal-calendar/events' => Http::response([
                'id' => 'rescheduled-outlook-event-id',
                'webLink' => 'https://outlook.office.com/rescheduled-event',
            ], 201),
        ]);

        $admin = User::where('email', 'admin@socal.test')->firstOrFail();
        $consultation = Consultation::where('booking_number', 'SAMPLE-08')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.consultations.reschedule', $consultation), [
                'starts_at' => '2026-10-01T13:00',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Consultation rescheduled.');

        $this->assertSame('2026-10-01 13:00:00', $consultation->refresh()->starts_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultation->id,
            'provider' => 'outlook',
            'action' => 'automatic_reschedule_sync',
            'status' => 'synced',
        ]);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultation->id,
            'provider' => 'outlook',
            'action' => 'automatic_reschedule_sync',
            'request_payload->subject' => $consultation->booking_number.' - '.$consultation->type->name,
            'response_payload->id' => 'rescheduled-outlook-event-id',
        ]);
        $this->assertDatabaseHas('external_calendar_events', [
            'provider' => 'outlook',
            'external_id' => 'consultation-'.$consultation->uuid,
            'application' => 'legal',
            'is_busy' => true,
        ]);
    }
}
