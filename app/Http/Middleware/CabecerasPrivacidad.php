<?php

/** Evita caché de información privada y añade cabeceras de protección también a respuestas de error. */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CabecerasPrivacidad
{
    /** Ejecuta la responsabilidad de este middleware, comando o trabajo; sus controles se explican en el encabezado de la clase. */
    public function handle(Request $peticion, Closure $siguiente)
    {
        return self::aplicar($peticion, $siguiente($peticion));
    }

    /** Aplica las cabeceras de privacidad a una respuesta normal o de error. */
    public static function aplicar(Request $peticion, Response $respuesta): Response
    {
        $respuesta->headers->set('X-Content-Type-Options', 'nosniff');
        $respuesta->headers->set('X-Frame-Options', 'DENY');
        $respuesta->headers->set('Referrer-Policy', 'same-origin');
        if ($peticion->is('api/*') || $peticion->is('/')) {
            $respuesta->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $respuesta->headers->set('Pragma', 'no-cache');
        }

        return $respuesta;
    }
}
