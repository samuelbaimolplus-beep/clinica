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
        Schema::create('specialties', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120)->unique();
            $t->text('description')->nullable();
            $t->string('status', 10)->default('Activo');
            $t->timestamps();
        });
        Schema::create('doctors', function (Blueprint $t) {
            $t->id();
            $t->string('registration', 80)->unique();
            $t->string('first_name', 100);
            $t->string('last_name', 150);
            $t->string('phone', 30);
            $t->string('email')->unique();
            $t->string('status', 10)->default('Activo');
            $t->timestamps();
        });
        Schema::create('doctor_specialty', function (Blueprint $t) {
            $t->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $t->foreignId('specialty_id')->constrained()->restrictOnDelete();
            $t->primary(['doctor_id', 'specialty_id']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('role', 20)->default('Recepcionista');
            $t->string('status', 10)->default('Activo');
            $t->foreignId('doctor_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        Schema::create('patients', function (Blueprint $t) {
            $t->id();
            $t->string('identity', 40)->unique();
            $t->string('first_name', 100);
            $t->string('last_name', 150);
            $t->date('birth_date');
            $t->string('sex', 20);
            $t->string('phone', 30);
            $t->string('email')->nullable();
            $t->text('address')->nullable();
            $t->string('status', 10)->default('Activo');
            $t->timestamps();
        });
        Schema::create('schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('weekday');
            $t->time('start_time');
            $t->time('end_time');
            $t->unsignedSmallInteger('duration');
            $t->unique(['doctor_id', 'weekday']);
            $t->timestamps();
        });
        Schema::create('unavailabilities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->string('reason', 300);
            $t->timestamps();
            $t->index(['doctor_id', 'starts_at', 'ends_at']);
        });
        Schema::create('appointments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('patient_id')->constrained()->restrictOnDelete();
            $t->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $t->foreignId('specialty_id')->constrained()->restrictOnDelete();
            $t->date('date');
            $t->time('start_time');
            $t->time('end_time');
            $t->string('status', 20)->default('Programada');
            $t->text('reason')->nullable();
            $t->dateTime('attendance_at')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['doctor_id', 'date']);
            $t->index(['patient_id', 'date']);
        });
        Schema::create('appointment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('appointment_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('action', 50);
            $t->jsonb('details');
            $t->timestamp('created_at')->useCurrent();
        });
        foreach (['specialties', 'doctors', 'patients', 'users'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_status_check CHECK (status IN ('Activo', 'Inactivo'))");
        }
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('Administrador', 'Recepcionista', 'Médico'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_doctor_check CHECK ((role = 'Médico' AND doctor_id IS NOT NULL) OR (role <> 'Médico' AND doctor_id IS NULL))");
        DB::statement('ALTER TABLE schedules ADD CONSTRAINT schedules_range_check CHECK (weekday BETWEEN 0 AND 6 AND start_time < end_time AND duration BETWEEN 5 AND 240)');
        DB::statement('ALTER TABLE unavailabilities ADD CONSTRAINT unavailabilities_range_check CHECK (starts_at < ends_at)');
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_status_check CHECK (status IN ('Programada','Confirmada','Atendida','Cancelada','Inasistencia'))");
        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_time_check CHECK (start_time < end_time)');
        DB::statement("CREATE UNIQUE INDEX appointments_active_slot_unique ON appointments (doctor_id, date, start_time) WHERE status IN ('Programada','Confirmada')");
        DB::statement('CREATE UNIQUE INDEX specialties_name_normalized_unique ON specialties (lower(name))');
        DB::statement('CREATE UNIQUE INDEX users_email_normalized_unique ON users (lower(email))');
    }

    /** Describe la reversión de esta migración; se utiliza únicamente al solicitar un rollback. */
    public function down(): void
    {
        Schema::dropIfExists('appointment_events');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('unavailabilities');
        Schema::dropIfExists('schedules');
        Schema::dropIfExists('patients');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_doctor_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');
        DB::statement('DROP INDEX IF EXISTS users_email_normalized_unique');
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('doctor_id');
            $t->dropColumn(['role', 'status']);
        });
        Schema::dropIfExists('doctor_specialty');
        Schema::dropIfExists('doctors');
        Schema::dropIfExists('specialties');
    }
};
