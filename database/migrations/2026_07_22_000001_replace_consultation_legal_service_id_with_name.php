<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('consultations', 'legal_service_name')) {
            Schema::table('consultations', function (Blueprint $table) {
                $table->string('legal_service_name')->nullable()->after('consultation_type_id');
            });
        }

        if (Schema::hasColumn('consultations', 'legal_service_id') && Schema::hasTable('legal_services')) {
            DB::table('consultations')
                ->leftJoin('legal_services', 'consultations.legal_service_id', '=', 'legal_services.id')
                ->whereNotNull('consultations.legal_service_id')
                ->update(['consultations.legal_service_name' => DB::raw('legal_services.name')]);

            Schema::table('consultations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('legal_service_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('consultations', 'legal_service_id')) {
            Schema::table('consultations', function (Blueprint $table) {
                $table->foreignId('legal_service_id')->nullable()->after('consultation_type_id')->constrained()->nullOnDelete();
            });
        }

        if (Schema::hasColumn('consultations', 'legal_service_name') && Schema::hasTable('legal_services')) {
            DB::table('consultations')
                ->leftJoin('legal_services', 'consultations.legal_service_name', '=', 'legal_services.name')
                ->whereNotNull('consultations.legal_service_name')
                ->update(['consultations.legal_service_id' => DB::raw('legal_services.id')]);

            Schema::table('consultations', function (Blueprint $table) {
                $table->dropColumn('legal_service_name');
            });
        }
    }
};
