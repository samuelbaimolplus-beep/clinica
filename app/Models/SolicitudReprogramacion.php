<?php

/** Entidad de solicitudes de cambio. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitudReprogramacion extends Model
{
    protected $table = 'reschedule_requests';

    protected $guarded = ['id'];

    /** Relaciona este registro con citas médicas mediante las claves del modelo. */
    public function appointment()
    {
        return $this->belongsTo(Cita::class);
    }
}
