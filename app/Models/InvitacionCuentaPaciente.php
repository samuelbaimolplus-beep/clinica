<?php

/** Entidad de vinculaciones administrativas heredadas. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvitacionCuentaPaciente extends Model
{
    protected $table = 'patient_account_invitations';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    /** Declara conversiones entre los tipos almacenados y los tipos que utiliza el modelo. */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }
}
