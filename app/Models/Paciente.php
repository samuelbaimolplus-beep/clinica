<?php

/** Entidad de fichas de pacientes. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Paciente extends Model
{
    protected $table = 'patients';

    protected $guarded = ['id'];

    /** Relaciona este registro con cuentas de usuario mediante las claves del modelo. */
    public function account(): HasOne
    {
        return $this->hasOne(Usuario::class, 'patient_id');
    }
}
