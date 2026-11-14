<?php

/** Entidad de eventos inmutables de auditoría. Sus relaciones usan las claves foráneas; la conexión adapta las columnas al esquema español. */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EventoAuditoria extends Model
{
    protected $table = 'audit_events';

    public $timestamps = false;

    protected $guarded = ['id'];

    /** Declara conversiones entre los tipos almacenados y los tipos que utiliza el modelo. */
    protected function casts(): array
    {
        return ['details' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    /** Configura eventos del modelo que deben aplicarse también fuera de los formularios. */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La bitácora es inmutable.'));
        static::deleting(fn () => throw new LogicException('La bitácora es inmutable.'));
    }
}
