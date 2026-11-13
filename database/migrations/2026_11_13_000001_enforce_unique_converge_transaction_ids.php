<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('payment_requests')
            ->select('provider', 'transaction_id')
            ->whereNotNull('transaction_id')
            ->groupBy('provider', 'transaction_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate) {
            throw new RuntimeException('Duplicate payment transaction IDs must be reviewed before applying the unique constraint.');
        }

        Schema::table('payment_requests', function (Blueprint $table) {
            $table->unique(['provider', 'transaction_id'], 'payment_provider_transaction_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropUnique('payment_provider_transaction_unique');
        });
    }
};
