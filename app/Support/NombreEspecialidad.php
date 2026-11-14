<?php

/** Normaliza nombres de especialidad para comparar duplicados sin diferencias de espacios o mayúsculas. */

namespace App\Support;

final class NombreEspecialidad
{
    /** Devuelve el nombre legible utilizado para presentar el valor. */
    public static function presentar(string $nombre): string
    {
        return trim(preg_replace('/\s+/u', ' ', $nombre));
    }

    /** Obtiene la clave normalizada con la que se detectan especialidades duplicadas. */
    public static function clave(string $nombre): string
    {
        return mb_strtolower(self::presentar($nombre));
    }
}
