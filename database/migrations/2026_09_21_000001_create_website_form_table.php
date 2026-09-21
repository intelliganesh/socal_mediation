<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_form', function (Blueprint $table) {
            $table->id();
            $table->string('application', 20)->index();
            $table->string('name');
            $table->string('email')->index();
            $table->string('phone', 50);
            $table->text('message');
            $table->json('extra_fields')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_form');
    }
};
