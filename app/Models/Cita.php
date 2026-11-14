<?php

/** Entidad de citas médicas. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cita extends Model
{
    protected $table = 'appointments';

    public const ACTIVE = ['Programada', 'Confirmada'];

    public const STATES = ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'];

    protected $guarded = ['id'];

    /** Declara conversiones entre los tipos almacenados y los tipos que utiliza el modelo. */
    protected function casts(): array
    {
        return ['complimentary' => 'boolean'];
    }

    /** Relaciona este registro con pagos y confirmaciones mediante las claves del modelo. */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Pago::class);
    }

    /** Relaciona este registro con fichas de pacientes mediante las claves del modelo. */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Paciente::class);
    }

    /** Relaciona este registro con profesionales médicos mediante las claves del modelo. */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Medico::class);
    }

    /** Relaciona este registro con especialidades y tarifas mediante las claves del modelo. */
    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Especialidad::class);
    }

    /** Relaciona este registro con cambios de una cita mediante las claves del modelo. */
    public function events(): HasMany
    {
        return $this->hasMany(EventoCita::class, 'appointment_id')->orderBy('id');
    }
}
