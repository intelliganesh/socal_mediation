<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('consultations', 'uuid') || ! Schema::hasColumn('payment_requests', 'uuid')) {
            return;
        }

        $driver = DB::getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $this->addColumnIfMissing('consultation_participants', 'consultation_uuid', 'ALTER TABLE consultation_participants ADD consultation_uuid CHAR(36) NULL');
            DB::statement('UPDATE consultation_participants cp JOIN consultations c ON cp.consultation_id = c.id SET cp.consultation_uuid = c.uuid');

            $this->addColumnIfMissing('payment_requests', 'consultation_uuid', 'ALTER TABLE payment_requests ADD consultation_uuid CHAR(36) NULL');
            DB::statement('UPDATE payment_requests pr JOIN consultations c ON pr.consultation_id = c.id SET pr.consultation_uuid = c.uuid');

            if (Schema::hasColumn('integration_logs', 'loggable_id')) {
                $this->addColumnIfMissing('integration_logs', 'loggable_uuid', 'ALTER TABLE integration_logs ADD loggable_uuid CHAR(36) NULL');
                DB::statement("UPDATE integration_logs il JOIN consultations c ON il.loggable_id = c.id SET il.loggable_uuid = c.uuid WHERE il.loggable_type = 'App\\\\Models\\\\Consultation'");
            }

            $this->dropForeignIfExists('consultation_participants', 'consultation_participants_consultation_id_foreign');
            $this->dropForeignIfExists('payment_requests', 'payment_requests_consultation_id_foreign');

            DB::statement('ALTER TABLE consultation_participants DROP COLUMN consultation_id');
            DB::statement('ALTER TABLE consultation_participants CHANGE consultation_uuid consultation_id CHAR(36) NOT NULL');

            DB::statement('ALTER TABLE payment_requests DROP COLUMN consultation_id');
            DB::statement('ALTER TABLE payment_requests CHANGE consultation_uuid consultation_id CHAR(36) NOT NULL');

            if (Schema::hasColumn('integration_logs', 'loggable_uuid')) {
                $this->dropIndexIfExists('integration_logs', 'integration_logs_loggable_type_loggable_id_index');
                DB::statement('ALTER TABLE integration_logs DROP COLUMN loggable_id');
                DB::statement('ALTER TABLE integration_logs CHANGE loggable_uuid loggable_id CHAR(36) NULL');
                $this->addIndexIfMissing('integration_logs', 'integration_logs_loggable_type_loggable_id_index', 'ALTER TABLE integration_logs ADD INDEX integration_logs_loggable_type_loggable_id_index (loggable_type, loggable_id)');
            }

            $this->dropIndexIfExists('consultations', 'consultations_uuid_unique');
            DB::statement('ALTER TABLE consultations DROP PRIMARY KEY');
            DB::statement('ALTER TABLE consultations DROP COLUMN id');
            DB::statement('ALTER TABLE consultations CHANGE uuid id CHAR(36) NOT NULL');
            DB::statement('ALTER TABLE consultations ADD PRIMARY KEY (id)');

            $this->dropIndexIfExists('payment_requests', 'payment_requests_uuid_unique');
            DB::statement('ALTER TABLE payment_requests DROP PRIMARY KEY');
            DB::statement('ALTER TABLE payment_requests DROP COLUMN id');
            DB::statement('ALTER TABLE payment_requests CHANGE uuid id CHAR(36) NOT NULL');
            DB::statement('ALTER TABLE payment_requests ADD PRIMARY KEY (id)');

            DB::statement('ALTER TABLE consultation_participants ADD CONSTRAINT consultation_participants_consultation_id_foreign FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE CASCADE');
            DB::statement('ALTER TABLE payment_requests ADD CONSTRAINT payment_requests_consultation_id_foreign FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE CASCADE');
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function down(): void
    {
        // This migration collapses public UUIDs into primary keys. Reversing it
        // would require inventing replacement integer ids and remapping every
        // related row, so it is intentionally forward-only.
    }

    private function dropForeignIfExists(string $table, string $constraint): void
    {
        if ($this->constraintExists($table, $constraint)) {
            DB::statement("ALTER TABLE {$table} DROP FOREIGN KEY {$constraint}");
        }
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if ($this->indexExists($table, $index)) {
            DB::statement("ALTER TABLE {$table} DROP INDEX {$index}");
        }
    }

    private function addColumnIfMissing(string $table, string $column, string $statement): void
    {
        if (! Schema::hasColumn($table, $column)) {
            DB::statement($statement);
        }
    }

    private function addIndexIfMissing(string $table, string $index, string $statement): void
    {
        if (! $this->indexExists($table, $index)) {
            DB::statement($statement);
        }
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->exists();
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }
};
