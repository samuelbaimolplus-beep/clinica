<?php

/** Entidad de identidades de franjas clínicas. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicalSlot extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /** Obtiene o crea la identidad única de médico, día y franja clínica. */
    public static function identify(int $doctor, string $date, string $start, string $end): int
    {
        return static::firstOrCreate(['doctor_id' => $doctor, 'date' => $date,
            'start_time' => strlen($start) === 5 ? $start.':00' : $start,
            'end_time' => strlen($end) === 5 ? $end.':00' : $end])->id;
    }
}
