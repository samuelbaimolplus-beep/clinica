<?php

/** Entidad de profesionales médicos. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Doctor extends Model
{
    protected $guarded = ['id'];

    /** Define la relación de esta entidad con sus registros asociados. */
    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class);
    }
}
