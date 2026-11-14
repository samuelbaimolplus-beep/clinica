<?php

/** Entrada HTTP de ClinicSession. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Http\Middleware;

use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\SeguridadSesion;
use Closure;
use Illuminate\Http\Request;

class SesionClinica
{
    /** Ejecuta la responsabilidad de este middleware, comando o trabajo; sus controles se explican en el encabezado de la clase. */
    public function handle(Request $peticion, Closure $siguiente)
    {
        if (! $peticion->user()) {
            return response()->json(['message' => 'Debe iniciar sesión.', 'csrf' => csrf_token()], 401);
        }
        if ($mensaje = app(SeguridadSesion::class)->validarSesion($peticion)) {
            return response()->json(['message' => $mensaje, 'csrf' => csrf_token()], 401);
        }

        return $siguiente($peticion);
    }
}
