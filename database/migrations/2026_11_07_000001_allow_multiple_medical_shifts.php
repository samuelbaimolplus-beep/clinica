<?php

/** Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Aplica el cambio de esquema correspondiente a esta migración. */
    public function up(): void
    {
        Schema::table('schedules', fn (Blueprint $table) => $table->dropUnique(['doctor_id', 'weekday']));
        // Los tipos range nativos permiten la exclusión sin instalar btree_gist.
        DB::statement("ALTER TABLE schedules ADD CONSTRAINT schedules_no_overlap EXCLUDE USING gist (
            int8range(doctor_id, doctor_id, '[]') WITH &&,
            int4range(weekday, weekday, '[]') WITH &&,
            tsrange(DATE '2000-01-01' + start_time, DATE '2000-01-01' + end_time, '[)') WITH &&)");
    }

    /** Describe la reversión de esta migración; se utiliza únicamente al solicitar un rollback. */
    public function down(): void
    {
        if (DB::table('schedules')->groupBy('doctor_id', 'weekday')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('No se puede restaurar un único turno diario sin perder horarios.');
        }
        DB::statement('ALTER TABLE schedules DROP CONSTRAINT schedules_no_overlap');
        Schema::table('schedules', fn (Blueprint $table) => $table->unique(['doctor_id', 'weekday']));
    }
};
