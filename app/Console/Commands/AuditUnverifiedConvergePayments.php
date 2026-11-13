<?php

namespace App\Console\Commands;

use App\Models\PaymentRequest;
use Illuminate\Console\Command;

class AuditUnverifiedConvergePayments extends Command
{
    protected $signature = 'payments:audit-unverified-converge';

    protected $description = 'Report paid Converge requests credited from browser returns without gateway verification evidence.';

    public function handle(): int
    {
        $payments = PaymentRequest::query()
            ->with(['consultation:id,booking_number', 'participant:id,email'])
            ->where('provider', 'converge')
            ->where('status', 'paid')
            ->whereNull('metadata->gateway_verification')
            ->whereHas('integrationLogs', function ($query) {
                $query->where('provider', 'converge')
                    ->where('action', 'payment_return')
                    ->where('status', 'paid');
            })
            ->orderBy('created_at')
            ->get();

        if ($payments->isEmpty()) {
            $this->components->info('No unverified Converge browser-return payments were found.');

            return self::SUCCESS;
        }

        $this->components->warn(sprintf(
            '%d paid Converge request(s) require manual review. No records were changed.',
            $payments->count(),
        ));

        $this->table(
            ['Payment Request', 'Booking', 'Participant', 'Transaction', 'Paid At'],
            $payments->map(fn (PaymentRequest $payment) => [
                $payment->id,
                $payment->consultation?->booking_number,
                $payment->participant?->email,
                $payment->transaction_id,
                $payment->paid_at?->toIso8601String(),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
