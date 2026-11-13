<?php

namespace App\Services;

use App\Exceptions\UnlinkedConvergeTransactionException;
use App\Models\Consultation;
use App\Models\PaymentRequest;
use App\Services\Integrations\ConvergeClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;

class PaymentReconciliationService
{
    public function __construct(
        private readonly ConvergeClient $converge,
        private readonly BookingFinalizer $finalizer,
    ) {}

    public function confirmFromConvergePayload(array $payload): Consultation
    {
        $payment = $this->findPaymentFromPayload($payload);

        return $this->verifyPayment($payment, 'payment_confirmation', $payload);
    }

    public function verifyPayment(PaymentRequest $payment, string $source = 'payment_confirmation', array $callbackPayload = []): Consultation
    {
        if ($payment->provider !== 'converge') {
            throw new \DomainException('Only Converge payment requests can be verified through Converge.');
        }

        if ($payment->status === 'paid') {
            return $payment->consultation->refresh();
        }

        try {
            $result = $this->converge->lookupPaymentStatus($payment, $callbackPayload);
        } catch (\Throwable $exception) {
            $payment->integrationLogs()->create([
                'provider' => 'converge',
                'action' => $source,
                'status' => 'failed',
                'request_payload' => $this->safePayload($callbackPayload),
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $status = $result['status'] ?? 'unknown';

        if ($status === 'unknown') {
            if ($this->isUnlinkedTransaction($result)) {
                $payment->integrationLogs()->create([
                    'provider' => 'converge',
                    'action' => $source,
                    'status' => 'failed',
                    'request_payload' => $this->safePayload($callbackPayload),
                    'response_payload' => $this->safePayload($result),
                    'message' => 'Converge transaction could not be linked to this payment.',
                ]);

                throw new UnlinkedConvergeTransactionException();
            }

            $payment->integrationLogs()->create([
                'provider' => 'converge',
                'action' => $source,
                'status' => 'skipped',
                'request_payload' => $this->safePayload($callbackPayload),
                'response_payload' => $this->safePayload($result['raw'] ?? $result),
                'message' => 'Converge verification did not return a final payment status.',
            ]);

            return $payment->consultation->refresh();
        }

        $result['raw'] = [
            'callback' => $this->safePayload($callbackPayload),
            'verification' => $this->safePayload($result['raw'] ?? $result),
        ];

        return $this->applyStatus($payment, $result, $source);
    }

    public function processReturnPayload(PaymentRequest $payment, array $payload): Consultation
    {
        if ($payment->provider !== 'converge') {
            throw new \DomainException('Only Converge payment requests can process a Converge return.');
        }

        $transactionId = trim((string) ($payload['ssl_txn_id'] ?? ''));
        $metadata = array_merge($payment->metadata ?? [], [
            'payment_return' => [
                'received_at' => now()->toIso8601String(),
                'transaction_id' => $transactionId !== '' ? $transactionId : null,
                'result' => isset($payload['ssl_result']) ? (string) $payload['ssl_result'] : null,
                'result_message' => $payload['ssl_result_message'] ?? null,
                'raw' => $this->safePayload($payload),
            ],
        ]);

        $payment->update([
            'metadata' => $metadata,
        ]);

        $payment->integrationLogs()->create([
            'provider' => 'converge',
            'action' => 'payment_return',
            'status' => 'pending',
            'request_payload' => $this->safePayload($payload),
            'message' => 'Converge browser return recorded as untrusted pending server verification.',
        ]);

        return $this->verifyPayment($payment->refresh(), 'payment_return_verification', $payload);
    }

    public function syncPendingPayments(?Consultation $consultation = null, ?PaymentRequest $paymentRequest = null, ?int $limit = null, bool $dryRun = false): array
    {
        if (! config('services.converge.payment_sync_enabled') && $paymentRequest === null) {
            return ['checked' => 0, 'paid' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $checked = $paid = $failed = $skipped = $errors = 0;

        $this->pendingPaymentQuery($consultation, $paymentRequest)
            ->limit($limit ?? (int) config('services.converge.payment_sync_batch_size', 50))
            ->get()
            ->each(function (PaymentRequest $payment) use (&$checked, &$paid, &$failed, &$skipped, &$errors, $dryRun) {
                $checked++;

                try {
                    $result = $this->converge->lookupPaymentStatus($payment);
                } catch (\Throwable $exception) {
                    $errors++;
                    $payment->update([
                        'last_status_check_at' => now(),
                        'txnquery_response' => ['error' => $exception->getMessage()],
                    ]);
                    $payment->integrationLogs()->create([
                        'provider' => 'converge',
                        'action' => 'payment_status_sync',
                        'status' => 'failed',
                        'message' => $exception->getMessage(),
                    ]);

                    return;
                }

                $status = $result['status'] ?? 'unknown';
                $payment->update([
                    'last_status_check_at' => now(),
                    'txnquery_response' => $this->safePayload($result['raw'] ?? $result),
                ]);

                if ($status === 'unknown') {
                    $skipped++;
                    $payment->integrationLogs()->create([
                        'provider' => 'converge',
                        'action' => 'payment_status_sync',
                        'status' => 'skipped',
                        'response_payload' => $this->safePayload($result['raw'] ?? $result),
                        'message' => 'Converge status lookup did not return a final payment status.',
                    ]);

                    return;
                }

                if ($dryRun) {
                    $status === 'paid' ? $paid++ : $failed++;

                    return;
                }

                $this->applyStatus($payment, $result, 'payment_status_sync');
                $status === 'paid' ? $paid++ : $failed++;
            });

        return compact('checked', 'paid', 'failed', 'skipped', 'errors');
    }

    public function syncConsultation(Consultation $consultation): array
    {
        return $this->syncPendingPayments($consultation, null, null, false);
    }

    public function syncLightweight(int $limit = 5): array
    {
        return $this->syncPendingPayments(null, null, $limit, false);
    }

    private function applyStatus(PaymentRequest $payment, array $result, string $source): Consultation
    {
        if ($payment->status === 'paid') {
            $metadata = array_merge($payment->metadata ?? [], [
                $source => [
                    'synced_at' => now()->toIso8601String(),
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'approval_code' => $result['approval_code'] ?? null,
                    'result_message' => $result['result_message'] ?? null,
                    'raw' => $this->safePayload($result['raw'] ?? $result),
                ],
            ]);
            $payment->update(['metadata' => $metadata]);
            $payment->integrationLogs()->create([
                'provider' => 'converge',
                'action' => $source,
                'status' => 'skipped',
                'response_payload' => $this->safePayload($result['raw'] ?? $result),
                'message' => 'Payment request is already marked paid.',
            ]);

            return $this->finalizer->syncPaymentStatus($payment->consultation);
        }

        $status = $result['status'] ?? 'unknown';
        $transactionId = $result['transaction_id'] ?? null;

        if ($status === 'paid') {
            try {
                $this->assertTransactionAvailable($payment, $transactionId);
            } catch (\DomainException $exception) {
                $this->logRejectedTransaction($payment, $source, $result, $exception->getMessage());

                throw $exception;
            }
        }

        $metadata = array_merge($payment->metadata ?? [], [
            $source => [
                'synced_at' => now()->toIso8601String(),
                'transaction_id' => $result['transaction_id'] ?? null,
                'approval_code' => $result['approval_code'] ?? null,
                'result_message' => $result['result_message'] ?? null,
                'raw' => $this->safePayload($result['raw'] ?? $result),
            ],
        ]);

        if ($status === 'paid') {
            $transactionIds = array_values(array_unique(array_filter([
                ...((array) ($metadata['converge_transaction_ids'] ?? [])),
                $transactionId,
            ])));
            $metadata['converge_transaction_id'] = $transactionId;
            $metadata['converge_transaction_ids'] = $transactionIds;
            $metadata['converge_transaction_received_at'] = now()->toIso8601String();
            $metadata['gateway_verification'] = [
                'verified_at' => now()->toIso8601String(),
                'source' => $source,
                'transaction_id' => $transactionId,
            ];
        }

        try {
            $payment->update([
                'status' => $status,
                'transaction_id' => $transactionId ?? $payment->transaction_id,
                'paid_at' => $status === 'paid' ? now() : $payment->paid_at,
                'approval_code' => $result['approval_code'] ?? $payment->approval_code,
                'metadata' => $metadata,
            ]);
        } catch (QueryException $exception) {
            if ($transactionId !== null && $this->transactionBelongsToAnotherPayment($payment, $transactionId)) {
                $message = 'This Converge transaction has already been applied to another payment request.';
                $this->logRejectedTransaction($payment, $source, $result, $message);

                throw new \DomainException($message, previous: $exception);
            }

            throw $exception;
        }

        $payment->integrationLogs()->create([
            'provider' => 'converge',
            'action' => $source,
            'status' => $status,
            'response_payload' => $metadata[$source],
            'message' => $status === 'paid' ? 'Payment marked paid from Converge status.' : 'Payment marked failed from Converge status.',
        ]);

        return $this->finalizer->syncPaymentStatus($payment->consultation);
    }

    private function logRejectedTransaction(PaymentRequest $payment, string $source, array $result, string $message): void
    {
        $payment->integrationLogs()->create([
            'provider' => 'converge',
            'action' => $source,
            'status' => 'failed',
            'response_payload' => $this->safePayload($result),
            'message' => $message,
        ]);
    }

    private function isUnlinkedTransaction(array $result): bool
    {
        return in_array($result['verification_error_code'] ?? null, [
            'returned_transaction_id_mismatch',
            'transaction_id_missing',
            'transaction_id_mismatch',
            'amount_missing',
            'amount_mismatch',
            'invoice_missing',
            'invoice_mismatch',
        ], true);
    }

    private function assertTransactionAvailable(PaymentRequest $payment, mixed $transactionId): void
    {
        if (! is_scalar($transactionId) || blank(trim((string) $transactionId))) {
            throw new \DomainException('Verified Converge payment is missing a transaction ID.');
        }

        $transactionId = trim((string) $transactionId);

        if ($this->transactionBelongsToAnotherPayment($payment, $transactionId)) {
            throw new \DomainException('This Converge transaction has already been applied to another payment request.');
        }
    }

    private function transactionBelongsToAnotherPayment(PaymentRequest $payment, mixed $transactionId): bool
    {
        return PaymentRequest::query()
            ->where('provider', $payment->provider)
            ->where('transaction_id', (string) $transactionId)
            ->whereKeyNot($payment->id)
            ->exists();
    }

    private function pendingPaymentQuery(?Consultation $consultation, ?PaymentRequest $paymentRequest): Builder
    {
        if ($consultation) {
            return PaymentRequest::query()
                ->with('consultation')
                ->where('provider', 'converge')
                ->where('status', 'pending')
                ->where('consultation_id', $consultation->id)
                ->orderBy('created_at');
        }

        if ($paymentRequest) {
            return PaymentRequest::query()
                ->with('consultation')
                ->where('provider', 'converge')
                ->whereKey($paymentRequest->id);
        }

        return PaymentRequest::query()
            ->with('consultation')
            ->where('provider', 'converge')
            ->where('status', 'pending')
            ->where(function (Builder $query) {
                $query->whereNotNull('payment_url')
                    ->orWhereNotNull('provider_reference');
            })
            ->where('created_at', '>=', CarbonImmutable::now()->subDays((int) config('services.converge.payment_sync_lookback_days', 30)))
            ->orderBy('created_at');
    }

    private function findPaymentFromPayload(array $payload): PaymentRequest
    {
        $reference = $payload['payment_request_id']
            ?? $payload['payment_request_uuid']
            ?? $payload['app_payment_reference']
            ?? $payload['ssl_invoice_number']
            ?? $payload['invoice_number']
            ?? $payload['provider_reference']
            ?? null;

        if (! is_scalar($reference) || blank(trim((string) $reference))) {
            abort(422, 'Payment reference is required.');
        }

        $reference = trim((string) $reference);

        $payment = PaymentRequest::query()
            ->where('provider', 'converge')
            ->where(function (Builder $query) use ($reference) {
                $query->whereKey($reference)
                    ->orWhere('provider_reference', $reference)
                    ->orWhere('metadata->invoice_number', $reference);
            })
            ->first();

        if ($payment === null) {
            abort(422, 'Payment reference is invalid.');
        }

        return $payment;
    }

    private function safePayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (preg_match('/pin|cvv|cvc|card|account|routing|token|exp_date/i', (string) $key)) {
                $payload[$key] = '[FILTERED]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->safePayload($value);
            }
        }

        return $payload;
    }
}
