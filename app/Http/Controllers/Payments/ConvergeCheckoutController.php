<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Services\Integrations\ConvergeClient;
use App\Services\PaymentReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use OpenApi\Attributes as OA;

class ConvergeCheckoutController extends Controller
{
    public function checkout(PaymentRequest $paymentRequest, ConvergeClient $converge): View|Response
    {
        $paymentRequest->loadMissing(['consultation.type', 'participant']);

        if ($paymentRequest->status === 'paid') {
            return $this->resultView($paymentRequest, 'paid', 'This payment has already been completed.');
        }

        if ($paymentRequest->provider !== 'converge' || ! config('services.converge.enabled')) {
            return $this->resultView($paymentRequest, 'unavailable', 'Online payment is currently unavailable.');
        }

        if (in_array($paymentRequest->consultation->status, ['draft', 'cancelled'], true)) {
            return $this->resultView($paymentRequest, 'unavailable', 'This consultation cannot accept payment.');
        }

        try {
            $session = $converge->createHostedPaymentSession($paymentRequest);

            $paymentRequest->integrationLogs()->create([
                'provider' => 'converge',
                'action' => 'hosted_payment_session',
                'status' => 'created',
                'request_payload' => $session['request'],
                'message' => 'Fresh Converge Hosted Payment Page session created.',
            ]);
        } catch (\Throwable $exception) {
            $paymentRequest->integrationLogs()->create([
                'provider' => 'converge',
                'action' => 'hosted_payment_session',
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ]);

            return $this->resultView($paymentRequest, 'error', 'We could not start the secure payment session. Please try again.');
        }

        return response()
            ->view('payments.converge-redirect', [
                'paymentRequest' => $paymentRequest,
                'action' => $session['action'],
                'token' => $session['token'],
            ])
            ->header('Cache-Control', 'no-store, private');
    }

    #[OA\Post(
        path: '/v1/payments/converge/return',
        tags: ['Payments'],
        summary: 'Receive the Hosted Payment Page browser return',
        description: 'Configure this URL in the Converge Hosted Payment Page profile. The callback reference triggers an independent server-to-server transaction query before payment status changes.',
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
            mediaType: 'application/x-www-form-urlencoded',
            schema: new OA\Schema(properties: [
                new OA\Property(property: 'ssl_invoice_number', type: 'string', example: 'conv_a1b2c3d4e5f6g7h8'),
                new OA\Property(property: 'ssl_customer_code', type: 'string', example: 'LX-72796'),
                new OA\Property(property: 'ssl_email', type: 'string', format: 'email'),
                new OA\Property(property: 'ssl_amount', type: 'string', example: '2600.00'),
                new OA\Property(property: 'ssl_result', type: 'string', nullable: true),
                new OA\Property(property: 'ssl_txn_id', type: 'string', nullable: true),
            ])
        )),
        responses: [
            new OA\Response(response: 200, description: 'HTML payment result page'),
            new OA\Response(response: 404, description: 'Payment request not found'),
            new OA\Response(response: 422, description: 'Payment reference missing'),
        ]
    )]
    public function return(Request $request, PaymentReconciliationService $payments): View
    {
        $paymentRequest = $this->resolveReturnedPayment($request);

        try {
            $payments->verifyPayment($paymentRequest, 'payment_return', $request->all());
            $paymentRequest->refresh();
        } catch (\Throwable $exception) {
            $paymentRequest->integrationLogs()->create([
                'provider' => 'converge',
                'action' => 'payment_return',
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ]);

            return $this->resultView($paymentRequest, 'error', 'Payment verification is temporarily unavailable. Your payment status has not been changed.');
        }

        return match ($paymentRequest->status) {
            'paid' => $this->resultView($paymentRequest, 'paid', 'Your payment was completed successfully.'),
            'failed' => $this->resultView($paymentRequest, 'failed', 'The payment was not approved. You can try again with a fresh payment session.'),
            default => $this->resultView($paymentRequest, 'pending', 'We are still verifying your payment. Please check again shortly.'),
        };
    }

    public function returnForPayment(Request $request, PaymentRequest $paymentRequest, PaymentReconciliationService $payments): View
    {
        $paymentRequest->loadMissing(['consultation.type', 'participant']);

        try {
            $payments->verifyPayment($paymentRequest, 'payment_return', $request->all());
            $paymentRequest->refresh();
        } catch (\Throwable $exception) {
            $paymentRequest->integrationLogs()->create([
                'provider' => 'converge',
                'action' => 'payment_return',
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ]);

            return $this->resultView($paymentRequest, 'error', 'Payment verification is temporarily unavailable. Your payment status has not been changed.');
        }

        return match ($paymentRequest->status) {
            'paid' => $this->resultView($paymentRequest, 'paid', 'Your payment was completed successfully.'),
            'failed' => $this->resultView($paymentRequest, 'failed', 'The payment was not approved. You can try again with a fresh payment session.'),
            default => $this->resultView($paymentRequest, 'pending', 'We are still verifying your payment. Please check again shortly.'),
        };
    }

    private function resultView(PaymentRequest $paymentRequest, string $state, string $message): View
    {
        return view('payments.converge-result', compact('paymentRequest', 'state', 'message'));
    }

    private function resolveReturnedPayment(Request $request): PaymentRequest
    {
        $paymentId = $request->input('ssl_invoice_number')
            ?? $request->input('payment_request_id')
            ?? $request->input('payment_request_uuid');

        if (filled($paymentId)) {
            return PaymentRequest::with(['consultation.type', 'participant'])
                ->whereKey($paymentId)
                ->orWhere('provider_reference', $paymentId)
                ->firstOrFail();
        }

        $bookingNumber = $request->input('ssl_customer_code');
        $email = strtolower(trim((string) $request->input('ssl_email')));
        $amount = $request->input('ssl_amount');

        abort_if(blank($bookingNumber) || $email === '' || ! is_numeric($amount), 422, 'Payment reference is required.');

        $amountCents = (int) round((float) $amount * 100);
        $matches = PaymentRequest::query()
            ->with(['consultation.type', 'participant'])
            ->where('provider', 'converge')
            ->where('amount_cents', $amountCents)
            ->whereHas('consultation', fn ($query) => $query->where('booking_number', $bookingNumber))
            ->get()
            ->filter(function (PaymentRequest $payment) use ($email) {
                $participantEmail = $payment->participant?->email
                    ?? data_get($payment->metadata, 'participant_email');

                return strtolower(trim((string) $participantEmail)) === $email;
            })
            ->values();

        abort_if($matches->count() !== 1, 422, 'Payment reference is missing or ambiguous.');

        return $matches->first();
    }
}
