<?php

/** Normaliza nombres de especialidad para comparar duplicados sin diferencias de espacios o mayúsculas. */

namespace App\Support;

final class SpecialtyName
{
    /** Devuelve el nombre legible utilizado para presentar el valor. */
    public static function display(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    /** Obtiene la clave normalizada con la que se detectan especialidades duplicadas. */
    public static function key(string $name): string
    {
        return mb_strtolower(self::display($name));
    }
}
