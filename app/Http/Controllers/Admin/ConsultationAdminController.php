<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Services\AdminPaymentNotificationService;
use App\Services\AdminZoomNotificationService;
use App\Services\AvailabilityService;
use App\Services\Integrations\OutlookCalendarClient;
use App\Services\Integrations\ZoomClient;
use App\Services\PaymentReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ConsultationAdminController extends Controller
{
    public function index(Request $request)
    {
        $query = Consultation::query()
            ->with(['type', 'participants', 'paymentRequests'])
            ->when($request->query('application'), fn($query, $application) => $query->where('application', $application))
            ->when($request->query('status'), fn($query, $status) => $query->where('status', $status))
            ->when($request->query('q'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('booking_number', 'like', "%{$search}%")
                        ->orWhere('primary_first_name', 'like', "%{$search}%")
                        ->orWhere('primary_last_name', 'like', "%{$search}%")
                        ->orWhere('primary_email', 'like', "%{$search}%");
                });
            })
            ->when($request->query('date_from'), fn($query, $date) => $query->whereDate('starts_at', '>=', $date))
            ->when($request->query('date_to'), fn($query, $date) => $query->whereDate('starts_at', '<=', $date));

        if ($request->boolean('export')) {
            return response()->streamDownload(function () use ($query) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['Consultation #', 'Client', 'Email', 'Application', 'Type', 'Date & Time', 'Status', 'Payment Status', 'Total Amount']);

                $query->latest()->chunk(100, function ($consultations) use ($handle) {
                    foreach ($consultations as $consultation) {
                        fputcsv($handle, [
                            $consultation->booking_number,
                            trim($consultation->primary_first_name.' '.$consultation->primary_last_name),
                            $consultation->primary_email,
                            $consultation->application,
                            $consultation->type?->name,
                            $consultation->starts_at?->format('M d, Y g:i A'),
                            $consultation->status,
                            $consultation->payment_status,
                            number_format($consultation->total_amount_cents / 100, 2, '.', ''),
                        ]);
                    }
                });

                fclose($handle);
            }, 'consultations.csv', ['Content-Type' => 'text/csv']);
        }

        $consultations = $query->latest()
            ->paginate(10)
            ->withQueryString();

        return view('admin.consultations.index', compact('consultations'));
    }

    public function show(Consultation $consultation, PaymentReconciliationService $payments)
    {
        $payments->syncConsultation($consultation);

        return view('admin.consultations.show', [
            'consultation' => $consultation->load([
                'type',
                'professional',
                'participants',
                'paymentRequests.participant',
                'integrationLogs',
            ]),
        ]);
    }

    public function sendReminder(Consultation $consultation, AdminPaymentNotificationService $notifications, PaymentReconciliationService $payments)
    {
        $payments->syncConsultation($consultation);
        $sent = $notifications->sendPaymentReminders($consultation);

        return back()->with('status', $sent > 0
                ? "{$sent} payment reminder email(s) sent."
                : 'No unpaid payment requests found for this consultation.'
        );
    }

    public function sendPaymentLinks(Consultation $consultation, AdminPaymentNotificationService $notifications, PaymentReconciliationService $payments)
    {
        $payments->syncConsultation($consultation);
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

        try {
            $meeting = $zoom->createMeeting($consultation);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $consultation->update([
            'zoom_meeting_id' => $meeting['id'],
            'zoom_join_url'   => $meeting['join_url'],
            'status' => 'scheduled',
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

    public function cancel(Consultation $consultation, OutlookCalendarClient $outlook)
    {
        $consultation->update([
            'status' => 'cancelled',
        ]);

        $consultation->integrationLogs()->create([
            'provider' => 'admin',
            'action'   => 'manual_cancel',
            'status'   => 'cancelled',
            'message'  => 'Consultation cancelled from admin panel.',
        ]);

        if (config('services.outlook.enabled')) {
            try {
                $outlook->deleteConsultationEvent($consultation);
                $consultation->integrationLogs()->create([
                    'provider' => 'outlook',
                    'action' => 'automatic_cancel_delete',
                    'status' => 'deleted',
                    'message' => 'Outlook event deleted after consultation cancellation.',
                ]);
            } catch (\DomainException|\RuntimeException $exception) {
                return back()->with('error', 'Consultation cancelled, but Outlook event deletion failed: '.$exception->getMessage());
            }
        }

        return back()->with('status', 'Consultation cancelled.');
    }

    public function reschedule(
        Request $request,
        Consultation $consultation,
        AvailabilityService $availability,
        OutlookCalendarClient $outlook,
        ZoomClient $zoom,
        AdminZoomNotificationService $zoomNotifications
    )
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
        ]);

        $timezone = $consultation->timezone ?: config('app.booking_timezone');
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
            'status'    => $this->statusAfterReschedule($consultation),
        ]);

        $consultation->integrationLogs()->create([
            'provider'        => 'admin',
            'action'          => 'manual_reschedule',
            'status'          => 'rescheduled',
            'request_payload' => ['starts_at' => $startsAt->toIso8601String()],
            'message'         => 'Consultation rescheduled from admin panel.',
        ]);

        if ($consultation->consultation_mode === 'online') {
            try {
                $meeting = $zoom->createMeeting($consultation->refresh()->load(['type']));

                $consultation->update([
                    'zoom_meeting_id' => $meeting['id'],
                    'zoom_join_url' => $meeting['join_url'],
                    'status' => 'scheduled',
                ]);

                $consultation->integrationLogs()->create([
                    'provider' => 'zoom',
                    'action' => 'manual_reschedule_meeting',
                    'status' => 'generated',
                    'response_payload' => $meeting,
                    'message' => 'Zoom meeting link regenerated after reschedule.',
                ]);

                $sent = $zoomNotifications->sendZoomLink($consultation->refresh(), 'manual_reschedule_zoom_link');

                if ($sent === 0) {
                    $zoomMailWarning = ' Zoom link was regenerated, but no Zoom emails were sent.';
                }
            } catch (\RuntimeException $exception) {
                return back()->with('error', 'Consultation rescheduled, but Zoom link update failed: '.$exception->getMessage());
            }
        }

        if (config('services.outlook.enabled')) {
            try {
                $syncedConsultation = $consultation->refresh()->load(['type', 'professional']);
                $outlookEvent = $outlook->recreateConsultationEvent($syncedConsultation, 'automatic_reschedule_sync');

                $consultation->integrationLogs()->create([
                    'provider' => 'outlook',
                    'action' => 'automatic_reschedule_sync',
                    'status' => 'synced',
                    'request_payload' => $outlook->consultationEventPayload($syncedConsultation),
                    'response_payload' => $outlookEvent->metadata['outlook_response'] ?? $outlookEvent->metadata,
                    'message' => 'Booking synced to Outlook after reschedule.',
                ]);
            } catch (\DomainException|\RuntimeException $exception) {
                return back()->with('error', 'Consultation rescheduled, but Outlook sync failed: '.$exception->getMessage());
            }
        }

        return back()->with('status', 'Consultation rescheduled.'.($zoomMailWarning ?? ''));
    }

    public function syncOutlook(Consultation $consultation, OutlookCalendarClient $outlook, PaymentReconciliationService $payments)
    {
        $payments->syncConsultation($consultation);

        if ($consultation->starts_at === null || $consultation->ends_at === null) {
            return back()->with('status', 'Schedule this booking before syncing it to Outlook.');
        }

        try {
            $syncedConsultation = $consultation->load(['type', 'professional']);
            $outlookEvent = $outlook->syncConsultation($syncedConsultation);
        } catch (\DomainException|\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $consultation->integrationLogs()->create([
            'provider' => 'outlook',
            'action' => 'manual_booking_sync',
            'status' => 'synced',
            'request_payload' => $outlook->consultationEventPayload($syncedConsultation),
            'response_payload' => $outlookEvent->metadata['outlook_response'] ?? $outlookEvent->metadata,
            'message' => 'Booking synced to Outlook from admin panel.',
        ]);

        return back()->with('status', 'This booking was synced to Outlook.');
    }

    private function statusAfterReschedule(Consultation $consultation): string
    {
        if ($consultation->status === 'scheduled' || filled($consultation->zoom_join_url)) {
            return 'scheduled';
        }

        if ($consultation->payment_status === 'paid') {
            return 'paid';
        }

        return 'payment_pending';
    }
}
