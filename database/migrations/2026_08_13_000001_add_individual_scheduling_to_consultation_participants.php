<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultation_participants', function (Blueprint $table) {
            $table->string('scheduling_status', 40)->default('pending')->after('share_amount_cents')->index();
            $table->dateTime('scheduled_starts_at')->nullable()->after('scheduling_status')->index();
            $table->dateTime('scheduled_ends_at')->nullable()->after('scheduled_starts_at')->index();
            $table->string('scheduled_timezone', 80)->nullable()->after('scheduled_ends_at');
            $table->string('scheduling_token', 80)->nullable()->after('scheduled_timezone');
            $table->unique('scheduling_token', 'consultation_participants_scheduling_token_unique');
            $table->dateTime('scheduling_invited_at')->nullable()->after('scheduling_token');
            $table->dateTime('confirmed_at')->nullable()->after('scheduling_invited_at');
        });
    }

    public function down(): void
    {
        Schema::table('consultation_participants', function (Blueprint $table) {
            $table->dropUnique('consultation_participants_scheduling_token_unique');
            $table->dropColumn([
                'scheduling_status',
                'scheduled_starts_at',
                'scheduled_ends_at',
                'scheduled_timezone',
                'scheduling_token',
                'scheduling_invited_at',
                'confirmed_at',
            ]);
        });
    }
};
