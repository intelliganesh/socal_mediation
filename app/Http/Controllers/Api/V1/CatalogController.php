<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConsultationTypeResource;
use App\Models\ConsultationType;
use App\Models\LegalService;
use App\Services\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CatalogController extends Controller
{
    #[OA\Get(
        path: '/v1/consultation-types',
        tags: ['Consultation Type Catalog'],
        summary: 'List available consultation types',
        parameters: [
            new OA\Parameter(name: 'application', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['socal', 'legal'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Consultation type catalog', content: new OA\JsonContent(properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'OK'),
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ConsultationType')),
                    ])),
        ]
    )]
    public function consultationTypes(Request $request)
    {
        $types = ConsultationType::query()
            ->where('is_active', true)
            ->when($request->query('application'), fn($query, $application) => $query->where('application', $application))
            ->orderBy('price_cents')
            ->get();

        return ApiResponse::success(ConsultationTypeResource::collection($types));
    }

    #[OA\Get(
        path: '/v1/legal-services',
        tags: ['Consultation Type Catalog'],
        summary: 'List selectable legal service categories',
        parameters: [
            new OA\Parameter(name: 'application', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['socal', 'legal'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Legal service catalog', content: new OA\JsonContent(properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'OK'),
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LegalService')),
                    ])),
        ]
    )]
    public function legalServices(Request $request)
    {
        $services = LegalService::query()
            ->where('is_active', true)
            ->when($request->query('application'), fn($query, $application) => $query->where('application', $application))
            ->orderBy('name')
            ->get(['id', 'application', 'name', 'slug']);

        return ApiResponse::success($services);
    }
}
