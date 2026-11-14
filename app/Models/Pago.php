<?php

/** Entidad de pagos y confirmaciones. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pago extends Model
{
    protected $table = 'payments';

    protected $guarded = ['id'];

    protected $hidden = ['qr_image'];

    /** Relaciona este registro con retenciones temporales de reserva mediante las claves del modelo. */
    public function bookingRequest(): BelongsTo
    {
        return $this->belongsTo(SolicitudReserva::class);
    }

    /** Relaciona este registro con citas médicas mediante las claves del modelo. */
    public function appointments(): HasMany
    {
        return $this->hasMany(Cita::class, 'payment_id');
    }

    /** Declara conversiones entre los tipos almacenados y los tipos que utiliza el modelo. */
    protected function casts(): array
    {
        return ['confirmed_at' => 'immutable_datetime', 'next_check_at' => 'immutable_datetime'];
    }
}
