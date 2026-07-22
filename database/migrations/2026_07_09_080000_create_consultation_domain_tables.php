<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_types', function (Blueprint $table) {
            $table->id();
            $table->string('application', 40)->index();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('price_cents');
            $table->string('currency', 3)->default('USD');
            $table->unsignedTinyInteger('max_participants')->default(1);
            $table->boolean('allows_split_payment')->default(false);
            $table->boolean('allows_phone')->default(false);
            $table->boolean('allows_online')->default(true);
            $table->boolean('allows_offline')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('legal_services', function (Blueprint $table) {
            $table->id();
            $table->string('application', 40)->index();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('professionals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('title')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('timezone', 80)->default('America/Los_Angeles');
            $table->string('outlook_calendar_id')->nullable();
            $table->json('applications')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('booking_number')->unique();
            $table->foreignId('consultation_type_id')->constrained()->cascadeOnDelete();
            $table->string('legal_service_name')->nullable();
            $table->foreignId('professional_id')->nullable()->constrained()->nullOnDelete();
            $table->string('application', 40)->index();
            $table->string('status', 40)->default('draft')->index();
            $table->string('payment_status', 40)->default('pending')->index();
            $table->string('consultation_mode', 30)->nullable();
            $table->string('timezone', 80)->default('America/Los_Angeles');
            $table->dateTime('starts_at')->nullable()->index();
            $table->dateTime('ends_at')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('referral_source')->nullable();
            $table->string('primary_first_name')->nullable();
            $table->string('primary_last_name')->nullable();
            $table->string('primary_email')->nullable()->index();
            $table->string('primary_phone_country', 8)->nullable();
            $table->string('primary_phone')->nullable();
            $table->unsignedInteger('total_amount_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('payment_mode', 20)->nullable();
            $table->string('zoom_meeting_id')->nullable();
            $table->text('zoom_join_url')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('consultation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone_country', 8)->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('should_pay')->default(false);
            $table->unsignedInteger('share_amount_cents')->default(0);
            $table->timestamps();
        });

        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('consultation_participants')->nullOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('provider')->default('converge');
            $table->string('status', 40)->default('pending')->index();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('USD');
            $table->string('payment_method', 30)->nullable();
            $table->string('provider_reference')->nullable()->index();
            $table->text('payment_url')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('external_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professional_id')->nullable()->constrained()->nullOnDelete();
            $table->string('application', 40)->nullable()->index();
            $table->string('provider')->default('outlook');
            $table->string('external_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at')->index();
            $table->boolean('is_busy')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('loggable');
            $table->string('provider', 40)->index();
            $table->string('action', 80);
            $table->string('status', 40)->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
        Schema::dropIfExists('external_calendar_events');
        Schema::dropIfExists('payment_requests');
        Schema::dropIfExists('consultation_participants');
        Schema::dropIfExists('consultations');
        Schema::dropIfExists('professionals');
        Schema::dropIfExists('legal_services');
        Schema::dropIfExists('consultation_types');
    }
};
