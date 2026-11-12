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
        // A stable interval identity shared by both modalities. Availability is derived, never cached here.
        Schema::create('clinical_slots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $t->date('date');
            $t->time('start_time');
            $t->time('end_time');
            $t->unique(['doctor_id', 'date', 'start_time', 'end_time']);
        });
        DB::statement('ALTER TABLE clinical_slots ADD CONSTRAINT clinical_slots_valid_range CHECK (start_time < end_time)');
        foreach (['appointments', 'booking_requests'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignId('clinical_slot_id')->nullable()->constrained()->restrictOnDelete());
            DB::statement("INSERT INTO clinical_slots (doctor_id, date, start_time, end_time) SELECT DISTINCT doctor_id, date, start_time, end_time FROM {$table} ON CONFLICT DO NOTHING");
            DB::statement("UPDATE {$table} AS a SET clinical_slot_id = s.id FROM clinical_slots AS s WHERE s.doctor_id = a.doctor_id AND s.date = a.date AND s.start_time = a.start_time AND s.end_time = a.end_time");
        }
        DB::statement("ALTER TABLE booking_requests ADD CONSTRAINT booking_requests_valid_values CHECK (start_time < end_time AND amount_minor >= 0 AND attempt BETWEEN 0 AND 2 AND modality IN ('presential','online') AND status IN ('held','processing','completed','expired'))");
        DB::statement("ALTER TABLE booking_requests ADD CONSTRAINT booking_requests_no_overlap EXCLUDE USING gist (int8range(doctor_id, doctor_id, '[]') WITH &&, tsrange(date + start_time, date + end_time, '[)') WITH &&) WHERE (status IN ('held','processing'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_valid_values CHECK (amount_minor > 0 AND attempt BETWEEN 1 AND 2 AND status IN ('initializing','unknown','pending','confirmed','failed'))");
    }

    /** Describe la reversión de esta migración; se utiliza únicamente al solicitar un rollback. */
    public function down(): void
    {
        throw new RuntimeException('Las franjas identificadas pertenecen al historial clínico y económico.');
    }
};
