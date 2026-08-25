<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use App\Models\IntegrationLog;
use App\Models\PaymentRequest;
use App\Models\QuestionnaireSubmission;
use App\Services\AdminConclusionNotificationService;
use App\Services\AdminPaymentNotificationService;
use App\Services\AdminZoomNotificationService;
use App\Services\AvailabilityService;
use App\Services\Integrations\OutlookCalendarClient;
use App\Services\Integrations\ZoomClient;
use App\Services\PaymentReconciliationService;
use App\Services\QuestionnairePdfService;
use App\Services\QuestionnaireWorkflowService;
use App\Services\RescheduleNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ConsultationAdminController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $selectedApplication = $user->isGlobalAdmin()
            ? $request->query('application')
            : $user->application;

        $query = Consultation::query()
            ->with(['type', 'participants', 'paymentRequests'])
            ->when($selectedApplication, fn ($query, $application) => $query->where('application', $application))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('q'), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('booking_number', 'like', "%{$search}%")
                        ->orWhere('primary_first_name', 'like', "%{$search}%")
                        ->orWhere('primary_last_name', 'like', "%{$search}%")
                        ->orWhere('primary_email', 'like', "%{$search}%");
                });
            })
            ->when($request->query('date_from'), fn ($query, $date) => $query->whereDate('starts_at', '>=', $date))
            ->when($request->query('date_to'), fn ($query, $date) => $query->whereDate('starts_at', '<=', $date));

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

        return view('admin.consultations.index', compact('consultations', 'selectedApplication'));
    }

    public function show(Consultation $consultation, PaymentReconciliationService $payments)
    {
        $this->authorizeConsultation($consultation);
        $payments->syncConsultation($consultation);

        $consultation->load([
            'type',
            'professional',
            'participants',
            'paymentRequests.participant',
            'integrationLogs',
            'questionnaireSubmissions.participant',
        ]);

        $paymentRequests = $consultation->paymentRequests;
        $paymentGatewayActivities = IntegrationLog::query()
            ->where('provider', 'converge')
            ->where('loggable_type', PaymentRequest::class)
            ->whereIn('loggable_id', $paymentRequests->pluck('id'))
            ->where(function ($query) {
                $query->where('action', '!=', 'payment_status_sync')
                    ->orWhere('status', '!=', 'skipped');
            })
            ->latest()
            ->get()
            ->map(fn (IntegrationLog $log) => [
                'log' => $log,
                'payment' => $paymentRequests->firstWhere('id', $log->loggable_id),
            ])
            ->filter(fn (array $activity) => $activity['payment'] !== null)
            ->values();

        return view('admin.consultations.show', [
            'consultation' => $consultation,
            'paymentGatewayActivities' => $paymentGatewayActivities,
            'repeatFreeIntroParticipants' => $this->repeatFreeIntroParticipants($consultation),
        ]);
    }

    public function downloadQuestionnairePdf(Consultation $consultation, QuestionnaireSubmission $submission, QuestionnairePdfService $pdf)
    {
        $this->authorizeConsultation($consultation);
        abort_unless($submission->consultation_id === $consultation->id, 404);
        abort_unless($submission->status === 'submitted', 404);

        return $pdf->download($submission);
    }

    public function sendReminder(Consultation $consultation, AdminPaymentNotificationService $notifications, PaymentReconciliationService $payments)
    {
        $this->authorizeConsultation($consultation);
        $payments->syncConsultation($consultation);
        $sent = $notifications->sendPaymentReminders($consultation);

        return back()->with('status', $sent > 0
                ? "{$sent} payment reminder email(s) sent."
                : 'No unpaid payment requests found for this consultation.'
        );
    }

    public function sendPaymentLinks(Consultation $consultation, AdminPaymentNotificationService $notifications, PaymentReconciliationService $payments)
    {
        $this->authorizeConsultation($consultation);
        $payments->syncConsultation($consultation);
        $sent = $notifications->sendPaymentLinks($consultation);

        return back()->with('status', $sent > 0
                ? "{$sent} payment link email(s) sent."
                : 'No unpaid payment requests found for this consultation.'
        );
    }

    public function resendZoomLink(Consultation $consultation, AdminZoomNotificationService $notifications)
    {
        $this->authorizeConsultation($consultation);
        $sent = $notifications->resendZoomLink($consultation);

        return back()->with('status', $sent > 0
                ? "{$sent} Zoom meeting link email(s) sent."
                : 'Zoom meeting link is not available or no email recipients were found.'
        );
    }

    public function regenerateZoomLink(Consultation $consultation, ZoomClient $zoom)
    {
        $this->authorizeConsultation($consultation);
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
            'zoom_join_url' => $meeting['join_url'],
            'status' => 'scheduled',
        ]);

        $consultation->integrationLogs()->create([
            'provider' => 'zoom',
            'action' => 'manual_regenerate_meeting',
            'status' => 'generated',
            'response_payload' => $meeting,
            'message' => 'Zoom meeting link regenerated from admin panel.',
        ]);

        return back()->with('status', 'Zoom meeting link regenerated.');
    }

    public function cancel(Consultation $consultation, OutlookCalendarClient $outlook)
    {
        $this->authorizeConsultation($consultation);
        $consultation->update([
            'status' => 'cancelled',
        ]);

        $consultation->integrationLogs()->create([
            'provider' => 'admin',
            'action' => 'manual_cancel',
            'status' => 'cancelled',
            'message' => 'Consultation cancelled from admin panel.',
        ]);

        if (config('services.outlook.enabled')) {
            try {
                $outlook->deleteConsultationEvent($consultation);
                $sync = $outlook->syncAllConsultations();
                $consultation->integrationLogs()->create([
                    'provider' => 'outlook',
                    'action' => 'automatic_cancel_delete',
                    'status' => 'deleted',
                    'response_payload' => $sync,
                    'message' => 'Outlook event deleted and all consultation events reconciled after cancellation.',
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
        AdminZoomNotificationService $zoomNotifications,
        QuestionnaireWorkflowService $questionnaires,
        RescheduleNotificationService $rescheduleNotifications
    ) {
        $this->authorizeConsultation($consultation);
        $data = $request->validate([
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
        ]);

        $timezone = $consultation->timezone ?: config('app.booking_timezone');
        $startsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['starts_at'], $timezone);
        $type = $consultation->type;

        if ($consultation->status === 'completed') {
            return back()->withInput()->with('error', 'Completed consultations cannot be rescheduled.');
        }

        try {
            $availability->assertAvailable($type, $startsAt, $consultation->professional_id, $consultation->id);
        } catch (\DomainException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        $consultation->update([
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes($type->duration_minutes),
            'status' => $this->statusAfterReschedule($consultation, $questionnaires),
        ]);

        $consultation->integrationLogs()->create([
            'provider' => 'admin',
            'action' => 'manual_reschedule',
            'status' => 'rescheduled',
            'request_payload' => ['starts_at' => $startsAt->toIso8601String()],
            'message' => 'Consultation rescheduled from admin panel.',
        ]);

        $isReadyForMeetingRelease = $questionnaires->isReadyForMeetingRelease($consultation->refresh()->load(['type', 'participants', 'questionnaireSubmissions']));

        if ($consultation->consultation_mode === 'online' && $isReadyForMeetingRelease) {
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
        } elseif ($consultation->consultation_mode === 'online') {
            if (filled($consultation->zoom_meeting_id)) {
                try {
                    $zoom->deleteMeeting($consultation->zoom_meeting_id);
                } catch (\RuntimeException $exception) {
                    $consultation->integrationLogs()->create([
                        'provider' => 'zoom',
                        'action' => 'manual_reschedule_delete_pending_meeting',
                        'status' => 'failed',
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            $consultation->update([
                'zoom_meeting_id' => null,
                'zoom_join_url' => null,
            ]);

            $consultation->integrationLogs()->create([
                'provider' => 'zoom',
                'action' => 'manual_reschedule_zoom_link',
                'status' => 'skipped',
                'message' => 'Zoom link regeneration was skipped because required questionnaire steps are not complete.',
            ]);
        }

        if (! $isReadyForMeetingRelease) {
            $rescheduleNotifications->sendPendingNextSteps($consultation->refresh(), 'manual_reschedule');
        }

        if (config('services.outlook.enabled') && $isReadyForMeetingRelease) {
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
        } elseif (config('services.outlook.enabled')) {
            $consultation->integrationLogs()->create([
                'provider' => 'outlook',
                'action' => 'automatic_reschedule_sync',
                'status' => 'skipped',
                'message' => 'Outlook sync was skipped because required questionnaire steps are not complete.',
            ]);
        }

        return back()->with('status', 'Consultation rescheduled.'.($zoomMailWarning ?? ''));
    }

    public function syncOutlook(Consultation $consultation, OutlookCalendarClient $outlook, PaymentReconciliationService $payments)
    {
        $this->authorizeConsultation($consultation);
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

        if ($outlookEvent === null) {
            $sync = $outlook->syncAllConsultations();
            $consultation->integrationLogs()->create([
                'provider' => 'outlook',
                'action' => 'manual_booking_sync',
                'status' => 'deleted',
                'response_payload' => $sync,
                'message' => 'Inactive consultation event removed and all Outlook events reconciled.',
            ]);

            return back()->with('status', 'This consultation is inactive. Its Outlook event was removed and all events were synced.');
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

    public function updateStatuses(Request $request, Consultation $consultation, OutlookCalendarClient $outlook, AdminConclusionNotificationService $conclusions)
    {
        $this->authorizeConsultation($consultation);
        $data = $request->validate([
            'status' => ['required', 'string', 'in:draft,pending,payment_pending,paid,scheduled,rescheduled,in_progress,completed,cancelled,overdue'],
            'payment_status' => ['required', 'string', 'in:pending,partially_paid,paid,failed,refunded'],
        ]);

        $oldStatus = $consultation->status;
        $oldPaymentStatus = $consultation->payment_status;

        $consultation->update([
            'status' => $data['status'],
            'payment_status' => $data['payment_status'],
            'confirmed_at' => $data['payment_status'] === 'paid'
                ? ($consultation->confirmed_at ?: now())
                : $consultation->confirmed_at,
        ]);

        $consultation->integrationLogs()->create([
            'provider' => 'admin',
            'action' => 'manual_status_update',
            'status' => 'updated',
            'request_payload' => [
                'old_status' => $oldStatus,
                'status' => $data['status'],
                'old_payment_status' => $oldPaymentStatus,
                'payment_status' => $data['payment_status'],
            ],
            'message' => 'Consultation and payment statuses updated from admin panel.',
        ]);

        $statusChanged = $oldStatus !== $data['status']
            || $oldPaymentStatus !== $data['payment_status'];

        if ($statusChanged && config('services.outlook.enabled')) {
            try {
                $sync = $outlook->syncAllConsultations();
                $consultation->integrationLogs()->create([
                    'provider' => 'outlook',
                    'action' => 'automatic_status_change_sync',
                    'status' => 'synced',
                    'response_payload' => $sync,
                    'message' => 'All Outlook consultation events reconciled after a status change.',
                ]);
            } catch (\DomainException|\RuntimeException $exception) {
                return back()->with('error', 'Statuses updated, but Outlook sync failed: '.$exception->getMessage());
            }
        }

        if ($oldStatus !== 'completed' && $data['status'] === 'completed') {
            $conclusions->sendConclusion($consultation->refresh());
        }

        return back()->with('status', 'Consultation statuses updated.');
    }

    private function statusAfterReschedule(Consultation $consultation, QuestionnaireWorkflowService $questionnaires): string
    {
        if (($consultation->status === 'scheduled' || filled($consultation->zoom_join_url))
            && $questionnaires->isReadyForMeetingRelease($consultation)) {
            return 'scheduled';
        }

        if ($consultation->payment_status === 'paid') {
            return 'paid';
        }

        return 'payment_pending';
    }

    private function authorizeConsultation(Consultation $consultation): void
    {
        abort_unless(auth()->user()?->canAccessApplication($consultation->application), 403);
    }

    private function repeatFreeIntroParticipants(Consultation $consultation): Collection
    {
        $contacts = $consultation->participants
            ->mapWithKeys(fn (ConsultationParticipant $participant) => [
                $participant->id => [
                    'email' => $this->normalizedEmail($participant->email),
                    'phone' => $this->normalizedPhone($participant->phone_country, $participant->phone),
                ],
            ])
            ->filter(fn (array $contact) => filled($contact['email']) || filled($contact['phone']));

        if ($contacts->isEmpty()) {
            return collect();
        }

        $priorParticipants = ConsultationParticipant::query()
            ->with(['consultation.type'])
            ->where('consultation_id', '!=', $consultation->id)
            ->whereHas('consultation', function ($query) use ($consultation) {
                $query->where('status', 'completed')
                    ->whereHas('type', fn ($query) => $query->where('slug', 'socal-free-intro-call'));

                if ($consultation->starts_at) {
                    $query->where('starts_at', '<', $consultation->starts_at);
                }
            })
            ->get();

        return $contacts
            ->map(function (array $contact) use ($priorParticipants) {
                return $priorParticipants
                    ->filter(function (ConsultationParticipant $participant) use ($contact) {
                        $emailMatches = filled($contact['email'])
                            && $this->normalizedEmail($participant->email) === $contact['email'];
                        $phoneMatches = filled($contact['phone'])
                            && $this->normalizedPhone($participant->phone_country, $participant->phone) === $contact['phone'];

                        return $emailMatches || $phoneMatches;
                    })
                    ->sortByDesc(fn (ConsultationParticipant $participant) => $participant->consultation?->starts_at)
                    ->first();
            })
            ->filter();
    }

    private function normalizedEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    private function normalizedPhone(?string $country, ?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $country.(string) $phone);

        return $digits === '' ? null : $digits;
    }
}
