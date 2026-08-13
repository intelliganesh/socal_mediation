<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Services\ApiResponse;
use App\Services\Integrations\ConvergeClient;
use OpenApi\Attributes as OA;

class PaymentCheckoutController extends Controller
{
    #[OA\Post(
        path: '/v1/payments/{payment_request_uuid}/checkout-token',
        tags: ['Payments'],
        summary: 'Create a Converge Checkout.js token for a full payment',
        description: 'Full-payment consultations use React Checkout.js/Lightbox. Split-payment requests continue using Hosted Payment Page links and cannot use this endpoint.',
        parameters: [
            new OA\Parameter(name: 'payment_request_uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Checkout token created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Checkout token created.'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'payment_request_uuid', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'amount_cents', type: 'integer', example: 260000),
                    new OA\Property(property: 'currency', type: 'string', example: 'USD'),
                    new OA\Property(property: 'provider_reference', type: 'string', example: 'CONVabc123'),
                    new OA\Property(property: 'ssl_txn_auth_token', type: 'string', example: 'session-token'),
                    new OA\Property(property: 'checkout_method', type: 'string', example: 'checkout_js'),
                    new OA\Property(property: 'converge_mode', type: 'string', example: 'sandbox'),
                    new OA\Property(property: 'converge_base_url', type: 'string', example: 'https://api.demo.convergepay.com'),
                    new OA\Property(property: 'hosted_payment_action', type: 'string', example: 'https://api.demo.convergepay.com/hosted-payments/'),
                ]),
            ])),
            new OA\Response(response: 404, description: 'Payment request not found'),
            new OA\Response(response: 409, description: 'Payment request is not eligible for Checkout.js'),
            new OA\Response(response: 422, description: 'Converge token creation failed'),
        ]
    )]
    public function token(string $paymentRequestUuid, ConvergeClient $converge)
    {
        $paymentRequest = PaymentRequest::with(['consultation.type', 'participant'])->findOrFail($paymentRequestUuid);

        if ($paymentRequest->provider !== 'converge' || ! config('services.converge.enabled')) {
            return ApiResponse::error('Converge checkout is currently unavailable.', 409);
        }

        if ($paymentRequest->status === 'paid') {
            return ApiResponse::error('This payment has already been completed.', 409);
        }

        if (in_array($paymentRequest->consultation->status, ['draft', 'cancelled'], true)) {
            return ApiResponse::error('This consultation cannot accept payment.', 409);
        }

        if ($paymentRequest->consultation->payment_mode !== 'full'
            || data_get($paymentRequest->metadata, 'checkout_method') !== 'checkout_js') {
            return ApiResponse::error('Checkout.js tokens are only available for full-payment requests.', 409);
        }

        try {
            $session = $converge->createCheckoutJsSession($paymentRequest);
        } catch (\Throwable $exception) {
            $paymentRequest->integrationLogs()->create([
                'provider' => 'converge',
                'action' => 'checkout_js_session',
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ]);

            return ApiResponse::error('Converge checkout token creation failed: '.$exception->getMessage(), 422);
        }

        $paymentRequest->integrationLogs()->create([
            'provider' => 'converge',
            'action' => 'checkout_js_session',
            'status' => 'created',
            'request_payload' => $session['request'],
            'response_payload' => [
                'checkout_method' => $session['checkout_method'],
                'converge_mode' => $session['converge_mode'],
            ],
            'message' => 'Fresh Converge Checkout.js session token created.',
        ]);

        unset($session['request']);

        return ApiResponse::success($session, 'Checkout token created.');
    }
}
