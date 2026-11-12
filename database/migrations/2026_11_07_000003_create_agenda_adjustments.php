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
        Schema::create('agenda_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->foreignId('specialty_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('kind', 30);
            $table->string('status', 15)->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('released_at')->nullable();
            $table->timestamps();
            $table->index(['doctor_id', 'date']);
        });
        DB::statement("ALTER TABLE agenda_adjustments ADD CONSTRAINT agenda_adjustment_values CHECK (
            start_time < end_time AND kind IN ('presential_block', 'online_opening') AND status IN ('active', 'released')
            AND ((kind = 'presential_block' AND specialty_id IS NOT NULL) OR (kind = 'online_opening' AND specialty_id IS NULL)))");
        DB::statement("ALTER TABLE agenda_adjustments ADD CONSTRAINT agenda_adjustments_no_overlap EXCLUDE USING gist (
            int8range(doctor_id, doctor_id, '[]') WITH &&,
            tsrange(date + start_time, date + end_time, '[)') WITH &&) WHERE (status = 'active')");
    }

    /** Describe la reversión de esta migración; se utiliza únicamente al solicitar un rollback. */
    public function down(): void
    {
        if (DB::table('agenda_adjustments')->exists()) {
            throw new RuntimeException('Existen movimientos de agenda; no se elimina su historial.');
        }
        Schema::dropIfExists('agenda_adjustments');
    }
};
