<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->string('transaction_id')->nullable()->index()->after('provider_reference');
            $table->string('approval_code')->nullable()->after('paid_at');
            $table->dateTime('last_status_check_at')->nullable()->after('approval_code');
            $table->json('txnquery_response')->nullable()->after('last_status_check_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropIndex(['transaction_id']);
            $table->dropColumn([
                'transaction_id',
                'approval_code',
                'last_status_check_at',
                'txnquery_response',
            ]);
        });
    }
};
