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
        path: '/v1/questionnaires/{token}',
        tags: ['Questionnaires'],
        summary: 'Get questionnaire details by secure token',
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Questionnaire detail'),
            new OA\Response(response: 404, description: 'Questionnaire token not found'),
        ]
    )]
    public function show(string $token, QuestionnaireTemplateService $templates, QuestionnaireWorkflowService $workflow)
    {
        $submission = $this->submission($token);
        $template = $templates->templateForConsultation($submission->consultation);

        return ApiResponse::success([
            'token' => $submission->token,
            'status' => $submission->status,
            'template' => [
                'key' => $template['key'],
                'label' => $template['label'],
                'version' => $template['version'],
                'requires_agreement' => (bool) ($template['requires_agreement'] ?? false),
                'fields' => $template['fields'],
            ],
            'agreement' => [
                'required' => (bool) ($template['requires_agreement'] ?? false),
                'accepted' => $submission->agreement_accepted,
                'version' => config('questionnaires.agreement_version'),
            ],
            'participant' => [
                'first_name' => $submission->participant->first_name,
                'last_name' => $submission->participant->last_name,
                'email' => $submission->participant->email,
                'is_primary' => $submission->participant->is_primary,
            ],
            'consultation' => new ConsultationResource($submission->consultation),
            'answers' => $submission->answers ?? [],
            'questionnaire_url' => $workflow->frontendUrl($submission),
        ]);
    }

    #[OA\Post(
        path: '/v1/questionnaires/{token}',
        tags: ['Questionnaires'],
        summary: 'Submit questionnaire answers by secure token',
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['answers'], properties: [
            new OA\Property(property: 'answers', type: 'object'),
            new OA\Property(property: 'agreement_accepted', type: 'boolean', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Questionnaire submitted'),
            new OA\Response(response: 404, description: 'Questionnaire token not found'),
            new OA\Response(response: 409, description: 'Questionnaire already submitted'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(string $token, Request $request, QuestionnaireWorkflowService $workflow)
    {
        $submission = $this->submission($token);
        $data = $request->validate([
            'answers' => ['required', 'array'],
            'agreement_accepted' => ['nullable', 'boolean'],
        ]);

        try {
            $consultation = $workflow->submit(
                $submission,
                $data['answers'],
                (bool) ($data['agreement_accepted'] ?? false),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (\DomainException $exception) {
            $status = str_contains($exception->getMessage(), 'already been submitted') ? 409 : 422;

            return ApiResponse::error($exception->getMessage(), $status);
        }

        return ApiResponse::success(new ConsultationResource($consultation), 'Questionnaire submitted.');
    }

    private function submission(string $token): QuestionnaireSubmission
    {
        return QuestionnaireSubmission::query()
            ->with(['consultation.type', 'consultation.professional', 'consultation.participants', 'consultation.paymentRequests', 'participant'])
            ->where('token', $token)
            ->firstOrFail();
    }
}
