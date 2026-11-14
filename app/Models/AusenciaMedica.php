<?php

/** Entidad de ausencias médicas. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AusenciaMedica extends Model
{
    protected $table = 'unavailabilities';

    protected $guarded = ['id'];

    /** Las ausencias retiradas se conservan para consulta, sin bloquear disponibilidad. */
    public function scopeActive(Builder $consulta): Builder
    {
        return $consulta->whereNull('cancelled_at');
    }
}
