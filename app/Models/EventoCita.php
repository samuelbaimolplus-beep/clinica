<?php

/** Entidad de cambios de una cita. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventoCita extends Model
{
    protected $table = 'appointment_events';

    public $timestamps = false;

    protected $guarded = ['id'];

    /** Declara conversiones entre los tipos almacenados y los tipos que utiliza el modelo. */
    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
