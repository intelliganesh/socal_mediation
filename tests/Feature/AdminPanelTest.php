<?php

namespace Tests\Feature;

use App\Mail\ConsultationPaymentLinkMail;
use App\Mail\ConsultationZoomLinkMail;
use App\Models\Consultation;
use App\Models\ExternalCalendarEvent;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\Integrations\OutlookCalendarClient;
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

    public function test_admin_consultation_show_reconciles_pending_converge_payments(): void
    {
        $this->seed();
        Mail::fake();
        config([
            'services.converge.payment_sync_enabled' => true,
            'services.converge.mode' => 'sandbox',
            'services.converge.sandbox_base_url' => 'https://api.demo.convergepay.com',
            'services.converge.account_id' => 'account-id',
            'services.converge.user_id' => 'api-user',
            'services.converge.pin' => 'secret-pin',
        ]);

        Http::fake([
            'api.demo.convergepay.com/VirtualMerchantDemo/processxml.do' => Http::response(
                '<txn><ssl_result>0</ssl_result><ssl_result_message>APPROVAL</ssl_result_message><ssl_txn_id>admin-detail-txn</ssl_txn_id></txn>',
                200
            ),
        ]);

        $admin = User::where('email', 'admin@socal.test')->firstOrFail();
        $consultation = Consultation::where('booking_number', 'SAMPLE-03')->firstOrFail();
        $paymentIds = $consultation->paymentRequests()->where('status', 'pending')->pluck('id');

        $this->assertNotEmpty($paymentIds);

        $this->actingAs($admin)
            ->get(route('admin.consultations.show', $consultation))
            ->assertOk();

        foreach ($paymentIds as $paymentId) {
            $this->assertDatabaseHas('payment_requests', [
                'id' => $paymentId,
                'status' => 'paid',
            ]);
            $this->assertDatabaseHas('integration_logs', [
                'loggable_type' => PaymentRequest::class,
                'loggable_id' => $paymentId,
                'provider' => 'converge',
                'action' => 'payment_status_sync',
                'status' => 'paid',
            ]);
        }
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

        ExternalCalendarEvent::create([
            'provider' => 'outlook',
            'external_id' => 'socal-stale-busy',
            'application' => 'socal',
            'title' => 'Removed Outlook busy event',
            'starts_at' => now(config('app.booking_timezone'))->addDays(7)->setTime(10, 0),
            'ends_at' => now(config('app.booking_timezone'))->addDays(7)->setTime(11, 0),
            'is_busy' => true,
        ]);

        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response(['access_token' => 'graph-token'], 200),
            'graph.microsoft.com/v1.0/users/legal%40example.com/calendars/legal-calendar/events/stale-event-id' => Http::response(null, 204),
            'graph.microsoft.com/v1.0/users/socal%40example.com/calendars/socal-calendar/calendarView*' => Http::response(['value' => []], 200),
            'graph.microsoft.com/v1.0/users/legal%40example.com/calendars/legal-calendar/calendarView*' => Http::response(['value' => []], 200),
            'graph.microsoft.com/v1.0/users/legal%40example.com/calendars/legal-calendar/events' => Http::response([
                'id' => 'future-consultation-legal-outlook-id',
                'webLink' => 'https://outlook.office.com/future-consultation-legal',
            ], 201),
            'graph.microsoft.com/v1.0/users/socal%40example.com/calendars/socal-calendar/events' => Http::response([
                'id' => 'future-consultation-socal-outlook-id',
                'webLink' => 'https://outlook.office.com/future-consultation-socal',
            ], 201),
        ]);

        $admin = User::where('email', 'admin@socal.test')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.calendar.sync'))
            ->assertRedirect()
            ->assertSessionHas('status', 'Outlook sync completed. 0 busy event(s) refreshed, 1 future consultation(s) synced, 1 stale consultation event(s) deleted.');

        foreach (['socal', 'legal'] as $application) {
            $this->assertDatabaseHas('external_calendar_events', [
                'provider' => 'outlook',
                'external_id' => 'consultation-'.$consultation->id.'-'.$application,
                'application' => $application,
                'is_busy' => true,
            ]);
        }
        $this->assertDatabaseMissing('external_calendar_events', [
            'provider' => 'outlook',
            'external_id' => 'consultation-stale-future',
        ]);
        $this->assertDatabaseMissing('external_calendar_events', [
            'provider' => 'outlook',
            'external_id' => 'socal-stale-busy',
        ]);
    }

    public function test_calendar_sync_deletes_marked_outlook_event_when_consultation_and_tracking_are_missing(): void
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
            'services.outlook.legal_user_id' => 'legal@example.com',
            'services.outlook.legal_calendar_id' => 'legal-calendar',
        ]);

        $missingConsultationId = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response(['access_token' => 'graph-token'], 200),
            'graph.microsoft.com/v1.0/users/socal%40example.com/calendars/socal-calendar/calendarView*' => Http::response(['value' => [[
                'id' => 'orphan-application-event',
                'subject' => 'Deleted consultation',
                'showAs' => 'busy',
                'body' => ['content' => 'Consultation booking\n\nSMC-CONSULTATION:'.$missingConsultationId],
            ]]], 200),
            'graph.microsoft.com/v1.0/users/socal%40example.com/calendars/socal-calendar/events/orphan-application-event' => Http::response(null, 204),
            'graph.microsoft.com/v1.0/users/legal%40example.com/calendars/legal-calendar/calendarView*' => Http::response(['value' => []], 200),
        ]);

        app(OutlookCalendarClient::class)->syncCurrentWindow();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://graph.microsoft.com/v1.0/users/socal%40example.com/calendars/socal-calendar/events/orphan-application-event');

        $this->assertDatabaseMissing('external_calendar_events', [
            'provider' => 'outlook',
            'external_id' => 'orphan-application-event',
        ]);
    }

    public function test_admin_can_update_consultation_and_payment_statuses_from_detail_page(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@socal.test')->firstOrFail();
        $consultation = Consultation::where('booking_number', 'SAMPLE-08')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.consultations.show', $consultation))
            ->assertOk()
            ->assertSee('Status Controls')
            ->assertSee('Update Statuses');

        $this->actingAs($admin)
            ->post(route('admin.consultations.statuses', $consultation), [
                'status' => 'completed',
                'payment_status' => 'paid',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Consultation statuses updated.');

        $this->assertDatabaseHas('consultations', [
            'id' => $consultation->id,
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultation->id,
            'provider' => 'admin',
            'action' => 'manual_status_update',
            'status' => 'updated',
        ]);
    }

    public function test_cancelling_from_status_controls_deletes_outlook_event_and_manual_sync_cannot_recreate_it(): void
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
        foreach (['socal', 'legal'] as $application) {
            ExternalCalendarEvent::create([
                'provider' => 'outlook',
                'external_id' => 'consultation-'.$consultation->id.'-'.$application,
                'application' => $application,
                'title' => 'Cancelled consultation',
                'starts_at' => $consultation->starts_at,
                'ends_at' => $consultation->ends_at,
                'is_busy' => true,
                'metadata' => [
                    'consultation_uuid' => $consultation->id,
                    'outlook_event_id' => 'cancelled-'.$application.'-outlook-event-id',
                ],
            ]);
        }

        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response(['access_token' => 'graph-token'], 200),
            'graph.microsoft.com/v1.0/users/*/calendarView*' => Http::response(['value' => []], 200),
            'graph.microsoft.com/v1.0/users/*/events/cancelled-*-outlook-event-id' => Http::response(null, 204),
        ]);

        $admin = User::where('email', 'admin@socal.test')->firstOrFail();
        $this->actingAs($admin)
            ->post(route('admin.consultations.statuses', $consultation), [
                'status' => 'cancelled',
                'payment_status' => $consultation->payment_status,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Consultation statuses updated.');

        foreach (['socal', 'legal'] as $application) {
            $this->assertDatabaseMissing('external_calendar_events', [
                'provider' => 'outlook',
                'external_id' => 'consultation-'.$consultation->id.'-'.$application,
            ]);
            Http::assertSent(fn ($request) => $request->method() === 'DELETE'
                && str_ends_with($request->url(), '/events/cancelled-'.$application.'-outlook-event-id'));
        }

        $this->actingAs($admin)
            ->post(route('admin.consultations.sync-outlook', $consultation))
            ->assertRedirect()
            ->assertSessionHas('status', 'This consultation is inactive. Its Outlook event was removed and all events were synced.');

        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), 'graph.microsoft.com')
            && str_ends_with($request->url(), '/events'));
        $this->assertDatabaseHas('integration_logs', [
            'loggable_type' => Consultation::class,
            'loggable_id' => $consultation->id,
            'provider' => 'outlook',
            'action' => 'automatic_status_change_sync',
            'status' => 'synced',
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

    public function test_admin_can_view_payment_gateway_failure_details_and_reference(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@socal.test')->firstOrFail();
        $consultation = Consultation::where('booking_number', 'SAMPLE-03')->firstOrFail();
        $payment = $consultation->paymentRequests()->firstOrFail();
        $log = $payment->integrationLogs()->create([
            'provider' => 'converge',
            'action' => 'hosted_payment_session',
            'status' => 'failed',
            'message' => 'Converge rejected the request because the account is not enabled for Hosted Payments.',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.consultations.show', $consultation))
            ->assertOk()
            ->assertSee('Payment Gateway Activity')
            ->assertSee('PAY-'.$log->id)
            ->assertSee('Hosted Payment Session')
            ->assertSee('Converge rejected the request because the account is not enabled for Hosted Payments.');
    }

    public function test_admin_can_cancel_reschedule_and_regenerate_meeting_link(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@socal.test')->firstOrFail();
        $onlineConsultation = Consultation::where('booking_number', 'SAMPLE-04')->firstOrFail();
        $scheduledConsultation = Consultation::where('booking_number', 'SAMPLE-08')->firstOrFail();
        $oldZoomUrl = $onlineConsultation->zoom_join_url;

        config([
            'services.zoom.enabled' => true,
            'services.zoom.oauth_base_url' => 'https://zoom.test',
            'services.zoom.base_url' => 'https://api.zoom.test/v2',
            'services.zoom.account_id' => 'account-id',
            'services.zoom.client_id' => 'client-id',
            'services.zoom.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'zoom.test/oauth/token' => Http::response(['access_token' => 'zoom-token'], 200),
            'api.zoom.test/v2/users/me/meetings' => Http::response([
                'id' => '987654321',
                'join_url' => 'https://zoom.test/j/987654321',
                'start_url' => 'https://zoom.test/s/987654321',
            ], 201),
        ]);

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
            'services.outlook.legal_user_id' => 'legal@example.com',
            'services.outlook.legal_calendar_id' => 'legal-calendar',
        ]);

        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response(['access_token' => 'graph-token'], 200),
            'graph.microsoft.com/v1.0/users/socal%40example.com/calendars/socal-calendar/events' => Http::response([
                'id' => 'socal-outlook-event-id',
                'webLink' => 'https://outlook.office.com/socal-event',
            ], 201),
            'graph.microsoft.com/v1.0/users/legal%40example.com/calendars/legal-calendar/events' => Http::response([
                'id' => 'legal-outlook-event-id',
                'webLink' => 'https://outlook.office.com/legal-event',
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
            'response_payload->id' => 'socal-outlook-event-id',
        ]);

        foreach (['socal', 'legal'] as $application) {
            $this->assertDatabaseHas('external_calendar_events', [
                'provider' => 'outlook',
                'external_id' => 'consultation-'.$consultation->uuid.'-'.$application,
                'application' => $application,
                'is_busy' => true,
            ]);
        }
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
            'services.outlook.socal_user_id' => 'socal@example.com',
            'services.outlook.socal_calendar_id' => 'socal-calendar',
            'services.outlook.legal_user_id' => 'legal@example.com',
            'services.outlook.legal_calendar_id' => 'legal-calendar',
        ]);

        Http::fake([
            'login.microsoftonline.com/tenant-id/oauth2/v2.0/token' => Http::response(['access_token' => 'graph-token'], 200),
            'graph.microsoft.com/v1.0/users/socal%40example.com/calendars/socal-calendar/events' => Http::response([
                'id' => 'rescheduled-socal-outlook-event-id',
                'webLink' => 'https://outlook.office.com/rescheduled-socal-event',
            ], 201),
            'graph.microsoft.com/v1.0/users/legal%40example.com/calendars/legal-calendar/events' => Http::response([
                'id' => 'rescheduled-legal-outlook-event-id',
                'webLink' => 'https://outlook.office.com/rescheduled-legal-event',
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
            'response_payload->id' => 'rescheduled-legal-outlook-event-id',
        ]);
        foreach (['socal', 'legal'] as $application) {
            $this->assertDatabaseHas('external_calendar_events', [
                'provider' => 'outlook',
                'external_id' => 'consultation-'.$consultation->uuid.'-'.$application,
                'application' => $application,
                'is_busy' => true,
            ]);
        }
    }
}
