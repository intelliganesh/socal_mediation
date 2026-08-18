<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConsultationResource;
use App\Models\QuestionnaireSubmission;
use App\Services\ApiResponse;
use App\Services\QuestionnaireTemplateService;
use App\Services\QuestionnaireWorkflowService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class QuestionnaireController extends Controller
{
    #[OA\Post(
        path: '/v1/questionnaires/socal-divorce-intake/{token}',
        tags: ['Questionnaires'],
        summary: 'Submit SoCal divorce intake answers',
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['answers'], properties: [
            new OA\Property(property: 'answers', type: 'object', properties: [
                new OA\Property(property: 'preferred_language', type: 'string', nullable: true),
                new OA\Property(property: 'other_language', type: 'string', nullable: true),
                new OA\Property(property: 'relationship_summary', type: 'string', nullable: true),
                new OA\Property(property: 'currently_living_together', type: 'boolean', nullable: true),
                new OA\Property(property: 'domestic_violence_history', type: 'string', nullable: true),
                new OA\Property(property: 'children_together', type: 'boolean', nullable: true),
                new OA\Property(property: 'children_details', type: 'string', nullable: true),
                new OA\Property(property: 'custody_concerns', type: 'string', nullable: true),
                new OA\Property(property: 'child_support_status', type: 'string', nullable: true),
                new OA\Property(property: 'spousal_support_expectation', type: 'string', nullable: true),
                new OA\Property(property: 'asset_summary', type: 'string', nullable: true),
                new OA\Property(property: 'debt_summary', type: 'string', nullable: true),
                new OA\Property(property: 'case_filed_status', type: 'string', nullable: true),
                new OA\Property(property: 'attorney_involvement', type: 'string', nullable: true),
                new OA\Property(property: 'preferred_session_format', type: 'string', nullable: true),
                new OA\Property(property: 'same_room_comfort', type: 'string', nullable: true),
                new OA\Property(property: 'goals_for_mediation', type: 'string', nullable: true),
                new OA\Property(property: 'additional_information', type: 'string', nullable: true),
            ]),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Questionnaire submitted'),
            new OA\Response(response: 404, description: 'Questionnaire token not found'),
            new OA\Response(response: 409, description: 'Questionnaire already submitted'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function storeSocalDivorceIntake(string $token, Request $request, QuestionnaireWorkflowService $workflow)
    {
        return $this->storeForTemplate($token, 'socal_divorce_intake', $request, $workflow);
    }

    #[OA\Post(
        path: '/v1/questionnaires/socal-party-mediation/{token}',
        tags: ['Questionnaires'],
        summary: 'Submit SoCal party mediation answers',
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['answers'], properties: [
            new OA\Property(property: 'answers', type: 'object', properties: [
                new OA\Property(property: 'name', type: 'string', nullable: true),
                new OA\Property(property: 'date', type: 'string', nullable: true),
                new OA\Property(property: 'other_party_name', type: 'string', nullable: true),
                new OA\Property(property: 'case_or_matter', type: 'string', nullable: true),
                new OA\Property(property: 'dispute_summary', type: 'string', nullable: true),
                new OA\Property(property: 'problem_started_at', type: 'string', nullable: true),
                new OA\Property(property: 'main_concern', type: 'string', nullable: true),
                new OA\Property(property: 'personal_contribution', type: 'string', nullable: true),
                new OA\Property(property: 'impact', type: 'string', nullable: true),
                new OA\Property(property: 'desired_result', type: 'string', nullable: true),
                new OA\Property(property: 'acceptable_solution', type: 'string', nullable: true),
                new OA\Property(property: 'unacceptable_result', type: 'string', nullable: true),
                new OA\Property(property: 'mediation_goals', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'other_goal', type: 'string', nullable: true),
                new OA\Property(property: 'important_information', type: 'string', nullable: true),
                new OA\Property(property: 'signature_name', type: 'string', nullable: true),
                new OA\Property(property: 'signature_date', type: 'string', nullable: true),
            ]),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Questionnaire submitted'),
            new OA\Response(response: 404, description: 'Questionnaire token not found'),
            new OA\Response(response: 409, description: 'Questionnaire already submitted or wrong template'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function storeSocalPartyMediation(string $token, Request $request, QuestionnaireWorkflowService $workflow)
    {
        return $this->storeForTemplate($token, 'socal_party_mediation', $request, $workflow);
    }

    #[OA\Post(
        path: '/v1/questionnaires/legal-initial-intake/{token}',
        tags: ['Questionnaires'],
        summary: 'Submit legal initial intake answers',
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['answers'], properties: [
            new OA\Property(property: 'answers', type: 'object', properties: [
                new OA\Property(property: 'previous_consultation', type: 'boolean', nullable: true),
                new OA\Property(property: 'name', type: 'string', nullable: true),
                new OA\Property(property: 'address', type: 'string', nullable: true),
                new OA\Property(property: 'email', type: 'string', nullable: true),
                new OA\Property(property: 'alternate_email', type: 'string', nullable: true),
                new OA\Property(property: 'phone', type: 'string', nullable: true),
                new OA\Property(property: 'matter_type', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'other_matter_type', type: 'string', nullable: true),
                new OA\Property(property: 'reason_for_visit', type: 'string', nullable: true),
                new OA\Property(property: 'desired_outcome', type: 'string', nullable: true),
                new OA\Property(property: 'additional_information', type: 'string', nullable: true),
            ]),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Questionnaire submitted'),
            new OA\Response(response: 404, description: 'Questionnaire token not found'),
            new OA\Response(response: 409, description: 'Questionnaire already submitted or wrong template'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function storeLegalInitialIntake(string $token, Request $request, QuestionnaireWorkflowService $workflow)
    {
        return $this->storeForTemplate($token, 'legal_initial_intake', $request, $workflow);
    }

    private function storeForTemplate(string $token, string $templateKey, Request $request, QuestionnaireWorkflowService $workflow)
    {
        $submission = $this->submission($token);

        if ($submission->template_key !== $templateKey) {
            return ApiResponse::error('This questionnaire link is not valid for the selected form.', 409);
        }

        $data = $request->validate([
            'answers' => ['required', 'array'],
        ]);

        try {
            $consultation = $workflow->submitQuestionnaire($submission, $data['answers']);
        } catch (\DomainException $exception) {
            $status = str_contains($exception->getMessage(), 'already been submitted') ? 409 : 422;

            return ApiResponse::error($exception->getMessage(), $status);
        }

        return ApiResponse::success(new ConsultationResource($consultation), 'Questionnaire submitted.');
    }

    #[OA\Get(
        path: '/v1/agreements/{token}',
        tags: ['Agreements'],
        summary: 'Get agreement details by secure token',
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Agreement detail'),
            new OA\Response(response: 404, description: 'Agreement token not found'),
        ]
    )]
    public function showAgreement(string $token, QuestionnaireTemplateService $templates, QuestionnaireWorkflowService $workflow)
    {
        $submission = $this->submission($token);
        $template = $templates->templateForConsultation($submission->consultation);

        return ApiResponse::success([
            'token' => $submission->token,
            'required' => (bool) ($template['requires_agreement'] ?? false),
            'accepted' => $submission->agreement_accepted,
            'accepted_at' => $submission->agreement_accepted_at?->toIso8601String(),
            'version' => QuestionnaireTemplateService::AGREEMENT_VERSION,
            'participant' => [
                'first_name' => $submission->participant->first_name,
                'last_name' => $submission->participant->last_name,
                'email' => $submission->participant->email,
                'is_primary' => $submission->participant->is_primary,
            ],
            'consultation' => new ConsultationResource($submission->consultation),
            'agreement_url' => ($template['requires_agreement'] ?? false) ? $workflow->agreementUrl($submission) : null,
            'questionnaire_url' => $workflow->questionnaireUrl($submission),
        ]);
    }

    #[OA\Post(
        path: '/v1/agreements/{token}',
        tags: ['Agreements'],
        summary: 'Accept agreement by secure token',
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['accepted'], properties: [
            new OA\Property(property: 'accepted', type: 'boolean', example: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Agreement accepted'),
            new OA\Response(response: 404, description: 'Agreement token not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function acceptAgreement(string $token, Request $request, QuestionnaireWorkflowService $workflow)
    {
        $submission = $this->submission($token);
        $request->validate([
            'accepted' => ['required', 'accepted'],
        ]);

        try {
            $consultation = $workflow->acceptAgreement($submission, $request->ip(), $request->userAgent());
        } catch (\DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(new ConsultationResource($consultation), 'Agreement accepted.');
    }

    private function submission(string $token): QuestionnaireSubmission
    {
        return QuestionnaireSubmission::query()
            ->with(['consultation.type', 'consultation.professional', 'consultation.participants', 'consultation.paymentRequests', 'participant'])
            ->where('token', $token)
            ->firstOrFail();
    }
}
