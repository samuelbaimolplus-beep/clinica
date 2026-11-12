<?php

/** Entrada HTTP de ClinicSession. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Http\Middleware;

use App\Paquetes\P01_UsuariosYAcceso\Compartido\Servicios\SessionSecurity;
use Closure;
use Illuminate\Http\Request;

class ClinicSession
{
    /** Ejecuta la responsabilidad de este middleware, comando o trabajo; sus controles se explican en el encabezado de la clase. */
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()) {
            return response()->json(['message' => 'Debe iniciar sesión.', 'csrf' => csrf_token()], 401);
        }
        if ($message = app(SessionSecurity::class)->validate($request)) {
            return response()->json(['message' => $message, 'csrf' => csrf_token()], 401);
        }

        return $next($request);
    }
}
