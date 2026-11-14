<?php

/** Entidad de retenciones temporales de reserva. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitudReserva extends Model
{
    protected $table = 'booking_requests';

    protected $guarded = ['id'];

    /** Declara conversiones entre los tipos almacenados y los tipos que utiliza el modelo. */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'deadline_at' => 'immutable_datetime'];
    }

    /** Consulta pagos y exenciones para supervisión, sin revelar credenciales del proveedor. */
    public function payments()
    {
        return $this->hasMany(Pago::class, 'booking_request_id');
    }

    /** Relaciona este registro con fichas de pacientes mediante las claves del modelo. */
    public function patient()
    {
        return $this->belongsTo(Paciente::class);
    }

    /** Relaciona este registro con profesionales médicos mediante las claves del modelo. */
    public function doctor()
    {
        return $this->belongsTo(Medico::class);
    }

    /** Relaciona este registro con especialidades y tarifas mediante las claves del modelo. */
    public function specialty()
    {
        return $this->belongsTo(Especialidad::class);
    }

    /** Relaciona este registro con citas médicas mediante las claves del modelo. */
    public function appointment()
    {
        return $this->hasOne(Cita::class, 'booking_request_id');
    }
}
