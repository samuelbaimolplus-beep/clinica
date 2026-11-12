<?php

/** Entidad de ausencias médicas. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unavailability extends Model
{
    protected $guarded = ['id'];
}
