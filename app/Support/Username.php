<?php

namespace App\Support;

use Illuminate\Support\Str;

final class Username
{
    public const PATTERN = '/^[a-z0-9][a-z0-9._-]{2,49}$/D';

    public static function base(string $email): string
    {
        $base = ltrim(preg_replace('/[^a-z0-9._-]+/', '_', strtolower(Str::ascii(Str::before($email, '@')))), '._-');

        return strlen($base) < 3 ? 'usuario' : substr($base, 0, 40);
    }

    public static function generated(string $email): string
    {
        return self::base($email).'_'.strtolower(Str::random(8));
    }
}
