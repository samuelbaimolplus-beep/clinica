<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE public.indisponibilidades RENAME CONSTRAINT unavailabilities_cancelled_by_foreign TO indisponibilidades_cancelado_por_foranea');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE public.indisponibilidades RENAME CONSTRAINT indisponibilidades_cancelado_por_foranea TO unavailabilities_cancelled_by_foreign');
    }
};
