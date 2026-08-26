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
    #[OA\Get(
        path: '/v1/questionnaires/socal-divorce-intake/{token}',
        tags: ['Questionnaires'],
        summary: 'Check SoCal divorce intake completion status',
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Questionnaire status', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'token', type: 'string'),
                    new OA\Property(property: 'questionnaire_completed', type: 'boolean', example: false),
                    new OA\Property(property: 'submitted_at', type: 'string', nullable: true),
                    new OA\Property(property: 'agreement_agreed', type: 'boolean', example: false),
                ]),
            ])),
            new OA\Response(response: 404, description: 'Questionnaire token not found'),
            new OA\Response(response: 409, description: 'Questionnaire token is not valid for this form'),
        ]
    )]
    public function showSocalDivorceIntake(string $token)
    {
        return $this->showForTemplate($token, 'socal_divorce_intake');
    }

    #[OA\Post(
        path: '/v1/questionnaires/socal-divorce-intake/{token}',
        tags: ['Questionnaires'],
        summary: 'Submit SoCal divorce intake answers',
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['answers'], properties: [
            new OA\Property(property: 'answers', type: 'object', properties: [
                new OA\Property(property: 'full_name', type: 'string', nullable: true),
                new OA\Property(property: 'spouse_partner_name', type: 'string', nullable: true),
                new OA\Property(property: 'phone', type: 'string', nullable: true),
                new OA\Property(property: 'email', type: 'string', nullable: true),
                new OA\Property(property: 'spouse_phone', type: 'string', nullable: true),
                new OA\Property(property: 'spouse_email', type: 'string', nullable: true),
                new OA\Property(property: 'preferred_language', type: 'string', nullable: true),
                new OA\Property(property: 'other_language', type: 'string', nullable: true),
                new OA\Property(property: 'referral_source', type: 'string', nullable: true),
                new OA\Property(property: 'date_of_marriage', type: 'string', nullable: true),
                new OA\Property(property: 'date_of_separation', type: 'string', nullable: true),
                new OA\Property(property: 'currently_living_together', type: 'boolean', nullable: true),
                new OA\Property(property: 'moved_out_party', type: 'string', nullable: true),
                new OA\Property(property: 'domestic_violence_history', type: 'string', nullable: true),
                new OA\Property(property: 'domestic_violence_explanation', type: 'string', nullable: true),
                new OA\Property(property: 'children_together', type: 'boolean', nullable: true),
                new OA\Property(property: 'children', type: 'string', nullable: true),
                new OA\Property(property: 'current_parenting_schedule', type: 'string', nullable: true),
                new OA\Property(property: 'proposed_parenting_schedule', type: 'string', nullable: true),
                new OA\Property(property: 'education_decision_maker', type: 'string', nullable: true),
                new OA\Property(property: 'medical_decision_maker', type: 'string', nullable: true),
                new OA\Property(property: 'child_related_concerns', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'child_related_concern_other', type: 'string', nullable: true),
                new OA\Property(property: 'child_support_discussed_or_ordered', type: 'string', nullable: true),
                new OA\Property(property: 'fair_monthly_child_support_amount', type: 'string', nullable: true),
                new OA\Property(property: 'monthly_income_you', type: 'string', nullable: true),
                new OA\Property(property: 'monthly_income_other_parent', type: 'string', nullable: true),
                new OA\Property(property: 'spousal_support_expectation', type: 'string', nullable: true),
                new OA\Property(property: 'spousal_support_duration', type: 'string', nullable: true),
                new OA\Property(property: 'spousal_monthly_income_you', type: 'string', nullable: true),
                new OA\Property(property: 'spousal_monthly_income_spouse', type: 'string', nullable: true),
                new OA\Property(property: 'real_estate_types', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'real_estate_addresses', type: 'string', nullable: true),
                new OA\Property(property: 'real_estate_estimated_values', type: 'string', nullable: true),
                new OA\Property(property: 'mortgage_balances', type: 'string', nullable: true),
                new OA\Property(property: 'bank_account_types', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'bank_accounts_estimated_total', type: 'string', nullable: true),
                new OA\Property(property: 'retirement_account_types', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'retirement_estimated_total', type: 'string', nullable: true),
                new OA\Property(property: 'vehicles', type: 'string', nullable: true),
                new OA\Property(property: 'other_asset_types', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'other_assets_description', type: 'string', nullable: true),
                new OA\Property(property: 'debts_credit_cards', type: 'string', nullable: true),
                new OA\Property(property: 'debts_loans', type: 'string', nullable: true),
                new OA\Property(property: 'debts_other', type: 'string', nullable: true),
                new OA\Property(property: 'property_division_goals', type: 'string', nullable: true),
                new OA\Property(property: 'divorce_case_filed', type: 'string', nullable: true),
                new OA\Property(property: 'case_number', type: 'string', nullable: true),
                new OA\Property(property: 'attorneys_involved', type: 'string', nullable: true),
                new OA\Property(property: 'mediation_priorities', type: 'string', nullable: true),
                new OA\Property(property: 'mediation_concerns', type: 'string', nullable: true),
                new OA\Property(property: 'preferred_session_format', type: 'string', nullable: true),
                new OA\Property(property: 'same_room_comfort', type: 'string', nullable: true),
                new OA\Property(property: 'additional_information', type: 'string', nullable: true),
                new OA\Property(property: 'confirmation_accepted', type: 'boolean', nullable: true, example: true),
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

    #[OA\Get(
        path: '/v1/questionnaires/socal-party-mediation/{token}',
        tags: ['Questionnaires'],
        summary: 'Check SoCal party mediation completion status',
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Questionnaire status', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'token', type: 'string'),
                    new OA\Property(property: 'questionnaire_completed', type: 'boolean', example: false),
                    new OA\Property(property: 'submitted_at', type: 'string', nullable: true),
                    new OA\Property(property: 'agreement_agreed', type: 'boolean', example: false),
                ]),
            ])),
            new OA\Response(response: 404, description: 'Questionnaire token not found'),
            new OA\Response(response: 409, description: 'Questionnaire token is not valid for this form'),
        ]
    )]
    public function showSocalPartyMediation(string $token)
    {
        return $this->showForTemplate($token, 'socal_party_mediation');
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
                new OA\Property(property: 'good_outcome', type: 'string', nullable: true),
                new OA\Property(property: 'mediation_goals', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'other_goal', type: 'string', nullable: true),
                new OA\Property(property: 'fairness_requirement', type: 'string', nullable: true),
                new OA\Property(property: 'flexible_about', type: 'string', nullable: true),
                new OA\Property(property: 'non_negotiables', type: 'string', nullable: true),
                new OA\Property(property: 'mediator_notes', type: 'string', nullable: true),
                new OA\Property(property: 'confirmation_accepted', type: 'boolean', nullable: true, example: true),
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

    #[OA\Get(
        path: '/v1/questionnaires/legal-initial-intake/{token}',
        tags: ['Questionnaires'],
        summary: 'Check legal initial intake completion status',
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Questionnaire status', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'token', type: 'string'),
                    new OA\Property(property: 'questionnaire_completed', type: 'boolean', example: false),
                    new OA\Property(property: 'submitted_at', type: 'string', nullable: true),
                    new OA\Property(property: 'agreement_agreed', type: 'boolean', example: false),
                ]),
            ])),
            new OA\Response(response: 404, description: 'Questionnaire token not found'),
            new OA\Response(response: 409, description: 'Questionnaire token is not valid for this form'),
        ]
    )]
    public function showLegalInitialIntake(string $token)
    {
        return $this->showForTemplate($token, 'legal_initial_intake');
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
                new OA\Property(property: 'name', type: 'string', nullable: true),
                new OA\Property(property: 'address', type: 'string', nullable: true),
                new OA\Property(property: 'home_phone', type: 'string', nullable: true),
                new OA\Property(property: 'cell_phone', type: 'string', nullable: true),
                new OA\Property(property: 'email', type: 'string', nullable: true),
                new OA\Property(property: 'alternate_email', type: 'string', nullable: true),
                new OA\Property(property: 'advice_or_assistance_needed', type: 'string', nullable: true),
                new OA\Property(property: 'referral_source', type: 'string', nullable: true),
                new OA\Property(property: 'referral_source_other', type: 'string', nullable: true),
                new OA\Property(property: 'paid', type: 'boolean', nullable: true),
                new OA\Property(property: 'confirmation_accepted', type: 'boolean', nullable: true, example: true),
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

    private function showForTemplate(string $token, string $templateKey)
    {
        $submission = $this->submission($token);

        if ($submission->template_key !== $templateKey) {
            return ApiResponse::error('This questionnaire link is not valid for the selected form.', 409);
        }

        return ApiResponse::success([
            'token' => $submission->token,
            'questionnaire_completed' => $submission->status === 'submitted',
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'agreement_agreed' => (bool) $submission->agreement_accepted,
        ]);
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
            new OA\Response(response: 200, description: 'Agreement detail', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'token', type: 'string'),
                    new OA\Property(property: 'required', type: 'boolean'),
                    new OA\Property(property: 'accepted', type: 'boolean'),
                    new OA\Property(property: 'agreement_agreed', type: 'boolean', example: false),
                    new OA\Property(property: 'accepted_at', type: 'string', nullable: true),
                    new OA\Property(property: 'questionnaire_completed', type: 'boolean', example: true),
                ]),
            ])),
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
            'agreement_agreed' => (bool) $submission->agreement_accepted,
            'accepted_at' => $submission->agreement_accepted_at?->toIso8601String(),
            'questionnaire_completed' => $submission->status === 'submitted',
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

        return ApiResponse::success([
            ...((new ConsultationResource($consultation))->resolve($request)),
            'questionnaire_completed' => $submission->refresh()->status === 'submitted',
        ], 'Agreement accepted.');
    }

    private function submission(string $token): QuestionnaireSubmission
    {
        return QuestionnaireSubmission::query()
            ->with(['consultation.type', 'consultation.professional', 'consultation.participants', 'consultation.paymentRequests', 'participant'])
            ->where('token', $token)
            ->firstOrFail();
    }
}
