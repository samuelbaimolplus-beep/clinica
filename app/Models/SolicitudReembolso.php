<?php

/** Entidad de solicitudes de devolución. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitudReembolso extends Model
{
    protected $table = 'refund_requests';

    protected $guarded = ['id'];

    /** Relaciona este registro con citas médicas mediante las claves del modelo. */
    public function appointment()
    {
        return $this->belongsTo(Cita::class);
    }

    /** Relaciona este registro con pagos y confirmaciones mediante las claves del modelo. */
    public function payment()
    {
        return $this->belongsTo(Pago::class);
    }
}
