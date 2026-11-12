<?php

/** Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Aplica el cambio de esquema correspondiente a esta migración. */
    public function up(): void
    {
        // No redondear silenciosamente configuraciones anteriores. NOT VALID conserva esas filas
        // y exige enteros a toda inserción o actualización posterior.
        DB::statement('ALTER TABLE doctor_quotas ADD CONSTRAINT doctor_quotas_whole_percentages CHECK
            (presential_percent = trunc(presential_percent) AND online_percent = trunc(online_percent)) NOT VALID');
    }

    /** Describe la reversión de esta migración; se utiliza únicamente al solicitar un rollback. */
    public function down(): void
    {
        DB::statement('ALTER TABLE doctor_quotas DROP CONSTRAINT doctor_quotas_whole_percentages');
    }
};
