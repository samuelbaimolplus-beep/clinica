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
        Schema::create('patient_account_invitations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('patient_id')->constrained()->restrictOnDelete();
            $t->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->timestampTz('expires_at');
            $t->timestampTz('consumed_at')->nullable();
            $t->timestamps();
        });
    }

    /** Describe la reversión de esta migración; se utiliza únicamente al solicitar un rollback. */
    public function down(): void
    {
        Schema::dropIfExists('patient_account_invitations');
    }
};
