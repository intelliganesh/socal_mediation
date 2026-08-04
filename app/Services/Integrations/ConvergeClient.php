<?php

namespace App\Services\Integrations;

use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use App\Models\PaymentRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class ConvergeClient
{
    public function createPaymentLink(Consultation $consultation, ConsultationParticipant $participant, int $amountCents, ?string $method, string $paymentRequestId): array
    {
        $reference = 'conv_'.Str::lower(Str::random(16));

        if (config('services.converge.enabled')) {
            $this->assertHostedPaymentConfigured();
        }

        return [
            'provider' => 'converge',
            'reference' => $reference,
            'url' => config('services.converge.enabled')
                ? URL::signedRoute('payments.checkout', ['paymentRequest' => $paymentRequestId])
                : null,
            'mode' => config('services.converge.mode'),
            'gateway_enabled' => (bool) config('services.converge.enabled'),
            'amount_cents' => $amountCents,
            'method' => $method,
            'invoice_number' => $paymentRequestId,
            'booking_number' => $consultation->booking_number,
            'participant_email' => $participant->email,
            'session_token' => null,
        ];
    }

    public function createHostedPaymentSession(PaymentRequest $payment): array
    {
        $this->assertHostedPaymentConfigured();
        $payment->loadMissing(['consultation.type', 'participant']);
        $payload = $this->hostedPaymentPayload($payment);

        return [
            'action' => rtrim($this->hostedPaymentBaseUrl(), '/').'/hosted-payments/',
            'token' => $this->requestHostedPaymentToken($payload),
            'request' => $this->safePayload($payload),
        ];
    }

    private function requestHostedPaymentToken(array $payload): string
    {
        $response = Http::asForm()
            ->accept('*/*')
            ->connectTimeout(10)
            ->timeout((int) config('services.converge.http_timeout_seconds', 90))
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

    private function hostedPaymentPayload(PaymentRequest $payment): array
    {
        $consultation = $payment->consultation;
        $participant = $payment->participant;

        return [
            'ssl_merchant_id' => config('services.converge.merchant_id'),
            'ssl_user_id' => config('services.converge.user_id'),
            'ssl_pin' => config('services.converge.pin'),
            'ssl_transaction_type' => $payment->payment_method === 'ach' ? 'ecspurchase' : 'ccsale',
            'ssl_amount' => number_format($payment->amount_cents / 100, 2, '.', ''),
            'ssl_invoice_number' => $payment->id,
            'ssl_description' => $consultation->booking_number.' - '.$consultation->type?->name,
            'ssl_customer_code' => $participant?->email ?: $payment->id,
            'ssl_first_name' => $participant?->first_name ?: $consultation->primary_first_name,
            'ssl_last_name' => $participant?->last_name ?: $consultation->primary_last_name,
            'ssl_email' => $participant?->email ?: $consultation->primary_email,
        ];
    }

    private function hostedPaymentTokenEndpoint(): string
    {
        return rtrim($this->hostedPaymentBaseUrl(), '/').'/hosted-payments/transaction_token';
    }

    public function lookupPaymentStatus(PaymentRequest $payment): array
    {
        $this->assertCredentialsConfigured();

        $response = Http::asForm()
            ->accept('*/*')
            ->connectTimeout(10)
            ->timeout((int) config('services.converge.http_timeout_seconds', 90))
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
        return $this->gatewayBaseUrl();
    }

    private function assertHostedPaymentConfigured(): void
    {
        $this->assertCredentialsConfigured();

        if (blank(config('services.converge.return_url'))) {
            throw new \RuntimeException('CONVERGE_RETURN_URL is not configured for the Hosted Payment Page profile.');
        }
    }

    private function assertCredentialsConfigured(): void
    {
        foreach (['merchant_id', 'user_id', 'pin'] as $key) {
            if (blank(config('services.converge.'.$key))) {
                throw new \RuntimeException('CONVERGE_'.strtoupper($key).' is not configured.');
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
