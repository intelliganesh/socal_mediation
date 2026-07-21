<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Services\AdminPaymentNotificationService;
use App\Services\AdminZoomNotificationService;
use App\Services\AvailabilityService;
use App\Services\Integrations\OutlookCalendarClient;
use App\Services\Integrations\ZoomClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ConsultationAdminController extends Controller
{
    public function index(Request $request)
    {
        $consultations = Consultation::query()
            ->with(['type', 'participants', 'paymentRequests'])
            ->when($request->query('application'), fn($query, $application) => $query->where('application', $application))
            ->when($request->query('status'), fn($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('admin.consultations.index', compact('consultations'));
    }

    public function show(Consultation $consultation)
    {
        return view('admin.consultations.show', [
            'consultation' => $consultation->load([
                'type',
                'legalService',
                'professional',
                'participants',
                'paymentRequests.participant',
                'integrationLogs',
            ]),
        ]);
    }

    public function sendReminder(Consultation $consultation, AdminPaymentNotificationService $notifications)
    {
        $sent = $notifications->sendPaymentReminders($consultation);

        return back()->with('status', $sent > 0
                ? "{$sent} payment reminder email(s) sent."
                : 'No unpaid payment requests found for this consultation.'
        );
    }

    public function sendPaymentLinks(Consultation $consultation, AdminPaymentNotificationService $notifications)
    {
        $sent = $notifications->sendPaymentLinks($consultation);

        return back()->with('status', $sent > 0
                ? "{$sent} payment link email(s) sent."
                : 'No unpaid payment requests found for this consultation.'
        );
    }

    public function resendZoomLink(Consultation $consultation, AdminZoomNotificationService $notifications)
    {
        $sent = $notifications->resendZoomLink($consultation);

        return back()->with('status', $sent > 0
                ? "{$sent} Zoom meeting link email(s) sent."
                : 'Zoom meeting link is not available or no email recipients were found.'
        );
    }

    public function regenerateZoomLink(Consultation $consultation, ZoomClient $zoom)
    {
        if ($consultation->consultation_mode !== 'online') {
            return back()->with('status', 'Zoom meeting links are only available for online consultations.');
        }

        $meeting = $zoom->createMeeting($consultation);
        $consultation->update([
            'zoom_meeting_id' => $meeting['id'],
            'zoom_join_url'   => $meeting['join_url'],
        ]);

        $consultation->integrationLogs()->create([
            'provider'         => 'zoom',
            'action'           => 'manual_regenerate_meeting',
            'status'           => 'generated',
            'response_payload' => $meeting,
            'message'          => 'Zoom meeting link regenerated from admin panel.',
        ]);

        return back()->with('status', 'Zoom meeting link regenerated.');
    }

    public function cancel(Consultation $consultation)
    {
        $consultation->update([
            'status'         => 'cancelled',
            'payment_status' => 'cancelled',
        ]);

        $consultation->integrationLogs()->create([
            'provider' => 'admin',
            'action'   => 'manual_cancel',
            'status'   => 'cancelled',
            'message'  => 'Consultation cancelled from admin panel.',
        ]);

        return back()->with('status', 'Consultation cancelled.');
    }

    public function reschedule(Request $request, Consultation $consultation, AvailabilityService $availability)
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
        ]);

        $timezone = $consultation->timezone ?: config('app.booking_timezone', 'America/Los_Angeles');
        $startsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['starts_at'], $timezone);
        $type     = $consultation->type;

        try {
            $availability->assertAvailable($type, $startsAt, $consultation->professional_id, $consultation->id);
        } catch (\DomainException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        $consultation->update([
            'starts_at' => $startsAt,
            'ends_at'   => $startsAt->addMinutes($type->duration_minutes),
            'status'    => $consultation->payment_status === 'paid' ? 'paid' : 'scheduled',
        ]);

        $consultation->integrationLogs()->create([
            'provider'        => 'admin',
            'action'          => 'manual_reschedule',
            'status'          => 'rescheduled',
            'request_payload' => ['starts_at' => $startsAt->toIso8601String()],
            'message'         => 'Consultation rescheduled from admin panel.',
        ]);

        return back()->with('status', 'Consultation rescheduled.');
    }

    public function syncOutlook(Consultation $consultation, OutlookCalendarClient $outlook)
    {
        if ($consultation->starts_at === null || $consultation->ends_at === null) {
            return back()->with('status', 'Schedule this booking before syncing it to Outlook.');
        }

        $outlook->syncConsultation($consultation);
        $consultation->integrationLogs()->create([
            'provider' => 'outlook',
            'action'   => 'manual_booking_sync',
            'status'   => 'synced',
            'message'  => 'Booking synced to Outlook from admin panel.',
        ]);

        return back()->with('status', 'This booking was synced to Outlook.');
    }
}
