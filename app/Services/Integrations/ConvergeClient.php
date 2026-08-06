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
        $reference = 'conv_' . Str::lower(Str::random(16));

        if (config('services.converge.enabled')) {
            $this->assertHostedPaymentConfigured();
        }

        return [
            'provider'          => 'converge',
            'reference'         => $reference,
            'url'               => config('services.converge.enabled')
                ? URL::signedRoute('payments.checkout', ['paymentRequest' => $paymentRequestId])
                : null,
            'mode'              => config('services.converge.mode'),
            'gateway_enabled'   => (bool) config('services.converge.enabled'),
            'amount_cents'      => $amountCents,
            'method'            => $method,
            'invoice_number'    => $paymentRequestId,
            'booking_number'    => $consultation->booking_number,
            'participant_email' => $participant->email,
            'session_token'     => null,
        ];
    }

    public function createHostedPaymentSession(PaymentRequest $payment): array
    {
        $this->assertHostedPaymentConfigured();
        $payment->loadMissing(['consultation.type', 'participant']);
        $payload = $this->hostedPaymentPayload($payment);

        return [
            'action'  => rtrim($this->hostedPaymentBaseUrl(), '/') . '/hosted-payments/',
            'token'   => $this->requestHostedPaymentToken($payload),
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

        if ($response->forbidden()) {
            throw new \RuntimeException(
                'Converge rejected the Hosted Payment Page request (403). Verify that the Account ID is correct, the API user has Hosted Payment API access, and the server IP is allowlisted.'
            );
        }

        if ($response->failed()) {
            throw new \RuntimeException('Converge hosted payment token request failed: ' . $response->body());
        }

        $token = trim($response->body());

        if ($token === '' || str_starts_with(strtolower($token), 'error')) {
            throw new \RuntimeException('Converge hosted payment token request failed: ' . $response->body());
        }

        return $token;
    }

    private function hostedPaymentPayload(PaymentRequest $payment): array
    {
        $consultation = $payment->consultation;
        $participant  = $payment->participant;
        $returnUrl    = $this->returnUrl($payment);

        $payload = [
            'ssl_account_id'          => config('services.converge.account_id'),
            'ssl_user_id'             => config('services.converge.user_id'),
            'ssl_pin'                 => config('services.converge.pin'),
            'ssl_transaction_type'    => $payment->payment_method === 'ach' ? 'ecspurchase' : 'ccsale',
            'ssl_amount'              => number_format($payment->amount_cents / 100, 2, '.', ''),
            'ssl_description'         => Str::limit($consultation->booking_number . ' - ' . $consultation->type?->name, 255, ''),
            'ssl_first_name'          => $participant?->first_name ?: $consultation->primary_first_name,
            'ssl_last_name'           => $participant?->last_name ?: $consultation->primary_last_name,
            'ssl_email'               => $participant?->email ?: $consultation->primary_email,
            'ssl_result_format'       => 'html',
            'ssl_receipt_link_method' => 'REDG',
            'ssl_receipt_link_url'    => $returnUrl,
            'ssl_error_url'           => $returnUrl,
        ];

        if ($this->fitsConvergeLimit($payment->id, 25)) {
            $payload['ssl_invoice_number'] = $payment->id;
        }

        if ($this->fitsConvergeLimit($consultation->booking_number, 17)) {
            $payload['ssl_customer_code'] = $consultation->booking_number;
        }

        return $payload;
    }

    private function hostedPaymentTokenEndpoint(): string
    {
        return rtrim($this->hostedPaymentBaseUrl(), '/') . '/hosted-payments/transaction_token';
    }

    private function returnUrl(PaymentRequest $payment): string
    {
        return route('payments.converge.return.payment', ['paymentRequest' => $payment]);
    }

    private function fitsConvergeLimit(?string $value, int $maxLength): bool
    {
        return filled($value) && strlen($value) <= $maxLength;
    }

    public function lookupPaymentStatus(PaymentRequest $payment, array $callbackPayload = []): array
    {
        $this->assertCredentialsConfigured();

        $response = Http::asForm()
            ->accept('*/*')
            ->connectTimeout(10)
            ->timeout((int) config('services.converge.http_timeout_seconds', 90))
            ->post($this->xmlEndpoint(), [
                'xmldata' => $this->transactionQueryXml($payment, $callbackPayload),
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Converge payment status lookup failed: ' . $response->body());
        }

        $payload = $this->parseXmlResponse($response->body());

        return [
            'status'         => $this->statusFromResponse($payload),
            'transaction_id' => $payload['ssl_txn_id'] ?? null,
            'approval_code'  => $payload['ssl_approval_code'] ?? null,
            'result_message' => $payload['ssl_result_message'] ?? $payload['errorMessage'] ?? null,
            'raw'            => $payload,
        ];
    }

    private function transactionQueryXml(PaymentRequest $payment, array $callbackPayload = []): string
    {
        $fields = [
            'ssl_account_id'       => config('services.converge.account_id'),
            'ssl_user_id'          => config('services.converge.user_id'),
            'ssl_pin'              => config('services.converge.pin'),
            'ssl_transaction_type' => 'txnquery',
        ];

        $transactionId = $callbackPayload['ssl_txn_id'] ?? null;

        if (filled($transactionId)) {
            $fields['ssl_txn_id'] = $transactionId;
        } elseif ($this->fitsConvergeLimit($payment->id, 25)) {
            $fields['ssl_invoice_number'] = $payment->id;
        } elseif (filled($payment->provider_reference) && ! str_starts_with($payment->provider_reference, 'conv_')) {
            $fields['ssl_txn_id'] = $payment->provider_reference;
        } else {
            throw new \RuntimeException('Converge transaction lookup requires ssl_txn_id because this payment id is too long for ssl_invoice_number.');
        }

        $xml = '<txn>';
        foreach ($fields as $key => $value) {
            $xml .= '<' . $key . '>' . htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</' . $key . '>';
        }

        return $xml . '</txn>';
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

        return rtrim($this->gatewayBaseUrl(), '/') . $path;
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
        foreach (['account_id', 'user_id', 'pin'] as $key) {
            if (blank(config('services.converge.' . $key))) {
                throw new \RuntimeException('CONVERGE_' . strtoupper($key) . ' is not configured.');
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
