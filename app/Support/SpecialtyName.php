<?php

namespace App\Support;

final class SpecialtyName
{
    public static function display(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    public static function key(string $name): string
    {
        return mb_strtolower(self::display($name));
    }
}
