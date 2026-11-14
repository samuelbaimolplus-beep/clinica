<?php

/** Entidad de bloqueos y aperturas de cupos. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AjusteAgenda extends Model
{
    protected $table = 'agenda_adjustments';

    protected $guarded = ['id'];

    /** Declara conversiones entre los tipos almacenados y los tipos que utiliza el modelo. */
    protected function casts(): array
    {
        return ['released_at' => 'immutable_datetime'];
    }
}
