<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConsultationResource;
use App\Models\PaymentRequest;
use App\Services\ApiResponse;
use App\Services\PaymentSimulationService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class SimulatedPaymentController extends Controller
{
    #[OA\Post(
        path: '/v1/testing/payments/{payment_request_uuid}/complete',
        tags: ['Testing'],
        summary: 'Mark one payment share paid in a non-production environment',
        description: 'Available only when payment simulation is enabled, Converge is disabled, and the application is not running in production. Paying the final share triggers the normal Zoom and Outlook finalization flow.',
        parameters: [
            new OA\Parameter(name: 'payment_request_uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'X-Payment-Simulation-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'password')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Simulated payment completed', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Simulated payment completed.'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Consultation'),
            ])),
            new OA\Response(response: 403, description: 'Missing or invalid simulation key'),
            new OA\Response(response: 404, description: 'Simulation unavailable or payment request not found'),
            new OA\Response(response: 409, description: 'Converge enabled or consultation cannot receive payment'),
        ]
    )]
    public function complete(
        Request $request,
        string $payment_request_uuid,
        PaymentSimulationService $simulation,
    ) {
        if (! $simulation->isEnabled()) {
            abort(404);
        }

        if (config('services.converge.enabled')) {
            return ApiResponse::error('Payment simulation is unavailable while Converge is enabled.', 409);
        }

        $configuredKey = (string) config('services.payment_simulation.key');
        $providedKey = (string) $request->header('X-Payment-Simulation-Key');

        if ($configuredKey === '' || $providedKey === '' || ! hash_equals($configuredKey, $providedKey)) {
            return ApiResponse::error('Invalid payment simulation key.', 403);
        }

        $payment = PaymentRequest::findOrFail($payment_request_uuid);

        try {
            $consultation = $simulation->complete($payment);
        } catch (\DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 409);
        }

        return ApiResponse::success(
            new ConsultationResource($consultation),
            'Simulated payment completed.'
        );
    }
}
