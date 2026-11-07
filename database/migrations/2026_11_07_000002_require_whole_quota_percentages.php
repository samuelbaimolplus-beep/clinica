<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // No redondear silenciosamente configuraciones anteriores. NOT VALID conserva esas filas
        // y exige enteros a toda inserción o actualización posterior.
        DB::statement('ALTER TABLE doctor_quotas ADD CONSTRAINT doctor_quotas_whole_percentages CHECK
            (presential_percent = trunc(presential_percent) AND online_percent = trunc(online_percent)) NOT VALID');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE doctor_quotas DROP CONSTRAINT doctor_quotas_whole_percentages');
    }
};
