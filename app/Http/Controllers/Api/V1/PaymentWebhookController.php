<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConsultationResource;
use App\Models\PaymentRequest;
use App\Services\ApiResponse;
use App\Services\BookingFinalizer;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PaymentWebhookController extends Controller
{
    #[OA\Post(
        path: '/v1/payments/converge/webhook',
        tags: ['Payments'],
        summary: 'Accept Converge payment status callbacks',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['provider_reference', 'status'], properties: [
            new OA\Property(property: 'provider_reference', type: 'string', example: 'conv_abc123'),
            new OA\Property(property: 'status', type: 'string', enum: ['paid', 'failed', 'cancelled'], example: 'paid'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Webhook accepted', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Webhook accepted.'),
                new OA\Property(property: 'data', ref: '#/components/schemas/Consultation'),
            ])),
            new OA\Response(response: 404, description: 'Payment request not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function converge(Request $request, BookingFinalizer $finalizer)
    {
        $data = $request->validate([
            'provider_reference' => ['required', 'string'],
            'status' => ['required', 'in:paid,failed,cancelled'],
        ]);

        $payment = PaymentRequest::where('provider_reference', $data['provider_reference'])->firstOrFail();
        $payment->update([
            'status' => $data['status'],
            'paid_at' => $data['status'] === 'paid' ? now() : null,
            'metadata' => array_merge($payment->metadata ?? [], ['webhook' => $request->all()]),
        ]);

        $consultation = $finalizer->syncPaymentStatus($payment->consultation);

        return ApiResponse::success(
            new ConsultationResource($consultation->load(['type', 'professional', 'participants', 'paymentRequests'])),
            'Webhook accepted.'
        );
    }
}
