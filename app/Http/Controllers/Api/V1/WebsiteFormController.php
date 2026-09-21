<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebsiteFormRequest;
use App\Http\Resources\WebsiteFormResource;
use App\Models\WebsiteForm;
use App\Services\ApiResponse;
use OpenApi\Attributes as OA;

class WebsiteFormController extends Controller
{
    #[OA\Post(
        path: '/v1/website-forms',
        summary: 'Submit a public website form',
        tags: ['Website Forms'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['application', 'name', 'email', 'phone', 'message'],
            properties: [
                new OA\Property(property: 'application', type: 'string', enum: ['socal', 'legal'], example: 'socal'),
                new OA\Property(property: 'name', type: 'string', example: 'Jordan Smith'),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'jordan@example.com'),
                new OA\Property(property: 'phone', type: 'string', example: '+1 555 010 0200'),
                new OA\Property(property: 'message', type: 'string', example: 'I would like more information about mediation.'),
                new OA\Property(
                    property: 'extra_fields',
                    description: 'Additional website-specific fields keyed by their frontend field names.',
                    type: 'object',
                    nullable: true,
                    example: ['preferred_contact_method' => 'email', 'company' => 'Example LLC'],
                    additionalProperties: new OA\AdditionalProperties,
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Website form submitted', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Your message has been submitted successfully.'),
                new OA\Property(property: 'data', ref: '#/components/schemas/WebsiteForm'),
            ])),
            new OA\Response(response: 422, description: 'Validation failed'),
            new OA\Response(response: 429, description: 'Too many requests'),
        ],
    )]
    public function store(StoreWebsiteFormRequest $request)
    {
        $websiteForm = WebsiteForm::create($request->validated());

        return ApiResponse::success(
            new WebsiteFormResource($websiteForm),
            'Your message has been submitted successfully.',
            201,
        );
    }
}
