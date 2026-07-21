<?php

namespace Tests\Feature;

use App\Mail\ConsultationPaymentLinkMail;
use App\Mail\ConsultationZoomLinkMail;
use App\Models\Consultation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame('cancelled', $scheduledConsultation->payment_status);
    }
}
