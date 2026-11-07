<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE schedules DROP CONSTRAINT schedules_range_check');
        DB::statement('ALTER TABLE schedules ADD CONSTRAINT schedules_range_check CHECK (weekday BETWEEN 0 AND 6 AND start_time < end_time AND duration > 0)');
        Schema::table('agenda_adjustments', function (Blueprint $table) {
            $table->uuid('demand_batch')->nullable()->index();
            $table->unsignedInteger('demand_quantity')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('La reversión requiere revisar duraciones e historial de demanda sin pérdida de datos.');
    }
};
