<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('consultations')
            ->where('status', 'pending_payment')
            ->update(['status' => 'payment_pending']);

        DB::table('consultations')
            ->where('status', 'partially_paid')
            ->update(['status' => 'payment_pending']);

        DB::table('consultations')
            ->where('status', 'details_complete')
            ->update(['status' => 'draft']);

        DB::table('consultations')
            ->whereIn('status', ['failed', 'error'])
            ->update(['status' => 'cancelled']);

        DB::table('consultations')
            ->whereIn('payment_status', ['not_started', 'not_required', 'cancelled', 'failed', 'error'])
            ->update(['payment_status' => 'pending']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE consultations MODIFY payment_status VARCHAR(40) NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE consultations MODIFY payment_status VARCHAR(40) NOT NULL DEFAULT 'not_started'");
        }
    }
};
