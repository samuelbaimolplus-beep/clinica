<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // RN01: evita también la carrera entre dos altas idénticas sin documento.
        DB::statement('CREATE UNIQUE INDEX patients_without_identity_unique ON patients
            (lower(trim(first_name)), lower(trim(last_name)), birth_date, phone) WHERE identity IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS patients_without_identity_unique');
    }
};
