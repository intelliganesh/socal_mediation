<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CompleteConsultationRequest;
use App\Http\Requests\Api\CreateDraftConsultationRequest;
use App\Http\Resources\ConsultationResource;
use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use App\Models\ConsultationType;
use App\Services\ApiResponse;
use App\Services\AvailabilityService;
use App\Services\BookingDateTimeService;
use App\Services\ConsultationCompletionService;
use App\Services\ConsultationDraftService;
use App\Services\ConsultationRescheduleService;
use App\Services\FreeIntroCallWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ConsultationController extends Controller
{
    #[OA\Post(
        path: '/v1/consultations/draft',
        tags: ['Consultations'],
        summary: 'Create or save a draft consultation',
        description: 'Creates the draft after consultation type selection. Optional fields can be sent from the details form to preserve partially completed data while the status remains draft.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['consultation_type_id'], properties: [
            new OA\Property(property: 'consultation_type_id', type: 'integer', example: 3),
            new OA\Property(property: 'legal_service_name', type: 'string', nullable: true, example: 'Business, Payment & Contract Disputes'),
            new OA\Property(property: 'consultation_mode', type: 'string', nullable: true, enum: ['online', 'offline', 'phone'], example: 'online'),
            new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Briefly describe the legal guidance or assistance needed.'),
            new OA\Property(property: 'referral_source', type: 'string', nullable: true, example: 'Google'),
            new OA\Property(property: 'referral_source_others', type: 'string', nullable: true, description: 'Used only when referral_source is Other Referral.', example: 'Friend at local business group'),
            new OA\Property(property: 'primary_client', ref: '#/components/schemas/ParticipantPayload'),
            new OA\Property(property: 'participants', type: 'array', nullable: true, items: new OA\Items(ref: '#/components/schemas/ParticipantPayload')),
        ])),
        responses: [new OA\Response(response: 201, description: 'Draft consultation created', content: new OA\JsonContent(properties: [
            new OA\Property(property: 'success', type: 'boolean', example: true),
            new OA\Property(property: 'message', type: 'string', example: 'Draft consultation created.'),
            new OA\Property(property: 'data', ref: '#/components/schemas/Consultation'),
        ]))]
    )]
    public function store(CreateDraftConsultationRequest $request, ConsultationDraftService $drafts)
    {
        $type = ConsultationType::findOrFail($request->integer('consultation_type_id'));

        try {
            $consultation = $drafts->createDraft($type, $request->validated());
        } catch (\DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(new ConsultationResource($consultation), 'Draft consultation created.', 201);
    }

    #[OA\Get(
        path: '/v1/consultations/{consultation}',
        tags: ['Consultations'],
        summary: 'Get a consultation by UUID',
        parameters: [
            new OA\Parameter(name: 'consultation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Consultation detail', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'OK'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Consultation'),
            ])),
            new OA\Response(response: 404, description: 'Consultation not found'),
        ]
    )]
    public function show(Consultation $consultation)
    {
        return ApiResponse::success(new ConsultationResource(
            $consultation->load(['type', 'professional', 'participants', 'paymentRequests', 'questionnaireSubmissions'])
        ));
    }

    #[OA\Post(
        path: '/v1/consultations/{consultation}/complete',
        tags: ['Consultations', 'Payments'],
        summary: 'Complete consultation details, schedule the slot, and send payment links',
        description: 'Send only schedule and payment fields when client details were already saved on the draft. Any client/detail fields included here override the draft values before payment links are created.',
        parameters: [
            new OA\Parameter(name: 'consultation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['starts_at', 'payment_mode'], properties: [
            new OA\Property(property: 'legal_service_name', type: 'string', nullable: true, example: 'Business, Payment & Contract Disputes'),
            new OA\Property(property: 'consultation_mode', type: 'string', enum: ['online', 'offline', 'phone'], example: 'online'),
            new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Need help reviewing a dispute before mediation.'),
            new OA\Property(property: 'referral_source', type: 'string', nullable: true, example: 'Google'),
            new OA\Property(property: 'referral_source_others', type: 'string', nullable: true, description: 'Used only when referral_source is Other Referral.', example: 'Friend at local business group'),
            new OA\Property(property: 'primary_client', ref: '#/components/schemas/ParticipantPayload'),
            new OA\Property(property: 'participants', type: 'array', items: new OA\Items(ref: '#/components/schemas/ParticipantPayload')),
            new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', example: '2026-08-14T09:00:00-07:00'),
            new OA\Property(property: 'professional_id', type: 'integer', nullable: true, example: 1),
            new OA\Property(property: 'timezone', type: 'string', nullable: true, example: 'America/Los_Angeles'),
            new OA\Property(property: 'payment_mode', type: 'string', enum: ['full', 'split'], example: 'split'),
            new OA\Property(property: 'payment_method', type: 'string', nullable: true, enum: ['card', 'ach'], example: 'card'),
            new OA\Property(
                property: 'payment_participant_emails',
                type: 'array',
                nullable: true,
                description: 'For split payments, omit this field to split across all participants, or pass selected participant emails.',
                items: new OA\Items(type: 'string', format: 'email'),
                example: ['client@example.com', 'other@example.com']
            ),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Consultation completed and payment links created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Consultation completed and payment links sent.'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Consultation'),
            ])),
            new OA\Response(response: 422, description: 'Validation failed, invalid service name, unavailable slot, or invalid payment split'),
        ]
    )]
    public function complete(CompleteConsultationRequest $request, Consultation $consultation, ConsultationCompletionService $completion)
    {
        try {
            $consultation = $completion->complete($consultation, $request->validated());
        } catch (\DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(
            new ConsultationResource($consultation->loadMissing(['type', 'professional', 'participants', 'paymentRequests', 'questionnaireSubmissions'])),
            'Consultation completed and payment links sent.'
        );
    }

    #[OA\Post(
        path: '/v1/consultations/{consultation}/reschedule',
        tags: ['Consultations'],
        summary: 'Reschedule a confirmed consultation booking',
        description: 'Updates the selected date/time after checking availability. Online bookings get a regenerated Zoom meeting link, and Outlook sync recreates the calendar event so the old event is removed.',
        parameters: [
            new OA\Parameter(name: 'consultation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['starts_at'], properties: [
            new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', example: '2026-08-14T09:00:00-07:00'),
            new OA\Property(property: 'timezone', type: 'string', nullable: true, example: 'America/Los_Angeles'),
            new OA\Property(property: 'professional_id', type: 'integer', nullable: true, example: 1),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Consultation rescheduled', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Consultation rescheduled.'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Consultation'),
            ])),
            new OA\Response(response: 422, description: 'Unavailable slot, inactive booking, or validation failed'),
        ]
    )]
    public function reschedule(Request $request, Consultation $consultation, ConsultationRescheduleService $rescheduler)
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'timezone' => ['nullable', 'timezone'],
            'professional_id' => ['nullable', 'integer', 'exists:professionals,id'],
        ]);

        try {
            $consultation = $rescheduler->reschedule($consultation->load('type'), $data, 'api_reschedule');
        } catch (\DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(
            new ConsultationResource($consultation->loadMissing(['type', 'professional', 'participants', 'paymentRequests', 'questionnaireSubmissions'])),
            'Consultation rescheduled.'
        );
    }

    #[OA\Get(
        path: '/v1/consultations/{consultation}/reschedule-status',
        tags: ['Consultations'],
        summary: 'Check whether a consultation can continue through reschedule flow',
        description: 'Returns only pending or completed. Completed consultations should not be rescheduled.',
        parameters: [
            new OA\Parameter(name: 'consultation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reschedule status', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'OK'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'completed'], example: 'pending'),
                ]),
            ])),
            new OA\Response(response: 404, description: 'Consultation not found'),
        ]
    )]
    public function rescheduleStatus(Consultation $consultation)
    {
        return ApiResponse::success([
            'status' => $consultation->status === 'completed' ? 'completed' : 'pending',
        ]);
    }

    #[OA\Post(
        path: '/v1/free-intro-slots/{scheduling_token}',
        tags: ['Consultations'],
        summary: 'Schedule an invited free intro participant slot',
        description: 'Used by additional Free 15-Min Intro Call participants who receive a secure scheduling email. The primary participant slot is reserved when the consultation is completed.',
        parameters: [
            new OA\Parameter(name: 'scheduling_token', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'token-from-email')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['starts_at'], properties: [
            new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', example: '2026-08-14T09:15:00-07:00'),
            new OA\Property(property: 'timezone', type: 'string', nullable: true, example: 'America/Los_Angeles'),
            new OA\Property(property: 'professional_id', type: 'integer', nullable: true, example: 1),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Participant slot scheduled', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Participant slot scheduled.'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Consultation'),
            ])),
            new OA\Response(response: 422, description: 'Invalid token, unavailable slot, or unsupported consultation type'),
        ]
    )]
    public function scheduleFreeIntroParticipantSlot(Request $request, string $schedulingToken, FreeIntroCallWorkflowService $workflow)
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'timezone' => ['nullable', 'timezone'],
            'professional_id' => ['nullable', 'integer', 'exists:professionals,id'],
        ]);

        try {
            $consultation = $workflow->scheduleParticipantByToken($schedulingToken, $data);
        } catch (\DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        $participant = ConsultationParticipant::query()
            ->where('scheduling_token', $schedulingToken)
            ->firstOrFail();
        $timezone = $participant->scheduled_timezone ?: config('app.booking_timezone');
        $resource = (new ConsultationResource($consultation->loadMissing(['type', 'professional', 'participants', 'paymentRequests', 'questionnaireSubmissions'])))
            ->resolve($request);
        $resource['starts_at'] = $participant->scheduled_starts_at?->timezone($timezone)->toIso8601String();
        $resource['ends_at'] = $participant->scheduled_ends_at?->timezone($timezone)->toIso8601String();
        $resource['timezone'] = $timezone;

        return ApiResponse::success(
            $resource,
            'Participant slot scheduled.'
        );
    }

    #[OA\Get(
        path: '/v1/availability',
        tags: ['Consultations'],
        summary: 'Return available start times at 15-minute intervals for free intro calls and 30-minute intervals for other consultations',
        description: 'Returns start times within BOOKING_DAY_START and BOOKING_DAY_END in BOOKING_TIMEZONE for the selected date or month. Past starts and starts within bookings, participant slots, or locally synced Outlook busy events are omitted. No end time or full-duration check is included. Call POST /v1/availability/confirm after selection. Outlook refresh remains scheduled/admin driven.',
        parameters: [
            new OA\Parameter(name: 'consultation_type_id', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 3)),
            new OA\Parameter(name: 'date', in: 'query', required: false, description: 'Required unless month is provided.', schema: new OA\Schema(type: 'string', format: 'date', example: '2026-08-14')),
            new OA\Parameter(name: 'month', in: 'query', required: false, description: 'Legacy fallback. Prefer date for selected-day availability.', schema: new OA\Schema(type: 'string', example: '2026-08')),
            new OA\Parameter(name: 'professional_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Selected-date availability', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'OK'),
                new OA\Property(property: 'data', ref: '#/components/schemas/AvailabilityDay'),
            ])),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function availability(Request $request, AvailabilityService $availability)
    {
        $request->validate([
            'consultation_type_id' => ['required', 'integer', 'exists:consultation_types,id'],
            'date' => ['required_without:month', 'date_format:Y-m-d'],
            'month' => ['required_without:date', 'date_format:Y-m'],
            'professional_id' => ['nullable', 'integer', 'exists:professionals,id'],
        ]);

        $type = ConsultationType::findOrFail($request->integer('consultation_type_id'));
        $selectedDate = $request->query('date');
        $month = $selectedDate !== null ? substr($selectedDate, 0, 7) : $request->query('month');

        if ($selectedDate !== null) {
            return ApiResponse::success($availability->dateAvailability(
                $type,
                $selectedDate,
                $request->integer('professional_id') ?: null
            ));
        }

        return ApiResponse::success($availability->monthAvailability(
            $type,
            $month,
            $request->integer('professional_id') ?: null
        ));
    }

    #[OA\Post(
        path: '/v1/availability/confirm',
        tags: ['Consultations'],
        summary: 'Check the full consultation duration for a selected start time',
        description: 'Calculates the end time from the consultation type duration and checks working hours and all shared booking and synced Outlook conflicts. Does not reserve the slot. Uses the same configured BOOKING_TIMEZONE wall-time handling as booking; timezone is accepted for compatibility.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['consultation_type_id', 'starts_at'], properties: [
            new OA\Property(property: 'consultation_type_id', type: 'integer', example: 3),
            new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', example: '2026-09-10T12:00:00+05:30'),
            new OA\Property(property: 'timezone', type: 'string', nullable: true, example: 'Asia/Kolkata'),
            new OA\Property(property: 'professional_id', type: 'integer', nullable: true, example: 1),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Full interval is available', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Selected time slot is available.'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', example: '2026-09-10T12:00:00+05:30'),
                    new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', example: '2026-09-10T16:00:00+05:30'),
                ]),
            ])),
            new OA\Response(response: 422, description: 'Invalid input or unavailable interval', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: false),
                new OA\Property(property: 'message', type: 'string', example: 'There is already a consultation scheduled during 9:00 AM to 1:00 PM. Please select another slot.'),
            ])),
        ]
    )]
    public function confirmAvailability(Request $request, AvailabilityService $availability, BookingDateTimeService $dateTimes)
    {
        $data = $request->validate([
            'consultation_type_id' => ['required', 'integer', 'exists:consultation_types,id'],
            'starts_at' => ['required', 'date'],
            'timezone' => ['nullable', 'timezone:all'],
            'professional_id' => ['nullable', 'integer', 'exists:professionals,id'],
        ]);
        $type = ConsultationType::findOrFail($data['consultation_type_id']);
        [$startsAt] = $dateTimes->startsAtFromRequest($data['starts_at']);
        $endsAt = $startsAt->addMinutes($type->duration_minutes);

        try {
            $availability->assertAvailable($type, $startsAt, $data['professional_id'] ?? null);
        } catch (\DomainException $exception) {
            return ApiResponse::error($this->availabilityConflictMessage($startsAt, $endsAt), 422);
        }

        return ApiResponse::success([
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
        ], 'Selected time slot is available.');
    }

    private function availabilityConflictMessage(CarbonImmutable $startsAt, CarbonImmutable $endsAt): string
    {
        return sprintf(
            'There is already a consultation scheduled during %s to %s. Please select another slot.',
            $startsAt->format('g:i A'),
            $endsAt->format('g:i A')
        );
    }
}
