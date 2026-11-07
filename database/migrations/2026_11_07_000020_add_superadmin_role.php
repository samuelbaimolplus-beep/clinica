<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('Superadmin', 'Administrador', 'Recepcionista', 'Médico', 'Paciente'))");
    }

    public function down(): void
    {
        if (DB::table('users')->where('role', 'Superadmin')->exists()) {
            throw new RuntimeException('No se puede retirar el rol mientras existan cuentas Superadmin.');
        }
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('Administrador', 'Recepcionista', 'Médico', 'Paciente'))");
    }
};
