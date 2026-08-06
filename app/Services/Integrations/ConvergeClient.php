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

        $payload = [
            'ssl_account_id'       => config('services.converge.account_id'),
            'ssl_user_id'          => config('services.converge.user_id'),
            'ssl_pin'              => config('services.converge.pin'),
            'ssl_transaction_type' => $payment->payment_method === 'ach' ? 'ecspurchase' : 'ccsale',
            'ssl_amount'           => number_format($payment->amount_cents / 100, 2, '.', ''),
            'ssl_description'      => Str::limit($consultation->booking_number . ' - ' . $consultation->type?->name, 255, ''),
            'ssl_first_name'       => $participant?->first_name ?: $consultation->primary_first_name,
            'ssl_last_name'        => $participant?->last_name ?: $consultation->primary_last_name,
            'ssl_email'            => $participant?->email ?: $consultation->primary_email,
            // 'ssl_result_format'       => 'html',
            // 'ssl_receipt_link_method' => 'REDG',
            // 'ssl_receipt_link_url'    => $returnUrl,
            // 'ssl_error_url'           => $returnUrl,
        ];

        if ($this->fitsConvergeLimit($payment->provider_reference, 25)) {
            $payload['ssl_invoice_number'] = $payment->provider_reference;
        } elseif ($this->fitsConvergeLimit($payment->id, 25)) {
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
        $transaction = $this->transactionFromQueryResponse($payload, $payment);

        return [
            'status'         => $this->statusFromResponse($transaction),
            'transaction_id' => $transaction['ssl_txn_id'] ?? null,
            'approval_code'  => $transaction['ssl_approval_code'] ?? null,
            'result_message' => $transaction['ssl_result_message'] ?? $transaction['errorMessage'] ?? null,
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
        } elseif (filled($payment->provider_reference) && ! str_starts_with($payment->provider_reference, 'conv_')) {
            $fields['ssl_txn_id'] = $payment->provider_reference;
        } elseif ($this->fitsConvergeLimit($payment->provider_reference, 25)) {
            $fields['ssl_invoice_number'] = $payment->provider_reference;
        } elseif ($this->fitsConvergeLimit($payment->id, 25)) {
            $fields['ssl_invoice_number'] = $payment->id;
        } else {
            throw new \RuntimeException('Converge transaction lookup requires a transaction ID or an invoice number no longer than 25 characters.');
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

    private function transactionFromQueryResponse(array $payload, PaymentRequest $payment): array
    {
        if (array_key_exists('ssl_result', $payload) || array_key_exists('errorCode', $payload)) {
            return $payload;
        }

        $transactions = data_get($payload, 'transactions.transaction')
            ?? data_get($payload, 'transactions.txn')
            ?? data_get($payload, 'transaction')
            ?? data_get($payload, 'txn');

        if (! is_array($transactions) || $transactions === []) {
            return $payload;
        }

        $candidates = array_is_list($transactions) ? $transactions : [$transactions];
        $expectedAmount = number_format($payment->amount_cents / 100, 2, '.', '');
        $matching = array_values(array_filter($candidates, static function ($transaction) use ($expectedAmount) {
            if (! is_array($transaction)) {
                return false;
            }

            return ! isset($transaction['ssl_amount'])
                || number_format((float) $transaction['ssl_amount'], 2, '.', '') === $expectedAmount;
        }));

        if ($matching === []) {
            return [
                'errorCode' => 'amount_mismatch',
                'errorMessage' => 'Converge transaction amount did not match the payment request.',
            ];
        }

        foreach ($matching as $transaction) {
            if ((string) ($transaction['ssl_result'] ?? '') === '0') {
                return $transaction;
            }
        }

        return $matching[array_key_last($matching)];
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
