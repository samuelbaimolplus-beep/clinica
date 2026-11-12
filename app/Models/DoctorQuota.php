<?php

/** Entidad de cupos por modalidad. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DoctorQuota extends Model
{
    protected $guarded = ['id'];

    /** Declara conversiones entre los tipos almacenados y los tipos que utiliza el modelo. */
    protected function casts(): array
    {
        return ['presential_percent' => 'decimal:2', 'online_percent' => 'decimal:2'];
    }
}
