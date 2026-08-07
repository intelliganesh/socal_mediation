<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\PaymentRequest;
use App\Services\Integrations\ConvergeClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class PaymentReconciliationService
{
    public function __construct(
        private readonly ConvergeClient $converge,
        private readonly BookingFinalizer $finalizer,
    ) {
    }

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

        $this->rememberConvergeTransactionId($payment, $callbackPayload);

        if ($payment->status === 'paid') {
            return $payment->consultation->refresh();
        }

        $result = $this->converge->lookupPaymentStatus($payment, $callbackPayload);
        $status = $result['status'] ?? 'unknown';

        if ($status === 'unknown') {
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

        $this->rememberConvergeTransactionId($payment, $payload);
        $payment->refresh();

        $status = $this->statusFromReturnPayload($payment, $payload);
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

        if ($payment->status === 'paid') {
            $status = 'paid';
        }

        $payment->update([
            'status' => $status,
            'transaction_id' => $transactionId !== '' ? $transactionId : $payment->transaction_id,
            'paid_at' => $status === 'paid' ? ($payment->paid_at ?? now()) : $payment->paid_at,
            'approval_code' => $payload['ssl_approval_code'] ?? $payment->approval_code,
            'metadata' => $metadata,
        ]);

        $payment->integrationLogs()->create([
            'provider' => 'converge',
            'action' => 'payment_return',
            'status' => $status,
            'request_payload' => $this->safePayload($payload),
            'message' => match ($status) {
                'paid' => 'Payment marked paid from the Converge return response; background verification is pending.',
                'failed' => 'Payment marked failed from the Converge return response; background verification is pending.',
                default => 'Converge return response did not contain a final payment result.',
            },
        ]);

        return $this->finalizer->syncPaymentStatus($payment->consultation, deferExternalSync: true);
    }

    public function syncPendingPayments(?Consultation $consultation = null, ?PaymentRequest $paymentRequest = null, ?int $limit = null, bool $dryRun = false): array
    {
        if (! config('services.converge.payment_sync_enabled')) {
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
        $metadata = array_merge($payment->metadata ?? [], [
            $source => [
                'synced_at' => now()->toIso8601String(),
                'transaction_id' => $result['transaction_id'] ?? null,
                'approval_code' => $result['approval_code'] ?? null,
                'result_message' => $result['result_message'] ?? null,
                'raw' => $this->safePayload($result['raw'] ?? $result),
            ],
        ]);

        $payment->update([
            'status' => $status,
            'transaction_id' => $result['transaction_id'] ?? $payment->transaction_id,
            'paid_at' => $status === 'paid' ? now() : $payment->paid_at,
            'approval_code' => $result['approval_code'] ?? $payment->approval_code,
            'metadata' => $metadata,
        ]);

        $payment->integrationLogs()->create([
            'provider' => 'converge',
            'action' => $source,
            'status' => $status,
            'response_payload' => $metadata[$source],
            'message' => $status === 'paid' ? 'Payment marked paid from Converge status.' : 'Payment marked failed from Converge status.',
        ]);

        return $this->finalizer->syncPaymentStatus($payment->consultation);
    }

    private function rememberConvergeTransactionId(PaymentRequest $payment, array $payload): void
    {
        $transactionId = $payload['ssl_txn_id'] ?? null;

        if (! is_scalar($transactionId) || blank(trim((string) $transactionId))) {
            return;
        }

        $transactionId = trim((string) $transactionId);
        $metadata = $payment->metadata ?? [];
        $transactionIds = array_values(array_unique(array_filter([
            ...((array) ($metadata['converge_transaction_ids'] ?? [])),
            $transactionId,
        ])));

        $metadata['converge_transaction_id'] = $transactionId;
        $metadata['converge_transaction_ids'] = $transactionIds;
        $metadata['converge_transaction_received_at'] = now()->toIso8601String();

        $payment->update([
            'transaction_id' => $transactionId,
            'metadata' => $metadata,
        ]);
    }

    private function statusFromReturnPayload(PaymentRequest $payment, array $payload): string
    {
        $transactionId = trim((string) ($payload['ssl_txn_id'] ?? ''));
        $result = isset($payload['ssl_result']) ? trim((string) $payload['ssl_result']) : null;
        $resultMessage = strtoupper(trim((string) ($payload['ssl_result_message'] ?? '')));
        $amount = $payload['ssl_amount'] ?? null;

        if ($amount !== null && (! is_numeric($amount)
            || (int) round((float) $amount * 100) !== (int) $payment->amount_cents)) {
            return 'pending';
        }

        if ($transactionId !== '' && $result === '0' && $resultMessage === 'APPROVAL') {
            return 'paid';
        }

        if ($result !== null && $result !== '' && $result !== '0') {
            return 'failed';
        }

        return 'pending';
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
                ->where('status', 'pending')
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
            ?? null;

        if ($reference !== null) {
            $payment = PaymentRequest::find($reference);
            if ($payment) {
                return $payment;
            }

            $payment = PaymentRequest::where('provider_reference', $reference)->first();
            if ($payment) {
                return $payment;
            }

            $payment = PaymentRequest::where('transaction_id', $reference)->first();
            if ($payment) {
                return $payment;
            }
        }

        $providerReference = $payload['provider_reference'] ?? $payload['ssl_txn_id'] ?? null;
        if ($providerReference !== null) {
            return PaymentRequest::query()
                ->where('transaction_id', $providerReference)
                ->orWhere('provider_reference', $providerReference)
                ->firstOrFail();
        }

        abort(422, 'Payment reference is required.');
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
