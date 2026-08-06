<?php

namespace App\Console\Commands;

use App\Models\Consultation;
use App\Models\PaymentRequest;
use App\Services\PaymentReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncConvergePayments extends Command
{
    protected $signature = 'payments:sync-converge
        {--payment-request= : Sync one payment request UUID}
        {--consultation= : Sync pending payment requests for one consultation UUID}
        {--limit= : Maximum pending payment requests to check}
        {--dry-run : Query Converge but do not update local payment status}';

    protected $description = 'Reconcile pending Converge payment requests through XML API transaction lookup.';

    public function handle(PaymentReconciliationService $payments): int
    {
        $context = [
            'payment_request_id' => $this->option('payment-request'),
            'consultation_id' => $this->option('consultation'),
            'limit' => $this->option('limit') !== null ? (int) $this->option('limit') : null,
            'dry_run' => (bool) $this->option('dry-run'),
        ];

        Log::info('Converge payment cron started.', $context);

        try {
            $paymentRequest = $context['payment_request_id']
                ? PaymentRequest::findOrFail($context['payment_request_id'])
                : null;

            $consultation = $context['consultation_id']
                ? Consultation::findOrFail($context['consultation_id'])
                : null;

            $result = $payments->syncPendingPayments(
                $consultation,
                $paymentRequest,
                $context['limit'],
                $context['dry_run']
            );
        } catch (Throwable $exception) {
            Log::error('Converge payment cron failed.', $context + [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->components->error('Converge payment sync failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        Log::info('Converge payment cron completed.', $context + $result);

        $this->components->info(sprintf(
            'Converge payment sync checked %d payment(s): %d paid, %d failed, %d skipped, %d error(s).',
            $result['checked'],
            $result['paid'],
            $result['failed'],
            $result['skipped'],
            $result['errors'],
        ));

        return self::SUCCESS;
    }
}
