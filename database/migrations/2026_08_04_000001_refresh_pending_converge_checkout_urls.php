<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('payment_requests')
            ->where('provider', 'converge')
            ->whereNot('status', 'paid')
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $payment): void {
                DB::table('payment_requests')
                    ->where('id', $payment->id)
                    ->update([
                        'payment_url' => URL::signedRoute('payments.checkout', [
                            'paymentRequest' => $payment->id,
                        ]),
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // Previous one-time Converge session URLs cannot be restored safely.
    }
};
