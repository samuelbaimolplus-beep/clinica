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
        Schema::create('doctor_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('presential_percent', 5, 2);
            $table->decimal('online_percent', 5, 2);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE doctor_quotas ADD CONSTRAINT doctor_quotas_percent_check
            CHECK (presential_percent BETWEEN 0 AND 100 AND online_percent BETWEEN 0 AND 100
                AND presential_percent + online_percent = 100)');
    }

    /** Describe la reversión de esta migración; se utiliza únicamente al solicitar un rollback. */
    public function down(): void
    {
        if (DB::table('doctor_quotas')->exists()) {
            throw new RuntimeException('Existen distribuciones de cupos; no se puede revertir sin perder configuración.');
        }
        Schema::drop('doctor_quotas');
    }
};
