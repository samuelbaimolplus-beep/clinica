<?php

/** Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Aplica el cambio de esquema correspondiente a esta migración. */
    public function up(): void
    {
        DB::statement('ALTER TABLE booking_requests DROP CONSTRAINT booking_requests_valid_values');
        DB::statement("ALTER TABLE booking_requests ADD CONSTRAINT booking_requests_valid_values CHECK (start_time < end_time AND amount_minor >= 0 AND attempt BETWEEN 0 AND 2 AND modality IN ('presential','online') AND status IN ('held','processing','completed','expired','cancelled'))");
    }

    /** Describe la reversión de esta migración; se utiliza únicamente al solicitar un rollback. */
    public function down(): void
    {
        if (DB::table('booking_requests')->where('status', 'cancelled')->exists()) {
            throw new RuntimeException('No se puede retirar el estado mientras existan solicitudes descartadas.');
        }
        DB::statement('ALTER TABLE booking_requests DROP CONSTRAINT booking_requests_valid_values');
        DB::statement("ALTER TABLE booking_requests ADD CONSTRAINT booking_requests_valid_values CHECK (start_time < end_time AND amount_minor >= 0 AND attempt BETWEEN 0 AND 2 AND modality IN ('presential','online') AND status IN ('held','processing','completed','expired'))");
    }
};
