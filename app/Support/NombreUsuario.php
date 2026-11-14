<?php

/** Normalización, generación y unicidad de identificadores de acceso independientes del correo. */

namespace App\Support;

use Illuminate\Support\Str;

final class NombreUsuario
{
    public const PATTERN = '/^[a-z0-9][a-z0-9._-]{2,49}$/D';

    /** Construye la consulta o configuración base que comparte este componente. */
    public static function base(string $correo): string
    {
        $base = ltrim(preg_replace('/[^a-z0-9._-]+/', '_', strtolower(Str::ascii(Str::before($correo, '@')))), '._-');

        return strlen($base) < 3 ? 'usuario' : substr($base, 0, 40);
    }

    /** Genera un identificador de acceso compatible cuando el alta no lo proporciona. */
    public static function generar(string $correo): string
    {
        return self::base($correo).'_'.strtolower(Str::random(8));
    }
}
