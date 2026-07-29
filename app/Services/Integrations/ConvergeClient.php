<?php

namespace App\Services\Integrations;

use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use App\Models\PaymentRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ConvergeClient
{
    public function createPaymentLink(Consultation $consultation, ConsultationParticipant $participant, int $amountCents, ?string $method, string $paymentRequestId): array
    {
        $reference = 'conv_'.Str::lower(Str::random(16));
        $baseUrl = rtrim(config('services.converge.payment_base_url'), '/');

        return [
            'reference' => $reference,
            'url' => $baseUrl.'/pay/'.$reference,
            'mode' => config('services.converge.mode'),
            'gateway_enabled' => (bool) config('services.converge.enabled'),
            'amount_cents' => $amountCents,
            'method' => $method,
            'invoice_number' => $paymentRequestId,
            'booking_number' => $consultation->booking_number,
            'participant_email' => $participant->email,
        ];
    }

    public function lookupPaymentStatus(PaymentRequest $payment): array
    {
        $this->assertConfigured();

        $response = Http::asForm()
            ->accept('*/*')
            ->post($this->xmlEndpoint(), [
                'xmldata' => $this->transactionQueryXml($payment),
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Converge payment status lookup failed: '.$response->body());
        }

        $payload = $this->parseXmlResponse($response->body());

        return [
            'status' => $this->statusFromResponse($payload),
            'transaction_id' => $payload['ssl_txn_id'] ?? null,
            'approval_code' => $payload['ssl_approval_code'] ?? null,
            'result_message' => $payload['ssl_result_message'] ?? $payload['errorMessage'] ?? null,
            'raw' => $payload,
        ];
    }

    private function transactionQueryXml(PaymentRequest $payment): string
    {
        $fields = [
            'ssl_merchant_id' => config('services.converge.merchant_id'),
            'ssl_user_id' => config('services.converge.user_id'),
            'ssl_pin' => config('services.converge.pin'),
            'ssl_transaction_type' => 'txnquery',
            'ssl_invoice_number' => $payment->id,
        ];

        $xml = '<txn>';
        foreach ($fields as $key => $value) {
            $xml .= '<'.$key.'>'.htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8').'</'.$key.'>';
        }

        return $xml.'</txn>';
    }

    private function parseXmlResponse(string $body): array
    {
        $xml = @simplexml_load_string($body);

        if (! $xml) {
            return ['raw_body' => $body];
        }

        return json_decode(json_encode($xml), true) ?: [];
    }

    private function statusFromResponse(array $payload): string
    {
        if (($payload['ssl_result'] ?? null) !== null) {
            return (string) $payload['ssl_result'] === '0' ? 'paid' : 'failed';
        }

        if (($payload['errorCode'] ?? null) !== null) {
            return 'unknown';
        }

        return 'unknown';
    }

    private function xmlEndpoint(): string
    {
        $baseUrl = config('services.converge.mode') === 'production'
            ? config('services.converge.production_base_url')
            : config('services.converge.sandbox_base_url');

        $path = config('services.converge.mode') === 'production'
            ? '/VirtualMerchant/processxml.do'
            : '/VirtualMerchantDemo/processxml.do';

        return rtrim($baseUrl, '/').$path;
    }

    private function assertConfigured(): void
    {
        foreach (['merchant_id', 'user_id', 'pin'] as $key) {
            if (blank(config('services.converge.'.$key))) {
                throw new \RuntimeException('Converge payment sync is enabled but CONVERGE_'.strtoupper($key).' is not configured.');
            }
        }
    }
}
