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
        $status = $this->statusFromPayload($payload);

        if ($status === 'unknown') {
            $payment->integrationLogs()->create([
                'provider' => 'converge',
                'action' => 'payment_confirmation',
                'status' => 'skipped',
                'request_payload' => $this->safePayload($payload),
                'message' => 'Converge confirmation did not include a final payment status.',
            ]);

            return $payment->consultation->refresh();
        }

        return $this->applyStatus($payment, [
            'status' => $status,
            'transaction_id' => $payload['ssl_txn_id'] ?? $payload['transaction_id'] ?? null,
            'approval_code' => $payload['ssl_approval_code'] ?? null,
            'result_message' => $payload['ssl_result_message'] ?? $payload['message'] ?? null,
            'raw' => $payload,
        ], 'payment_confirmation');
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
                    $payment->integrationLogs()->create([
                        'provider' => 'converge',
                        'action' => 'payment_status_sync',
                        'status' => 'failed',
                        'message' => $exception->getMessage(),
                    ]);

                    return;
                }

                $status = $result['status'] ?? 'unknown';
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
            $payment->integrationLogs()->create([
                'provider' => 'converge',
                'action' => $source,
                'status' => 'skipped',
                'response_payload' => $this->safePayload($result['raw'] ?? $result),
                'message' => 'Payment request is already marked paid.',
            ]);

            return $payment->consultation->refresh();
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
            'provider_reference' => $result['transaction_id'] ?? $payment->provider_reference,
            'paid_at' => $status === 'paid' ? now() : $payment->paid_at,
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
            ->whereHas('consultation', function (Builder $query) {
                $query->whereIn('payment_status', ['pending', 'partially_paid'])
                    ->where('created_at', '>=', CarbonImmutable::now()->subDays((int) config('services.converge.payment_sync_lookback_days', 30)));
            })
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
        }

        $providerReference = $payload['provider_reference'] ?? $payload['ssl_txn_id'] ?? null;
        if ($providerReference !== null) {
            return PaymentRequest::where('provider_reference', $providerReference)->firstOrFail();
        }

        abort(422, 'Payment reference is required.');
    }

    private function statusFromPayload(array $payload): string
    {
        if (($payload['status'] ?? null) === 'paid') {
            return 'paid';
        }

        if (in_array(($payload['status'] ?? null), ['failed', 'cancelled'], true)) {
            return 'failed';
        }

        if (array_key_exists('ssl_result', $payload)) {
            return (string) $payload['ssl_result'] === '0' ? 'paid' : 'failed';
        }

        return 'unknown';
    }

    private function safePayload(array $payload): array
    {
        foreach (['ssl_pin', 'pin', 'CONVERGE_PIN'] as $secretKey) {
            if (array_key_exists($secretKey, $payload)) {
                $payload[$secretKey] = '[FILTERED]';
            }
        }

        return $payload;
    }
}
