<?php

/** Entidad de ausencias médicas. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Unavailability extends Model
{
    protected $guarded = ['id'];

    /** Las ausencias retiradas se conservan para consulta, sin bloquear disponibilidad. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }
}
