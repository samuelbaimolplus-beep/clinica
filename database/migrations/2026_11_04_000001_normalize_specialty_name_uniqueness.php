<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // No fusionar ni borrar especialidades históricas si la nueva normalización encuentra conflictos.
        $duplicates = DB::select("SELECT lower(trim(regexp_replace(name, '[[:space:]]+', ' ', 'g'))) AS name
            FROM specialties GROUP BY 1 HAVING count(*) > 1");
        if ($duplicates) {
            throw new RuntimeException('Existen especialidades con nombres equivalentes al normalizar espacios. Corrija los registros antes de migrar.');
        }
        DB::statement("CREATE UNIQUE INDEX specialties_name_spaces_unique ON specialties
            (lower(trim(regexp_replace(name, '[[:space:]]+', ' ', 'g'))))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS specialties_name_spaces_unique');
    }
};
