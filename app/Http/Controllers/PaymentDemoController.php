<?php

namespace App\Http\Controllers;

use App\Models\PaymentRequest;
use App\Services\PaymentReconciliationService;

class PaymentDemoController extends Controller
{
    public function show(PaymentRequest $paymentRequest)
    {
        return view('payments.demo', [
            'paymentRequest' => $paymentRequest->load(['consultation.type', 'participant']),
        ]);
    }

    public function pay(PaymentRequest $paymentRequest, PaymentReconciliationService $payments)
    {
        if ($paymentRequest->status !== 'paid') {
            $payments->confirmFromConvergePayload([
                'payment_request_id' => $paymentRequest->id,
                'status' => 'paid',
                'ssl_txn_id' => 'demo_'.$paymentRequest->provider_reference,
                'ssl_result' => '0',
                'ssl_result_message' => 'APPROVAL',
                'ssl_approval_code' => 'DEMO',
            ]);
        }

        return redirect()
            ->route('payments.demo.show', $paymentRequest)
            ->with('status', 'Payment completed successfully.');
    }
}
