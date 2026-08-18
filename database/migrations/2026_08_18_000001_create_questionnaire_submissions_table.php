<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questionnaire_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('consultation_participants')->cascadeOnDelete();
            $table->string('template_key', 80)->index();
            $table->unsignedInteger('template_version')->default(1);
            $table->string('token', 96)->unique();
            $table->string('status', 40)->default('pending')->index();
            $table->json('answers')->nullable();
            $table->boolean('agreement_accepted')->default(false);
            $table->dateTime('agreement_accepted_at')->nullable();
            $table->string('agreement_version', 40)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->dateTime('invited_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();

            $table->unique('participant_id', 'questionnaire_submissions_participant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questionnaire_submissions');
    }
};
