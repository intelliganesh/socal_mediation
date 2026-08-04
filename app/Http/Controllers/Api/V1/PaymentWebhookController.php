<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConsultationResource;
use App\Services\ApiResponse;
use App\Services\PaymentReconciliationService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PaymentWebhookController extends Controller
{
    #[OA\Post(
        path: '/v1/payments/converge/confirmation',
        tags: ['Payments'],
        summary: 'Verify a Converge payment after receiving return data',
        description: 'Accepts the payment reference from a Hosted Payment Page return, then queries Converge server-to-server before changing payment status. Supplied status fields are logged but never trusted as proof of payment.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'payment_request_id', type: 'string', format: 'uuid', example: '4af8ec35-3704-4aec-a936-6a0a99d30199'),
            new OA\Property(property: 'ssl_invoice_number', type: 'string', example: '4af8ec35-3704-4aec-a936-6a0a99d30199'),
            new OA\Property(property: 'ssl_result', type: 'string', example: '0'),
            new OA\Property(property: 'ssl_result_message', type: 'string', example: 'APPROVAL'),
            new OA\Property(property: 'ssl_txn_id', type: 'string', example: '020524C4A-D5EC1A3F-84BE-4D4A-A95C-4C36CFE5ECF2'),
            new OA\Property(property: 'ssl_approval_code', type: 'string', example: '916299'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Payment verification completed', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Payment verification completed.'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Consultation'),
            ])),
            new OA\Response(response: 404, description: 'Payment request not found'),
            new OA\Response(response: 422, description: 'Payment reference is missing or confirmation is invalid'),
        ]
    )]
    public function confirmation(Request $request, PaymentReconciliationService $payments)
    {
        $data = $request->validate([
            'payment_request_id' => ['nullable', 'string'],
            'payment_request_uuid' => ['nullable', 'string'],
            'app_payment_reference' => ['nullable', 'string'],
            'ssl_invoice_number' => ['nullable', 'string'],
            'invoice_number' => ['nullable', 'string'],
            'provider_reference' => ['nullable', 'string'],
            'status' => ['nullable', 'in:paid,failed,cancelled'],
            'ssl_result' => ['nullable', 'string'],
            'ssl_result_message' => ['nullable', 'string'],
            'ssl_txn_id' => ['nullable', 'string'],
            'ssl_approval_code' => ['nullable', 'string'],
        ]);

        try {
            $consultation = $payments->confirmFromConvergePayload($data + $request->all());
        } catch (\DomainException|\RuntimeException $exception) {
            return ApiResponse::error('Converge payment verification failed: '.$exception->getMessage(), 422);
        }

        return ApiResponse::success(
            new ConsultationResource($consultation->load(['type', 'professional', 'participants', 'paymentRequests'])),
            'Payment verification completed.'
        );
    }

    public function converge(Request $request, PaymentReconciliationService $payments)
    {
        return $this->confirmation($request, $payments);
    }
}
