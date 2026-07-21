<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CompleteConsultationRequest;
use App\Http\Requests\Api\CreateDraftConsultationRequest;
use App\Http\Resources\ConsultationResource;
use App\Models\Consultation;
use App\Models\ConsultationType;
use App\Services\ApiResponse;
use App\Services\AvailabilityService;
use App\Services\ConsultationCompletionService;
use App\Services\ConsultationDraftService;
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
            $consultation->load(['type', 'legalService', 'professional', 'participants', 'paymentRequests'])
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
            new ConsultationResource($consultation),
            'Consultation completed and payment links sent.'
        );
    }

    #[OA\Get(
        path: '/v1/availability',
        tags: ['Consultations'],
        summary: 'Return duration-based available slots for a consultation type and month',
        description: 'The day window comes from BOOKING_DAY_START and BOOKING_DAY_END. Slot spacing comes from the selected consultation type duration, and each slot is marked unavailable when it overlaps an application booking or Outlook busy event.',
        parameters: [
            new OA\Parameter(name: 'consultation_type_id', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 3)),
            new OA\Parameter(name: 'month', in: 'query', required: true, schema: new OA\Schema(type: 'string', example: '2026-08')),
            new OA\Parameter(name: 'professional_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Monthly availability', content: new OA\JsonContent(properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'OK'),
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AvailabilityDay')),
                    ])),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function availability(Request $request, AvailabilityService $availability)
    {
        $request->validate([
            'consultation_type_id' => ['required', 'integer', 'exists:consultation_types,id'],
            'month'                => ['required', 'date_format:Y-m'],
            'professional_id'      => ['nullable', 'integer', 'exists:professionals,id'],
        ]);

        $type = ConsultationType::findOrFail($request->integer('consultation_type_id'));

        return ApiResponse::success($availability->monthAvailability(
            $type,
            $request->query('month'),
            $request->integer('professional_id') ?: null
        ));
    }

}
