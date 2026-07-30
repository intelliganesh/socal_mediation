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
        $token = null;

        $payload = [
            'ssl_merchant_id' => config('services.converge.merchant_id'),
            'ssl_user_id' => config('services.converge.user_id'),
            'ssl_pin' => config('services.converge.pin'),
            'ssl_transaction_type' => $method === 'ach' ? 'ecspurchase' : 'ccsale',
            'ssl_amount' => number_format($amountCents / 100, 2, '.', ''),
            'ssl_invoice_number' => $paymentRequestId,
            'ssl_description' => $consultation->booking_number.' - '.$consultation->type?->name,
            'ssl_customer_code' => $participant->email ?: $paymentRequestId,
        ];

        if (config('services.converge.enabled')) {
            $this->assertConfigured();
            $token = $this->requestHostedPaymentToken($payload);
        }

        return [
            'reference' => $reference,
            'url' => $token
                ? $this->hostedPaymentUrl($token)
                : route('payments.demo.show', ['paymentRequest' => $paymentRequestId]),
            'mode' => config('services.converge.mode'),
            'gateway_enabled' => (bool) config('services.converge.enabled'),
            'amount_cents' => $amountCents,
            'method' => $method,
            'invoice_number' => $paymentRequestId,
            'booking_number' => $consultation->booking_number,
            'participant_email' => $participant->email,
            'token_request' => $this->safePayload($payload),
            'session_token' => $token ? '[GENERATED]' : null,
        ];
    }

    private function requestHostedPaymentToken(array $payload): string
    {
        $response = Http::asForm()
            ->accept('*/*')
            ->post($this->hostedPaymentTokenEndpoint(), $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Converge hosted payment token request failed: '.$response->body());
        }

        $token = trim($response->body());

        if ($token === '' || str_starts_with(strtolower($token), 'error')) {
            throw new \RuntimeException('Converge hosted payment token request failed: '.$response->body());
        }

        return $token;
    }

    private function hostedPaymentUrl(string $token): string
    {
        return rtrim($this->hostedPaymentBaseUrl(), '/').'/hosted-payments?ssl_txn_auth_token='.urlencode($token);
    }

    private function hostedPaymentTokenEndpoint(): string
    {
        return rtrim($this->hostedPaymentBaseUrl(), '/').'/hosted-payments/transaction_token';
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
        $path = config('services.converge.mode') === 'production'
            ? '/VirtualMerchant/processxml.do'
            : '/VirtualMerchantDemo/processxml.do';

        return rtrim($this->gatewayBaseUrl(), '/').$path;
    }

    private function gatewayBaseUrl(): string
    {
        return config('services.converge.mode') === 'production'
            ? config('services.converge.production_base_url')
            : config('services.converge.sandbox_base_url');
    }

    private function hostedPaymentBaseUrl(): string
    {
        return config('services.converge.mode') === 'production'
            ? config('services.converge.production_hpp_base_url')
            : config('services.converge.sandbox_hpp_base_url');
    }

    private function assertConfigured(): void
    {
        foreach (['merchant_id', 'user_id', 'pin'] as $key) {
            if (blank(config('services.converge.'.$key))) {
                throw new \RuntimeException('Converge payment sync is enabled but CONVERGE_'.strtoupper($key).' is not configured.');
            }
        }
    }

    private function safePayload(array $payload): array
    {
        foreach (['ssl_pin'] as $secretKey) {
            if (array_key_exists($secretKey, $payload)) {
                $payload[$secretKey] = '[FILTERED]';
            }
        }

        return $payload;
    }
}
