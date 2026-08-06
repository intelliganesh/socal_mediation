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
                ->whereIn('status', ['pending', 'failed'])
                ->whereKey($paymentRequest->id);
        }

        return PaymentRequest::query()
            ->with('consultation')
            ->where('provider', 'converge')
            ->whereIn('status', ['pending', 'failed'])
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

            $payment = PaymentRequest::where('provider_reference', $reference)->first();
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
