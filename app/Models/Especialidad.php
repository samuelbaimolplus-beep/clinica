<?php

/** Entidad de especialidades y tarifas. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Especialidad extends Model
{
    protected $table = 'specialties';

    protected $guarded = ['id'];
}
