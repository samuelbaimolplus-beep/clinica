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
        Schema::table('patients', fn (Blueprint $t) => $t->string('identity', 40)->nullable()->change());
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('patient_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->timestampTz('terms_accepted_at')->nullable();
            $t->string('terms_version', 30)->nullable();
        });
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('Administrador', 'Recepcionista', 'Médico', 'Paciente'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_patient_check CHECK ((role = 'Paciente' AND patient_id IS NOT NULL) OR (role <> 'Paciente' AND patient_id IS NULL))");
        DB::statement('CREATE UNIQUE INDEX patients_identity_normalized_unique ON patients (upper(trim(identity))) WHERE identity IS NOT NULL');

        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->timestampTz('occurred_at')->useCurrent();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('module', 40);
            $t->string('action', 40);
            $t->string('record_type', 80)->nullable();
            $t->unsignedBigInteger('record_id')->nullable();
            $t->string('result', 20)->default('success');
            $t->jsonb('details');
            $t->index(['module', 'occurred_at']);
            $t->index(['user_id', 'occurred_at']);
        });
        // RN61: tampoco SQL accidental desde la aplicación puede alterar la bitácora.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION reject_audit_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'La bitácora de auditoría es inmutable';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER audit_events_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON audit_events
            FOR EACH STATEMENT EXECUTE FUNCTION reject_audit_mutation();
            SQL);
    }

    /** Describe la reversión de esta migración; se utiliza únicamente al solicitar un rollback. */
    public function down(): void
    {
        // Evitar pérdida de cuentas: el rollback requiere resolverlas explícitamente.
        if (DB::table('users')->where('role', 'Paciente')->exists()) {
            throw new RuntimeException('Existen cuentas de pacientes; no se puede revertir esta migración.');
        }
        Schema::dropIfExists('audit_events');
        DB::statement('DROP FUNCTION IF EXISTS reject_audit_mutation()');
        DB::statement('DROP INDEX IF EXISTS patients_identity_normalized_unique');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_patient_check');
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('patient_id');
            $t->dropColumn(['terms_accepted_at', 'terms_version']);
        });
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('Administrador', 'Recepcionista', 'Médico'))");
        // identity conserva nulabilidad para no destruir pacientes sin documento.
    }
};
