<?php

/** Entrada HTTP de RequireAppointments. Coordina la solicitud y delega las reglas del dominio en sus servicios. */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ExigirModuloCitas
{
    /** Ejecuta la responsabilidad de este middleware, comando o trabajo; sus controles se explican en el encabezado de la clase. */
    public function handle(Request $peticion, Closure $siguiente)
    {
        abort_unless(config('clinic.appointments_enabled'), 404, 'El módulo de citas está deshabilitado en la configuración del servidor.');

        return $siguiente($peticion);
    }
}
