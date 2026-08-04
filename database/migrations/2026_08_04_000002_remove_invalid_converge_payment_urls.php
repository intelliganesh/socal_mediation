<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

return new class extends Migration
{
    public function up(): void
    {
        $payments = DB::table('payment_requests')
            ->where('provider', 'converge')
            ->whereNot('status', 'paid');

        if (config('services.converge.enabled')) {
            $payments->where(function ($query): void {
                $query->where('payment_url', 'like', 'https://pay.demo.convergepay.com/%')
                    ->orWhere('payment_url', 'like', '%/pay/conv_%');
            });
        }

        $payments
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $payment): void {
                $paymentUrl = config('services.converge.enabled')
                    ? URL::signedRoute('payments.checkout', [
                        'paymentRequest' => $payment->id,
                    ])
                    : null;

                DB::table('payment_requests')
                    ->where('id', $payment->id)
                    ->update([
                        'payment_url' => $paymentUrl,
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // Invalid external URLs must not be restored.
    }
};
