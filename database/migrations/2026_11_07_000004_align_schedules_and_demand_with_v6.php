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
        DB::statement('ALTER TABLE schedules DROP CONSTRAINT schedules_range_check');
        DB::statement('ALTER TABLE schedules ADD CONSTRAINT schedules_range_check CHECK (weekday BETWEEN 0 AND 6 AND start_time < end_time AND duration > 0)');
        Schema::table('agenda_adjustments', function (Blueprint $table) {
            $table->uuid('demand_batch')->nullable()->index();
            $table->unsignedInteger('demand_quantity')->nullable();
        });
    }

    /** Describe la reversión de esta migración; se utiliza únicamente al solicitar un rollback. */
    public function down(): void
    {
        throw new RuntimeException('La reversión requiere revisar duraciones e historial de demanda sin pérdida de datos.');
    }
};
