<?php

/** Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Aplica el cambio de esquema correspondiente a esta migración. */
    public function up(): void
    {
        // RN01: evita también la carrera entre dos altas idénticas sin documento.
        DB::statement('CREATE UNIQUE INDEX patients_without_identity_unique ON patients
            (lower(trim(first_name)), lower(trim(last_name)), birth_date, phone) WHERE identity IS NULL');
    }

    /** Describe la reversión de esta migración; se utiliza únicamente al solicitar un rollback. */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS patients_without_identity_unique');
    }
};
