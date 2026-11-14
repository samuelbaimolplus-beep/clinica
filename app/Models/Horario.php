<?php

/** Entidad de turnos semanales. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Horario extends Model
{
    protected $table = 'schedules';

    protected $guarded = ['id'];
}
