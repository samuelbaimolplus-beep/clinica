<?php

/** Entidad de identidades de franjas clínicas. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranjaClinica extends Model
{
    protected $table = 'clinical_slots';

    public $timestamps = false;

    protected $guarded = ['id'];

    /** Obtiene o crea la identidad única de médico, día y franja clínica. */
    public static function identificar(int $medico, string $fecha, string $inicio, string $fin): int
    {
        return static::firstOrCreate(['doctor_id' => $medico, 'date' => $fecha,
            'start_time' => strlen($inicio) === 5 ? $inicio.':00' : $inicio,
            'end_time' => strlen($fin) === 5 ? $fin.':00' : $fin])->id;
    }
}
