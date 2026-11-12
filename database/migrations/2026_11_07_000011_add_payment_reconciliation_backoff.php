<?php

/** Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Aplica el cambio de esquema correspondiente a esta migración. */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->unsignedInteger('poll_count')->default(0);
            $t->timestampTz('next_check_at')->nullable();
        });
    }

    /** Describe la reversión de esta migración; se utiliza únicamente al solicitar un rollback. */
    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn(['poll_count', 'next_check_at']));
    }
};
