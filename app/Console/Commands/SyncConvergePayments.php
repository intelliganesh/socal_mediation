<?php

namespace App\Console\Commands;

use App\Models\Consultation;
use App\Models\PaymentRequest;
use App\Services\PaymentReconciliationService;
use Illuminate\Console\Command;

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
        $paymentRequest = $this->option('payment-request')
            ? PaymentRequest::findOrFail($this->option('payment-request'))
            : null;

        $consultation = $this->option('consultation')
            ? Consultation::findOrFail($this->option('consultation'))
            : null;

        $result = $payments->syncPendingPayments(
            $consultation,
            $paymentRequest,
            $this->option('limit') !== null ? (int) $this->option('limit') : null,
            (bool) $this->option('dry-run')
        );

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
